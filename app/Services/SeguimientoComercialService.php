<?php

namespace App\Services;

use App\Models\SolicitudCotizacion;
use Carbon\Carbon;

/**
 * Servicio de Seguimiento Comercial para Vendedores.
 *
 * - La "venta del vendedor" se mide sobre COTIZACIONES atribuidas al vendedor
 *   (scope SolicitudCotizacion::deVendedor = created_by OR cliente.vendedor_id).
 * - Si $vendedorId es null/0, el scope no filtra: agrega TODOS los vendedores
 *   (opción "Todos" del panel admin). El panel del vendedor siempre pasa Auth::id().
 * - Aditivo: no altera MetricasService ni el flujo de ventas/cotizaciones.
 *
 * Clasificación de PAGO idéntica al sistema (getEtiquetaEstadoPagoAttribute):
 *   forma_pago_factura con 'Mixto'   => Mixto
 *   forma_pago_factura con 'Crédito' => Crédito  (sin importar estado_pago)
 *   en otro caso, según estado_pago  => Pagado (contado) / Pendiente / Parcial
 */
class SeguimientoComercialService
{
    /** Condición SQL: la cotización NO es crédito ni mixto (es de contado). */
    protected string $sqlContado = "(forma_pago_factura IS NULL OR (forma_pago_factura NOT LIKE '%Crédito%' AND forma_pago_factura NOT LIKE '%Mixto%'))";

    /**
     * Resumen de ventas del vendedor: total vendido (cotizaciones aplicadas),
     * número de pedidos y ticket promedio.
     */
    public function resumenVendedor(?int $vendedorId, ?Carbon $fechaInicio = null, ?Carbon $fechaFin = null): array
    {
        $fechaInicio = $fechaInicio ?? Carbon::now()->startOfMonth();
        $fechaFin = $fechaFin ?? Carbon::now()->endOfMonth();

        $row = SolicitudCotizacion::deVendedor($vendedorId)
            ->aplicadas()
            ->whereBetween('aplicada_en', [$fechaInicio, $fechaFin])
            ->selectRaw('COUNT(*) as cantidad, COALESCE(SUM(monto_total), 0) as monto')
            ->first();

        $totalVentas = $row->monto ?? 0;
        $totalPedidos = $row->cantidad ?? 0;

        return [
            'total_ventas' => $totalVentas,
            'total_pedidos' => $totalPedidos,
            'ticket_promedio' => $totalPedidos > 0 ? $totalVentas / $totalPedidos : 0,
            'periodo' => [
                'inicio' => $fechaInicio->format('Y-m-d'),
                'fin' => $fechaFin->format('Y-m-d'),
            ],
        ];
    }

    /**
     * Comparativa del vendedor entre el período actual y el anterior.
     */
    public function comparativaVendedor(
        ?int $vendedorId,
        Carbon $inicioActual,
        Carbon $finActual,
        Carbon $inicioAnterior,
        Carbon $finAnterior
    ): array {
        $actual = $this->resumenVendedor($vendedorId, $inicioActual, $finActual);
        $anterior = $this->resumenVendedor($vendedorId, $inicioAnterior, $finAnterior);

        $variacion = function ($hoy, $antes) {
            if ($antes > 0) {
                return round((($hoy - $antes) / $antes) * 100, 1);
            }
            return $hoy > 0 ? 100 : 0;
        };

        $varVentas = $variacion($actual['total_ventas'], $anterior['total_ventas']);
        $varPedidos = $variacion($actual['total_pedidos'], $anterior['total_pedidos']);
        $varTicket = $variacion($actual['ticket_promedio'], $anterior['ticket_promedio']);

        return [
            'actual' => $actual,
            'anterior' => $anterior,
            'variacion' => [
                'ventas' => ['valor' => $varVentas, 'tendencia' => $varVentas >= 0 ? 'up' : 'down'],
                'pedidos' => ['valor' => $varPedidos, 'tendencia' => $varPedidos >= 0 ? 'up' : 'down'],
                'ticket' => ['valor' => $varTicket, 'tendencia' => $varTicket >= 0 ? 'up' : 'down'],
            ],
        ];
    }

