<?php

namespace App\Services;

use App\Models\SolicitudCotizacion;
use Carbon\Carbon;

/**
 * Servicio de Seguimiento Comercial para Vendedores.
 *
 * Reglas del módulo (Opción A):
 * - La "venta del vendedor" se mide sobre COTIZACIONES atribuidas al vendedor
 *   (scope SolicitudCotizacion::deVendedor = created_by OR cliente.vendedor_id).
 * - Todo cálculo va SIEMPRE filtrado por $vendedorId; el controlador pasa
 *   Auth::id() para el rol vendedor (dato aislado, no editable).
 * - Es aditivo: no altera MetricasService ni el flujo de ventas/cotizaciones.
 *
 * La clasificación contado/crédito reutiliza la misma regla que el resto del
 * sistema: forma_pago_factura LIKE '%Crédito%' => crédito; en otro caso contado.
 */
class SeguimientoComercialService
{
    /**
     * Resumen de ventas del vendedor en el período: total vendido (cotizaciones
     * aplicadas), número de pedidos y ticket promedio.
     */
    public function resumenVendedor(int $vendedorId, ?Carbon $fechaInicio = null, ?Carbon $fechaFin = null): array
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
     * Comparativa del vendedor entre el período actual y el anterior,
     * con variación porcentual y tendencia (up/down) para las tarjetas.
     */
    public function comparativaVendedor(
        int $vendedorId,
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
     * Cotizaciones del vendedor agrupadas por estado en el período (por fecha de
     * creación), incluyendo el desglose contado/crédito. Misma regla de negocio
     * que MetricasService::getCotizacionesPorEstado, filtrada por vendedor.
     */
    public function cotizacionesPorEstado(int $vendedorId, ?Carbon $fechaInicio = null, ?Carbon $fechaFin = null): array
    {
        $fechaInicio = $fechaInicio ?? Carbon::now()->startOfMonth();
        $fechaFin = $fechaFin ?? Carbon::now()->endOfMonth();

        $row = SolicitudCotizacion::deVendedor($vendedorId)
            ->whereBetween('created_at', [$fechaInicio, $fechaFin])
            ->selectRaw('
                COUNT(*) as total_cantidad,
                COALESCE(SUM(monto_total), 0) as total_monto,
                SUM(CASE WHEN estado = "pendiente" THEN 1 ELSE 0 END) as pendientes_cantidad,
                COALESCE(SUM(CASE WHEN estado = "pendiente" THEN monto_total ELSE 0 END), 0) as pendientes_monto,
                SUM(CASE WHEN estado = "aplicada" THEN 1 ELSE 0 END) as aplicadas_cantidad,
                COALESCE(SUM(CASE WHEN estado = "aplicada" THEN monto_total ELSE 0 END), 0) as aplicadas_monto,
                SUM(CASE WHEN estado = "aplicada" AND estado_pago = "pagado" AND (forma_pago_factura IS NULL OR forma_pago_factura NOT LIKE "%Crédito%") THEN 1 ELSE 0 END) as contado_cantidad,
                COALESCE(SUM(CASE WHEN estado = "aplicada" AND estado_pago = "pagado" AND (forma_pago_factura IS NULL OR forma_pago_factura NOT LIKE "%Crédito%") THEN monto_total ELSE 0 END), 0) as contado_monto,
                SUM(CASE WHEN estado = "aplicada" AND estado_pago = "pagado" AND forma_pago_factura LIKE "%Crédito%" THEN 1 ELSE 0 END) as credito_cantidad,
                COALESCE(SUM(CASE WHEN estado = "aplicada" AND estado_pago = "pagado" AND forma_pago_factura LIKE "%Crédito%" THEN monto_total ELSE 0 END), 0) as credito_monto,
                SUM(CASE WHEN estado = "rechazada" THEN 1 ELSE 0 END) as rechazadas_cantidad,
                COALESCE(SUM(CASE WHEN estado = "rechazada" THEN monto_total ELSE 0 END), 0) as rechazadas_monto
            ')
            ->first();

        $totalCantidad = $row->total_cantidad ?? 0;
        $contadoCantidad = $row->contado_cantidad ?? 0;
        $contadoMonto = $row->contado_monto ?? 0;
        $creditoCantidad = $row->credito_cantidad ?? 0;
        $creditoMonto = $row->credito_monto ?? 0;
        $pagadasMonto = $contadoMonto + $creditoMonto;

        return [
            'pendientes' => [
                'cantidad' => $row->pendientes_cantidad ?? 0,
                'monto' => $row->pendientes_monto ?? 0,
            ],
            'aplicadas' => [
                'cantidad' => $row->aplicadas_cantidad ?? 0,
                'monto' => $row->aplicadas_monto ?? 0,
            ],
            'contado' => [
                'cantidad' => $contadoCantidad,
                'monto' => $contadoMonto,
                'participacion' => $pagadasMonto > 0 ? round(($contadoMonto / $pagadasMonto) * 100, 1) : 0,
            ],
            'credito' => [
                'cantidad' => $creditoCantidad,
                'monto' => $creditoMonto,
                'participacion' => $pagadasMonto > 0 ? round(($creditoMonto / $pagadasMonto) * 100, 1) : 0,
            ],
            'rechazadas' => [
                'cantidad' => $row->rechazadas_cantidad ?? 0,
                'monto' => $row->rechazadas_monto ?? 0,
            ],
            'total' => [
                'cantidad' => $totalCantidad,
                'monto' => $row->total_monto ?? 0,
            ],
            'tasa_conversion' => $totalCantidad > 0
                ? round((($row->aplicadas_cantidad ?? 0) / $totalCantidad) * 100, 1)
                : 0,
        ];
    }

    /**
     * Ranking de clientes del vendedor por ventas en el período: total facturado,
     * número de pedidos y fecha de última compra. (Etapa 3)
     */
    public function rankingClientes(int $vendedorId, ?Carbon $fechaInicio = null, ?Carbon $fechaFin = null, ?int $limite = null): array
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
     * Detalle de pedidos (cotizaciones) de un cliente concreto para el vendedor,
     * en el período. (Etapa 3 - drill-down)
     */
    public function pedidosDeCliente(int $vendedorId, int $clienteId, ?Carbon $fechaInicio = null, ?Carbon $fechaFin = null)
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
     * Seguimiento de pedidos del vendedor para priorizar la atención:
     * pendientes (todos, para gestionar) y los últimos movimientos. (Etapa 5)
     */
    public function seguimientoPedidos(int $vendedorId, int $limiteUltimos = 15): array
    {
        $pendientes = SolicitudCotizacion::deVendedor($vendedorId)
            ->with(['cliente:id,nombre_contacto'])
            ->pendientes()
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
            'ultimos' => $ultimos,
        ];
    }

    /**
     * Tendencia diaria de ventas del vendedor (cotizaciones aplicadas por día)
     * para el gráfico del panel. (Etapa 2)
     */
    public function tendenciaDiaria(int $vendedorId, int $dias = 30): array
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
