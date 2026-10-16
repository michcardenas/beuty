<?php

namespace App\Services\Siigo;

use App\Models\ConfiguracionPdv;
use App\Models\SiigoLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class SiigoApiClient
{
    private const BASE_URL = 'https://api.siigo.com';
    private const TOKEN_CACHE_KEY = 'siigo_access_token';
    private const TOKEN_TTL = 39600; // 11 hours (safety margin under 12h)

    private ?string $username;
    private ?string $accessKey;
    private ?string $partnerId;
    private string $modo;

    public function __construct()
    {
        $this->modo = ConfiguracionPdv::obtener('siigo_modo', 'test');

        if ($this->modo === 'test') {
            $this->username = ConfiguracionPdv::obtener('siigo_username_test');
            $this->accessKey = ConfiguracionPdv::obtener('siigo_access_key_test');
        } else {
            $this->username = ConfiguracionPdv::obtener('siigo_username');
            $this->accessKey = ConfiguracionPdv::obtener('siigo_access_key');
        }

        $this->partnerId = ConfiguracionPdv::obtener('siigo_partner_id', 'MiraclePdV');
    }

    public function esModoTest(): bool
    {
        return $this->modo === 'test';
    }

    public function estaConfigurado(): bool
    {
        return !empty($this->username) && !empty($this->accessKey);
    }

    public function getToken(bool $forceRefresh = false): string
    {
        if (!$forceRefresh) {
            $cached = Cache::get(self::TOKEN_CACHE_KEY);
            if ($cached) {
                return $cached;
            }
        }

        if (!$this->estaConfigurado()) {
            throw new Exception('Las credenciales de SIIGO no están configuradas.');
        }

        $start = microtime(true);
        $response = Http::acceptJson()
            ->timeout(30)
            ->post(self::BASE_URL . '/auth', [
                'username' => $this->username,
                'access_key' => $this->accessKey,
            ]);

        $duracion = (int) ((microtime(true) - $start) * 1000);

        $this->log('/auth', 'POST', [
            'username' => $this->username,
        ], $response->status(), $response->json(), $duracion, $response->successful());

        if (!$response->successful()) {
            $error = $response->json('Errors.0.Message')
                ?? $response->json('errors.0.message')
                ?? $response->json('Message')
                ?? 'Error de autenticación con SIIGO';
            throw new Exception("SIIGO Auth Error: {$error}");
        }

        $token = $response->json('access_token');
        if (!$token) {
            throw new Exception('SIIGO no retornó un token de acceso.');
        }

        Cache::put(self::TOKEN_CACHE_KEY, $token, self::TOKEN_TTL);

        return $token;
    }

    public function get(string $endpoint, array $query = [], ?int $facturaSiigoId = null): array
    {
        return $this->request('GET', $endpoint, $query, null, $facturaSiigoId);
    }

    /**
     * @param array $headers encabezados extra, p. ej. Idempotency-Key: si un reintento
     *                       por timeout llega a SIIGO dos veces, no crea dos facturas.
     */
    public function post(string $endpoint, array $data = [], ?int $facturaSiigoId = null, array $headers = []): array
    {
        return $this->request('POST', $endpoint, [], $data, $facturaSiigoId, false, $headers);
    }

    public function put(string $endpoint, array $data = [], ?int $facturaSiigoId = null): array
    {
        return $this->request('PUT', $endpoint, [], $data, $facturaSiigoId);
    }

    public function delete(string $endpoint, ?int $facturaSiigoId = null): array
    {
        return $this->request('DELETE', $endpoint, [], null, $facturaSiigoId);
    }

    /**
     * GET request that returns raw response (for PDF downloads, etc.)
     */
    public function getRaw(string $endpoint, ?int $facturaSiigoId = null)
    {
        $token = $this->getToken();
        $start = microtime(true);

        $response = Http::withToken($token)
            ->withHeaders(['Partner-Id' => $this->partnerId])
            ->timeout(30)
            ->get(self::BASE_URL . $endpoint);

        $duracion = (int) ((microtime(true) - $start) * 1000);

        $this->log($endpoint, 'GET', null, $response->status(), null, $duracion, $response->successful(), $facturaSiigoId);

        if ($response->status() === 401) {
            $token = $this->getToken(true);
            $response = Http::withToken($token)
                ->withHeaders(['Partner-Id' => $this->partnerId])
                ->timeout(30)
                ->get(self::BASE_URL . $endpoint);
        }

        return $response;
    }

    private function request(string $method, string $endpoint, array $query = [], ?array $data = null, ?int $facturaSiigoId = null, bool $isRetry = false, array $headers = []): array
    {
        // Reintenta hasta 3 veces en fallos transitorios (red caída, SIIGO 5xx, 429).
        // Backoff: 1s, 3s. Los errores 4xx (validación) NO se reintentan.
        $maxIntentos = 3;
        $intento = 0;

        while (true) {
            $intento++;
            $token = $this->getToken();
            $start = microtime(true);

            $http = Http::withToken($token)
                ->withHeaders(['Partner-Id' => $this->partnerId] + $headers)
                ->acceptJson()
                ->timeout(30);

            $url = self::BASE_URL . $endpoint;
            $connectionError = null;
            $response = null;

            try {
                $response = match ($method) {
                    'GET' => $http->get($url, $query),
                    'POST' => $http->post($url, $data ?? []),
                    'PUT' => $http->put($url, $data ?? []),
                    'DELETE' => $http->delete($url),
                };
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                $connectionError = $e;
            }

            $duracion = (int) ((microtime(true) - $start) * 1000);

            if ($connectionError) {
                $errorMsg = 'Connection timeout (intento ' . $intento . '/' . $maxIntentos . '): ' . $connectionError->getMessage();
                $this->log($endpoint, $method, $data ?? $query, null, null, $duracion, false, $facturaSiigoId, $errorMsg);

                if ($intento < $maxIntentos) {
                    sleep($intento);
                    continue;
                }
                throw new Exception('No se pudo conectar con SIIGO tras ' . $maxIntentos . ' intentos. Verifique la conectividad hacia api.siigo.com.');
            }

            $this->log(
                $endpoint,
                $method,
                $data ?? ($query ?: null),
                $response->status(),
                $response->json(),
                $duracion,
                $response->successful(),
                $facturaSiigoId,
                $response->successful() ? null : $this->extractError($response->json())
            );

            // On 401, refresh token and retry once (no cuenta contra $maxIntentos)
            if ($response->status() === 401 && !$isRetry) {
                Cache::forget(self::TOKEN_CACHE_KEY);
                return $this->request($method, $endpoint, $query, $data, $facturaSiigoId, true, $headers);
            }

            // Reintentar en 5xx (SIIGO caído) o 429 (rate limit). 4xx = validación, NO reintentar.
            if (in_array($response->status(), [500, 502, 503, 504, 429]) && $intento < $maxIntentos) {
                sleep($intento);
                continue;
            }

            if (!$response->successful()) {
                $error = $this->extractError($response->json());
                throw new Exception("SIIGO API Error ({$response->status()}): {$error}");
            }

            return $response->json() ?? [];
        }
    }

    private function extractError(?array $body): string
    {
        if (!$body) return 'Respuesta vacía';

        // SIIGO returns errors in different formats
        if (isset($body['Errors'])) {
            return collect($body['Errors'])->pluck('Message')->implode('; ');
        }
        if (isset($body['errors'])) {
            return collect($body['errors'])->map(function ($e) {
                return is_array($e) ? ($e['message'] ?? json_encode($e)) : $e;
            })->implode('; ');
        }
        if (isset($body['Message'])) {
            return $body['Message'];
        }

        return json_encode($body);
    }

    private function log(
        string $endpoint,
        string $method,
        ?array $requestBody,
        ?int $responseCode,
        ?array $responseBody,
        int $duracionMs,
        bool $exitoso,
        ?int $facturaSiigoId = null,
        ?string $errorMensaje = null
    ): void {
        try {
            SiigoLog::create([
                'factura_siigo_id' => $facturaSiigoId,
                'endpoint' => $endpoint,
                'method' => $method,
                'request_body' => $requestBody,
                'response_code' => $responseCode,
                'response_body' => $responseBody,
                'duracion_ms' => $duracionMs,
                'exitoso' => $exitoso,
                'error_mensaje' => $errorMensaje,
                'usuario_id' => auth()->id(),
            ]);
        } catch (Exception $e) {
            Log::error('Error logging SIIGO API call: ' . $e->getMessage());
        }
    }

    public static function limpiarTokenCache(): void
    {
        Cache::forget(self::TOKEN_CACHE_KEY);
    }
}
