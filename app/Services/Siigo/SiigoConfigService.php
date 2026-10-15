<?php

namespace App\Services\Siigo;

use App\Models\ConfiguracionPdv;
use App\Models\SiigoProductoCache;
use Illuminate\Support\Facades\DB;
use Exception;

class SiigoConfigService
{
    private SiigoApiClient $api;

    public function __construct(SiigoApiClient $api)
    {
        $this->api = $api;
    }

    public function testConexion(): array
    {
        try {
            $this->api->getToken(true);
            return ['exito' => true, 'mensaje' => 'Conexión exitosa con SIIGO.'];
        } catch (Exception $e) {
            return ['exito' => false, 'mensaje' => $e->getMessage()];
        }
    }

    public function obtenerDocumentTypes(): array
    {
        try {
            $response = $this->api->get('/v1/document-types', ['type' => 'FV']);
            return $response;
        } catch (Exception $e) {
            return [];
        }
    }

    public function obtenerCreditNoteTypes(): array
    {
        try {
            $response = $this->api->get('/v1/document-types', ['type' => 'NC']);
            return $response;
        } catch (Exception $e) {
            return [];
        }
    }

    public function obtenerPaymentTypes(): array
    {
        try {
            $response = $this->api->get('/v1/payment-types', ['document_type' => 'FV']);
            return $response;
        } catch (Exception $e) {
            return [];
        }
    }

    public function obtenerTaxes(): array
    {
        try {
            return $this->api->get('/v1/taxes');
        } catch (Exception $e) {
            return [];
        }
    }

