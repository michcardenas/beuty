<?php

namespace App\Http\Controllers;

use App\Models\FacturaSiigo;
use App\Models\SolicitudCotizacion;
use App\Services\Siigo\SiigoCotizacionService;
use App\Services\Siigo\SiigoFacturacionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Factura electrónica de cotizaciones: revisión de datos, envío a la DIAN
 * (uno o varios), estado y descarga del PDF / XML.
 *
 * Enviar es solo para admin y auxiliar administrativo
 * (User::puedeFacturarCotizaciones). Ver el estado y descargar, los roles de la ruta.
 */
class CotizacionFacturaSiigoController extends Controller
{
    /** Tope del envío por lote: cada factura tarda varios segundos en SIIGO. */
    private const MAX_POR_LOTE = 20;

    public function __construct(private SiigoCotizacionService $servicio)
    {
    }

    public function revision(SolicitudCotizacion $solicitud)
    {
        $this->exigirPermiso();

        return response()->json(['success' => true] + $this->servicio->revisar($solicitud));
    }

    public function enviar(Request $request, SolicitudCotizacion $solicitud)
    {
        $this->exigirPermiso();

        // Nota opcional de facturación: va en las observaciones de la factura,
        // debajo de las del vendedor.
        $nota = $request->validate(['nota_facturacion' => 'nullable|string|max:1500'])['nota_facturacion'] ?? null;

        return response()->json($this->resultado($solicitud, $nota));
    }

    /**
     * Envío selectivo: las cotizaciones marcadas en el listado, una por una.
     * Las que no pasan la revisión no se envían y se informa el motivo.
     */
    public function enviarLote(Request $request)
    {
        $this->exigirPermiso();

        $ids = $request->validate([
            'ids' => 'required|array|min:1|max:' . self::MAX_POR_LOTE,
            'ids.*' => 'integer',
        ])['ids'];

        $resultados = SolicitudCotizacion::whereIn('id', $ids)->get()
            ->map(fn ($solicitud) => ['numero' => $solicitud->numero_solicitud] + $this->resultado($solicitud));

        return response()->json([
            'success' => true,
            'enviadas' => $resultados->where('success', true)->count(),
            'resultados' => $resultados->values(),
        ]);
    }

    public function estado(SolicitudCotizacion $solicitud)
    {
        $factura = $this->factura($solicitud);
        $resultado = app(SiigoFacturacionService::class)->consultarEstado($factura);

        return response()->json(['success' => true] + $resultado + ['estado_badge' => $factura->fresh()->estado_badge]);
    }

    public function pdf(SolicitudCotizacion $solicitud)
    {
        $factura = $this->factura($solicitud);

        return $this->descargar(
            fn () => app(SiigoFacturacionService::class)->obtenerPdf($factura),
            "factura-{$factura->numero_factura}.pdf",
            'application/pdf'
        );
    }

    public function xml(SolicitudCotizacion $solicitud)
    {
        $factura = $this->factura($solicitud);

        return $this->descargar(
            fn () => app(SiigoFacturacionService::class)->obtenerXml($factura),
            "factura-{$factura->numero_factura}.xml",
            'application/xml'
        );
    }

    private function resultado(SolicitudCotizacion $solicitud, ?string $notaFacturacion = null): array
    {
        try {
            $factura = $this->servicio->emitir($solicitud, $notaFacturacion);
        } catch (\Exception $e) {
            return ['success' => false, 'mensaje' => $e->getMessage()];
        }

        if ($factura->estado_dian === 'error') {
            return ['success' => false, 'mensaje' => 'SIIGO no aceptó la factura: ' . $factura->errores, 'estado_badge' => $factura->estado_badge];
        }

        return [
            'success' => true,
            'mensaje' => $factura->estado_dian === 'aprobada'
                ? "Factura {$factura->numero_factura} aprobada por la DIAN."
                : "Factura {$factura->numero_factura} enviada; la DIAN aún la está validando.",
            'numero_factura' => $factura->numero_factura,
            'estado_badge' => $factura->estado_badge,
        ];
    }

    /**
     * SIIGO entrega el documento en base64 dentro de un JSON (o, a veces, el
     * archivo directo).
     */
    private function descargar(callable $pedir, string $nombre, string $tipo)
    {
        try {
            $respuesta = $pedir();
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'mensaje' => $e->getMessage()], 502);
        }

        if (!$respuesta->successful()) {
            Log::warning("SIIGO descarga {$nombre}: HTTP {$respuesta->status()}");

            return response()->json(['success' => false, 'mensaje' => 'SIIGO no entregó el documento (HTTP ' . $respuesta->status() . ').'], 502);
        }

        $base64 = $respuesta->json('base64');
        $contenido = $base64 ? base64_decode($base64) : $respuesta->body();

        return response($contenido, 200)
            ->header('Content-Type', $tipo)
            ->header('Content-Disposition', "attachment; filename=\"{$nombre}\"");
    }

    private function factura(SolicitudCotizacion $solicitud): FacturaSiigo
    {
        $factura = $solicitud->facturaSiigo;
        abort_unless($factura && $factura->siigo_invoice_id, 404, 'La cotización no tiene factura electrónica.');

        return $factura;
    }

    private function exigirPermiso(): void
    {
        abort_unless(auth()->user()->puedeFacturarCotizaciones(), 403, 'No tiene permiso para enviar cotizaciones a la DIAN.');
    }
}
