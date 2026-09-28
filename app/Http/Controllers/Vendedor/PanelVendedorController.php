<?php

namespace App\Http\Controllers\Vendedor;

use App\Http\Controllers\Controller;
use App\Services\SeguimientoComercialService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

/**
 * Panel de Seguimiento Comercial del Vendedor.
 *
 * Todas las vistas muestran ÚNICAMENTE la información del vendedor autenticado
 * (Auth::id()). El identificador del vendedor nunca se toma del request: es un
 * dato aislado y no editable, tal como exige la propuesta.
 */
class PanelVendedorController extends Controller
{
    protected SeguimientoComercialService $seguimiento;

    public function __construct(SeguimientoComercialService $seguimiento)
    {
        $this->seguimiento = $seguimiento;
    }

    /**
     * Etapa 2 — Panel de ventas del vendedor.
     */
    public function index(Request $request)
    {
        $vendedorId = (int) Auth::id();
        [$periodo, $fechaInicio, $fechaFin, $fechaInicioAnterior, $fechaFinAnterior] = $this->resolverPeriodo($request);

        $comparativa = $this->seguimiento->comparativaVendedor(
            $vendedorId,
            $fechaInicio,
            $fechaFin,
            $fechaInicioAnterior,
            $fechaFinAnterior
        );

        $estados = $this->seguimiento->cotizacionesPorEstado($vendedorId, $fechaInicio, $fechaFin);
        $tendencia = $this->seguimiento->tendenciaDiaria($vendedorId, 30);

        return view('vendedor.panel', [
            'periodo' => $periodo,
            'fechaInicio' => $fechaInicio,
            'fechaFin' => $fechaFin,
            'comparativa' => $comparativa,
            'resumen' => $comparativa['actual'],
            'variacion' => $comparativa['variacion'],
            'estados' => $estados,
            'tendencia' => $tendencia,
        ]);
    }

    /**
     * Etapa 3 — Ventas por cliente (ranking + última compra).
     */
    public function ventasPorCliente(Request $request)
    {
        $vendedorId = (int) Auth::id();
        [$periodo, $fechaInicio, $fechaFin] = $this->resolverPeriodo($request);

        $clientes = $this->seguimiento->rankingClientes($vendedorId, $fechaInicio, $fechaFin);

        return view('vendedor.clientes', compact('periodo', 'fechaInicio', 'fechaFin', 'clientes'));
    }

    /**
     * Etapa 4 — Ventas de contado vs crédito.
     */
    public function contadoCredito(Request $request)
    {
        $vendedorId = (int) Auth::id();
        [$periodo, $fechaInicio, $fechaFin] = $this->resolverPeriodo($request);

        $estados = $this->seguimiento->cotizacionesPorEstado($vendedorId, $fechaInicio, $fechaFin);

        return view('vendedor.contado-credito', compact('periodo', 'fechaInicio', 'fechaFin', 'estados'));
    }

    /**
     * Etapa 5 — Seguimiento de pedidos (pendientes + últimos).
     */
    public function seguimiento(Request $request)
    {
        $vendedorId = (int) Auth::id();

        $data = $this->seguimiento->seguimientoPedidos($vendedorId);

        return view('vendedor.seguimiento', [
            'pendientes' => $data['pendientes'],
            'porCobrar' => $data['por_cobrar'],
            'ultimos' => $data['ultimos'],
        ]);
    }

    /**
     * Etapa 6 — Exportación a Excel del seguimiento del período.
     */
    public function exportar(Request $request)
    {
        $vendedorId = (int) Auth::id();
        [$periodo, $fechaInicio, $fechaFin] = $this->resolverPeriodo($request);

        $nombreArchivo = 'mi-seguimiento-' . $fechaInicio->format('Ymd') . '-' . $fechaFin->format('Ymd') . '.xlsx';

        return \Maatwebsite\Excel\Facades\Excel::download(
            new \App\Exports\SeguimientoVendedorExport($vendedorId, $fechaInicio, $fechaFin),
            $nombreArchivo
        );
    }

    /**
     * Resuelve el rango de fechas a partir del request.
     * Soporta presets (hoy/semana/mes/año) y rango personalizado
     * (fecha_inicio + fecha_fin), calculando también el período anterior
     * equivalente para la comparativa.
     *
     * @return array{0:string,1:Carbon,2:Carbon,3:Carbon,4:Carbon}
     */
    protected function resolverPeriodo(Request $request): array
    {
        $desde = $request->get('fecha_inicio');
        $hasta = $request->get('fecha_fin');

        // Rango personalizado: el período anterior es la ventana de igual
        // duración inmediatamente anterior.
        if ($desde && $hasta) {
            try {
                $fechaInicio = Carbon::parse($desde)->startOfDay();
                $fechaFin = Carbon::parse($hasta)->endOfDay();
            } catch (\Throwable $e) {
                $fechaInicio = $fechaFin = null; // fecha inválida => se usa el preset
            }

            if ($fechaInicio && $fechaFin) {
                if ($fechaFin->lt($fechaInicio)) {
                    [$fechaInicio, $fechaFin] = [$fechaFin->copy()->startOfDay(), $fechaInicio->copy()->endOfDay()];
                }

                $dias = $fechaInicio->diffInDays($fechaFin) + 1;
                $fechaFinAnterior = $fechaInicio->copy()->subDay()->endOfDay();
                $fechaInicioAnterior = $fechaFinAnterior->copy()->subDays($dias - 1)->startOfDay();

                return ['personalizado', $fechaInicio, $fechaFin, $fechaInicioAnterior, $fechaFinAnterior];
            }
        }

        // Presets
        $periodo = $request->get('periodo', 'mes');
        switch ($periodo) {
            case 'hoy':
                return ['hoy',
                    Carbon::now()->startOfDay(), Carbon::now()->endOfDay(),
                    Carbon::now()->subDay()->startOfDay(), Carbon::now()->subDay()->endOfDay()];
            case 'semana':
                return ['semana',
                    Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek(),
                    Carbon::now()->subWeek()->startOfWeek(), Carbon::now()->subWeek()->endOfWeek()];
            case 'año':
                return ['año',
                    Carbon::now()->startOfYear(), Carbon::now()->endOfYear(),
                    Carbon::now()->subYear()->startOfYear(), Carbon::now()->subYear()->endOfYear()];
            default:
                return ['mes',
                    Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth(),
                    Carbon::now()->subMonth()->startOfMonth(), Carbon::now()->subMonth()->endOfMonth()];
        }
    }
}