    public function obtenerSellers(): array
    {
        try {
            return $this->api->get('/v1/users');
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Sincronizar el catálogo completo de productos de SIIGO con la tabla local
     * `siigo_productos_cache`. Pagina hasta agotar el listado y hace upsert por
     * `siigo_id` o `code`.
     *
     * @return array{insertados:int, actualizados:int, total:int, duracion_ms:int}
     */
    public function sincronizarCatalogoSiigo(): array
    {
        $inicio = microtime(true);
        $totalLeidos = 0;
        $insertados = 0;
        $actualizados = 0;
        $vistos = [];

        $pagina = 1;
        $maxPaginas = 500; // tope: 500 * 100 = 50.000 productos

        do {
            $resp = $this->obtenerProductos([
                'page' => $pagina,
                'page_size' => 100,
                'active' => true,
            ]);
            $batch = $resp['results'] ?? [];
            if (empty($batch)) break;

            foreach ($batch as $p) {
                $code = $p['code'] ?? null;
                if (!$code) continue;

                $datos = [
                    'siigo_id' => $p['id'] ?? null,
                    'code' => substr((string) $code, 0, 50),
                    'name' => $p['name'] ?? '',
                    'reference' => $p['reference'] ?? null,
                    'account_group_name' => $p['account_group']['name'] ?? null,
                    'type' => $p['type'] ?? null,
                    'active' => $p['active'] ?? true,
                    'last_sync_at' => now(),
                ];

                $registro = SiigoProductoCache::where('siigo_id', $datos['siigo_id'])
                    ->orWhere('code', $datos['code'])
                    ->first();

                if ($registro) {
                    $registro->fill($datos)->save();
                    $actualizados++;
                } else {
                    SiigoProductoCache::create($datos);
                    $insertados++;
                }
                $vistos[] = $datos['code'];
                $totalLeidos++;
            }

            $totalDisponible = $resp['total_results'] ?? $totalLeidos;
            if ($totalLeidos >= $totalDisponible) break;
            $pagina++;
        } while ($pagina <= $maxPaginas);

        return [
            'insertados' => $insertados,
            'actualizados' => $actualizados,
            'total' => $totalLeidos,
            'duracion_ms' => (int) ((microtime(true) - $inicio) * 1000),
        ];
    }

    /**
     * Listar productos del catálogo de SIIGO con paginación y filtros opcionales.
     * Filtros admitidos por la API: code, page, page_size, active, type, account_group, ids,
     * created_start, created_end, date_start, date_end, updated_start, updated_end.
     */
    public function obtenerProductos(array $filtros = []): array
    {
        $query = [];

        if (!empty($filtros['code'])) {
            $query['code'] = $filtros['code'];
        }
        if (!empty($filtros['type'])) {
            $query['type'] = $filtros['type'];
        }
        if (array_key_exists('active', $filtros)) {
            $query['active'] = $filtros['active'] ? 'true' : 'false';
        }

        $query['page'] = (int) ($filtros['page'] ?? 1);
        $query['page_size'] = min((int) ($filtros['page_size'] ?? 25), 100);

        $response = $this->api->get('/v1/products', $query);

        $results = $response['results'] ?? (is_array($response) && !isset($response['pagination']) ? $response : []);
        $pagination = $response['pagination'] ?? [];

        return [
            'results' => is_array($results) ? $results : [],
            'page' => $pagination['page'] ?? $query['page'],
            'page_size' => $pagination['page_size'] ?? $query['page_size'],
            'total_results' => $pagination['total_results'] ?? count($results ?? []),
        ];
    }

    public function guardarConfiguracion(array $data): void
    {
        $claves = [
            'siigo_activo', 'siigo_modo', 'siigo_username', 'siigo_access_key',
            'siigo_username_test', 'siigo_access_key_test',
            'siigo_partner_id', 'siigo_document_type_id', 'siigo_credit_note_type_id',
            'siigo_payment_type_efectivo_id', 'siigo_payment_type_transferencia_id',
            'siigo_tax_id', 'siigo_seller_id',
            'siigo_consumidor_final_nit', 'siigo_facturar_siempre', 'siigo_max_reintentos',
            'siigo_payment_type_credito_id', 'siigo_tax_iva5_id', 'siigo_codigo_flete',
        ];

        foreach ($claves as $clave) {
            if (array_key_exists($clave, $data)) {
                $valor = $data[$clave] ?? '';
                ConfiguracionPdv::establecer($clave, (string) $valor);
            }
        }

        // Clear token cache when credentials or mode change
        if (isset($data['siigo_username']) || isset($data['siigo_access_key'])
            || isset($data['siigo_username_test']) || isset($data['siigo_access_key_test'])
            || isset($data['siigo_modo'])) {
            SiigoApiClient::limpiarTokenCache();
        }
    }

    public function obtenerConfiguracionActual(): array
    {
        return [
            'siigo_activo' => ConfiguracionPdv::obtenerBoolean('siigo_activo', false),
            'siigo_modo' => ConfiguracionPdv::obtener('siigo_modo', 'test'),
            'siigo_username' => ConfiguracionPdv::obtener('siigo_username', ''),
            'siigo_access_key' => ConfiguracionPdv::obtener('siigo_access_key', ''),
            'siigo_username_test' => ConfiguracionPdv::obtener('siigo_username_test', ''),
            'siigo_access_key_test' => ConfiguracionPdv::obtener('siigo_access_key_test', ''),
            'siigo_partner_id' => ConfiguracionPdv::obtener('siigo_partner_id', 'MiraclePdV'),
            'siigo_document_type_id' => ConfiguracionPdv::obtener('siigo_document_type_id', ''),
            'siigo_credit_note_type_id' => ConfiguracionPdv::obtener('siigo_credit_note_type_id', ''),
            'siigo_payment_type_efectivo_id' => ConfiguracionPdv::obtener('siigo_payment_type_efectivo_id', ''),
            'siigo_payment_type_transferencia_id' => ConfiguracionPdv::obtener('siigo_payment_type_transferencia_id', ''),
            'siigo_tax_id' => ConfiguracionPdv::obtener('siigo_tax_id', ''),
            'siigo_seller_id' => ConfiguracionPdv::obtener('siigo_seller_id', ''),
            'siigo_consumidor_final_nit' => ConfiguracionPdv::obtener('siigo_consumidor_final_nit', '222222222222'),
            'siigo_facturar_siempre' => ConfiguracionPdv::obtenerBoolean('siigo_facturar_siempre', false),
            'siigo_max_reintentos' => ConfiguracionPdv::obtenerNumero('siigo_max_reintentos', 3),
            // Facturación de cotizaciones
            'siigo_payment_type_credito_id' => ConfiguracionPdv::obtener('siigo_payment_type_credito_id', ''),
            'siigo_tax_iva5_id' => ConfiguracionPdv::obtener('siigo_tax_iva5_id', ''),
            'siigo_codigo_flete' => ConfiguracionPdv::obtener('siigo_codigo_flete', ''),
        ];
    }
}