    /**
     * Desglose de las ventas (cotizaciones aplicadas) del período por estado de PAGO,
     * con la MISMA clasificación que el badge del sistema. Las categorías suman el total.
     * Además, la tasa de conversión (aplicadas / creadas en el período).
     */
    public function cotizacionesPorEstado(?int $vendedorId, ?Carbon $fechaInicio = null, ?Carbon $fechaFin = null): array
    {
        $fechaInicio = $fechaInicio ?? Carbon::now()->startOfMonth();
        $fechaFin = $fechaFin ?? Carbon::now()->endOfMonth();

        $contado = $this->sqlContado;

        // Desglose por estado de PAGO sobre las ventas aplicadas del período.
        $p = SolicitudCotizacion::deVendedor($vendedorId)
            ->aplicadas()
            ->whereBetween('aplicada_en', [$fechaInicio, $fechaFin])
            ->selectRaw("
                COUNT(*) as total_cantidad,
                COALESCE(SUM(monto_total), 0) as total_monto,
                SUM(CASE WHEN forma_pago_factura LIKE '%Mixto%' THEN 1 ELSE 0 END) as mixto_cantidad,
                COALESCE(SUM(CASE WHEN forma_pago_factura LIKE '%Mixto%' THEN monto_total ELSE 0 END), 0) as mixto_monto,
                SUM(CASE WHEN forma_pago_factura LIKE '%Crédito%' AND forma_pago_factura NOT LIKE '%Mixto%' THEN 1 ELSE 0 END) as credito_cantidad,
                COALESCE(SUM(CASE WHEN forma_pago_factura LIKE '%Crédito%' AND forma_pago_factura NOT LIKE '%Mixto%' THEN monto_total ELSE 0 END), 0) as credito_monto,
                SUM(CASE WHEN {$contado} AND estado_pago = 'pagado' THEN 1 ELSE 0 END) as contado_cantidad,
                COALESCE(SUM(CASE WHEN {$contado} AND estado_pago = 'pagado' THEN monto_total ELSE 0 END), 0) as contado_monto,
                SUM(CASE WHEN {$contado} AND estado_pago = 'pendiente' THEN 1 ELSE 0 END) as pendiente_cantidad,
                COALESCE(SUM(CASE WHEN {$contado} AND estado_pago = 'pendiente' THEN monto_total ELSE 0 END), 0) as pendiente_monto,
                SUM(CASE WHEN {$contado} AND estado_pago = 'parcial' THEN 1 ELSE 0 END) as parcial_cantidad,
                COALESCE(SUM(CASE WHEN {$contado} AND estado_pago = 'parcial' THEN monto_total ELSE 0 END), 0) as parcial_monto
            ")
            ->first();

        // Conversión sobre las cotizaciones CREADAS en el período.
        $c = SolicitudCotizacion::deVendedor($vendedorId)
            ->whereBetween('created_at', [$fechaInicio, $fechaFin])
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN estado = 'aplicada' THEN 1 ELSE 0 END) as aplicadas,
                SUM(CASE WHEN estado = 'pendiente' THEN 1 ELSE 0 END) as pendientes
            ")
            ->first();

        $totalMonto = $p->total_monto ?? 0;
        $part = fn($monto) => $totalMonto > 0 ? round(($monto / $totalMonto) * 100, 1) : 0;

        return [
            'total' => ['cantidad' => $p->total_cantidad ?? 0, 'monto' => $totalMonto],
            'contado' => ['cantidad' => $p->contado_cantidad ?? 0, 'monto' => $p->contado_monto ?? 0, 'participacion' => $part($p->contado_monto ?? 0)],
            'pendiente' => ['cantidad' => $p->pendiente_cantidad ?? 0, 'monto' => $p->pendiente_monto ?? 0, 'participacion' => $part($p->pendiente_monto ?? 0)],
            'parcial' => ['cantidad' => $p->parcial_cantidad ?? 0, 'monto' => $p->parcial_monto ?? 0, 'participacion' => $part($p->parcial_monto ?? 0)],
            'credito' => ['cantidad' => $p->credito_cantidad ?? 0, 'monto' => $p->credito_monto ?? 0, 'participacion' => $part($p->credito_monto ?? 0)],
            'mixto' => ['cantidad' => $p->mixto_cantidad ?? 0, 'monto' => $p->mixto_monto ?? 0, 'participacion' => $part($p->mixto_monto ?? 0)],
            // Cotizaciones aún SIN aplicar (estado de la cotización, no del pago):
            'cotizaciones_pendientes' => $c->pendientes ?? 0,
            'tasa_conversion' => ($c->total ?? 0) > 0 ? round((($c->aplicadas ?? 0) / $c->total) * 100, 1) : 0,
        ];
    }

    /**
     * Ranking de clientes del vendedor por ventas en el período. (Etapa 3)
     */
    public function rankingClientes(?int $vendedorId, ?Carbon $fechaInicio = null, ?Carbon $fechaFin = null, ?int $limite = null): array
    {
        $fechaInicio = $fechaInicio ?? Carbon::now()->startOfMonth();
        $fechaFin = $fechaFin ?? Carbon::now()->endOfMonth();

        $query = SolicitudCotizacion::deVendedor($vendedorId)
            ->aplicadas()
            ->whereNotNull('solicitudes_cotizacion.cliente_id')
            ->whereBetween('aplicada_en', [$fechaInicio, $fechaFin])
            ->join('clientes', 'solicitudes_cotizacion.cliente_id', '=', 'clientes.id')
            ->selectRaw('
                clientes.id as cliente_id,
                clientes.nombre_contacto as cliente,
                COUNT(*) as pedidos,
                COALESCE(SUM(solicitudes_cotizacion.monto_total), 0) as total_facturado,
                MAX(solicitudes_cotizacion.aplicada_en) as ultima_compra
            ')
            ->groupBy('clientes.id', 'clientes.nombre_contacto')
            ->orderByDesc('total_facturado');

        if ($limite) {
            $query->limit($limite);
        }

        return $query->get()->map(function ($fila) {
            return [
                'cliente_id' => $fila->cliente_id,
                'cliente' => $fila->cliente ?: 'Sin nombre',
                'pedidos' => (int) $fila->pedidos,
                'total_facturado' => (float) $fila->total_facturado,
                'ultima_compra' => $fila->ultima_compra ? Carbon::parse($fila->ultima_compra) : null,
            ];
        })->toArray();
    }

    /**
     * Detalle de pedidos de un cliente concreto para el vendedor. (Etapa 3 - drill-down)
     */
    public function pedidosDeCliente(?int $vendedorId, int $clienteId, ?Carbon $fechaInicio = null, ?Carbon $fechaFin = null)
    {
        $fechaInicio = $fechaInicio ?? Carbon::now()->startOfMonth();
        $fechaFin = $fechaFin ?? Carbon::now()->endOfMonth();

        return SolicitudCotizacion::deVendedor($vendedorId)
            ->where('cliente_id', $clienteId)
            ->whereBetween('created_at', [$fechaInicio, $fechaFin])
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Seguimiento de pedidos del vendedor: pendientes (sin aplicar) y últimos. (Etapa 5)
     */
    public function seguimientoPedidos(?int $vendedorId, int $limiteUltimos = 15): array
    {
        // 1) Cotizaciones SIN APLICAR (estado de la cotización = pendiente).
        $pendientes = SolicitudCotizacion::deVendedor($vendedorId)
            ->with(['cliente:id,nombre_contacto'])
            ->pendientes()
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        // 2) PAGOS POR COBRAR: ventas aplicadas con pago pendiente (badge "Pendiente",
        //    es decir contado sin cobrar; el crédito tiene su propio badge).
        $porCobrar = SolicitudCotizacion::deVendedor($vendedorId)
            ->with(['cliente:id,nombre_contacto'])
            ->aplicadas()
            ->where('estado_pago', 'pendiente')
            ->where(function ($q) {
                $q->whereNull('forma_pago_factura')
                  ->orWhere(function ($q2) {
                      $q2->where('forma_pago_factura', 'not like', '%Crédito%')
                         ->where('forma_pago_factura', 'not like', '%Mixto%');
                  });
            })
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        $ultimos = SolicitudCotizacion::deVendedor($vendedorId)
            ->with(['cliente:id,nombre_contacto'])
            ->orderByDesc('created_at')
            ->limit($limiteUltimos)
            ->get();

        return [
            'pendientes' => $pendientes,
            'por_cobrar' => $porCobrar,
            'ultimos' => $ultimos,
        ];
    }

    /**
     * Tendencia diaria de ventas del vendedor (cotizaciones aplicadas por día). (Etapa 2)
     */
    public function tendenciaDiaria(?int $vendedorId, int $dias = 30): array
    {
        $fechaInicio = Carbon::now()->subDays($dias - 1)->startOfDay();
        $fechaFin = Carbon::now()->endOfDay();

        $porDia = SolicitudCotizacion::deVendedor($vendedorId)
            ->aplicadas()
            ->whereBetween('aplicada_en', [$fechaInicio, $fechaFin])
            ->selectRaw('DATE(aplicada_en) as fecha, COALESCE(SUM(monto_total), 0) as monto')
            ->groupBy('fecha')
            ->get()
            ->keyBy('fecha');

        $tendencia = [];
        $fecha = $fechaInicio->copy();
        while ($fecha <= $fechaFin) {
            $fechaStr = $fecha->format('Y-m-d');
            $tendencia[] = [
                'fecha' => $fechaStr,
                'fecha_corta' => $fecha->format('d/m'),
                'monto' => $porDia->get($fechaStr)->monto ?? 0,
            ];
            $fecha->addDay();
        }

        return $tendencia;
    }
}
