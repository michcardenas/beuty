<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SeguimientoComercialService;
use App\Exports\SeguimientoVendedorExport;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

/**
 * Panel de ADMIN para el Seguimiento Comercial: el administrador elige un vendedor
 * y ve todas sus métricas consolidadas en una sola pantalla.
 *
 * A diferencia del panel del vendedor (que siempre usa Auth::id()), aquí el
 * vendedor a mostrar viene del selector (request), PERO la ruta está bajo
 * middleware role:admin y el id se valida contra la lista real de vendedores,
 * así que solo un admin puede consultar y solo vendedores válidos.
 */
class PanelAdminVendedoresController extends Controller
{
    protected SeguimientoComercialService $seguimiento;

    public function __construct(SeguimientoComercialService $seguimiento)
    {
        $this->seguimiento = $seguimiento;
    }

    public function index(Request $request)
    {
        $vendedores = User::role('vendedor')->orderBy('name')->get(['id', 'name']);
        $vendedorId = $this->resolverVendedor($request, $vendedores);
        [$periodo, $fechaInicio, $fechaFin, $fechaInicioAnterior, $fechaFinAnterior] = $this->resolverPeriodo($request);

        // Siempre hay datos: un vendedor concreto, o el agregado de TODOS ($vendedorId = null).
        $comparativa = $this->seguimiento->comparativaVendedor(
            $vendedorId, $fechaInicio, $fechaFin, $fechaInicioAnterior, $fechaFinAnterior
        );

        return view('admin.seguimiento-vendedores', [
            'vendedores' => $vendedores,
            'vendedorId' => $vendedorId,
            'vendedorSel' => $vendedorId ? $vendedores->firstWhere('id', $vendedorId) : null,
            'periodo' => $periodo,
            'fechaInicio' => $fechaInicio,
            'fechaFin' => $fechaFin,
            'resumen' => $comparativa['actual'],
            'variacion' => $comparativa['variacion'],
            'estados' => $this->seguimiento->cotizacionesPorEstado($vendedorId, $fechaInicio, $fechaFin),
            'tendencia' => $this->seguimiento->tendenciaDiaria($vendedorId, 30),
            'clientes' => $this->seguimiento->rankingClientes($vendedorId, $fechaInicio, $fechaFin, 15),
            'seguimiento' => $this->seguimiento->seguimientoPedidos($vendedorId),
        ]);
    }

    public function exportar(Request $request)
    {
        $vendedores = User::role('vendedor')->get(['id', 'name']);
        $vendedorId = $this->resolverVendedor($request, $vendedores);
        abort_if(!$vendedorId, 404, 'No hay vendedor seleccionado.');

        [$periodo, $fechaInicio, $fechaFin] = $this->resolverPeriodo($request);
        $vendedor = $vendedores->firstWhere('id', $vendedorId);
        $slug = \Illuminate\Support\Str::slug($vendedor->name ?? 'vendedor');

        $nombreArchivo = "seguimiento-{$slug}-" . $fechaInicio->format('Ymd') . '-' . $fechaFin->format('Ymd') . '.xlsx';

        return Excel::download(new SeguimientoVendedorExport($vendedorId, $fechaInicio, $fechaFin), $nombreArchivo);
    }

    /**
     * Devuelve el id del vendedor solicitado si es un vendedor válido; si no,
     * el primero de la lista (comportamiento por defecto al abrir la pantalla).
     */
    protected function resolverVendedor(Request $request, $vendedores): ?int
    {
        $sel = $request->get('vendedor_id');
        // vacío o "todos" => null (agrega TODOS los vendedores). Es el valor por defecto.
        if ($sel === null || $sel === '' || $sel === 'todos') {
            return null;
        }
        $sel = (int) $sel;
        return ($sel && $vendedores->firstWhere('id', $sel)) ? $sel : null;
    }

    /**
     * Igual que en el panel del vendedor: presets (hoy/semana/mes/año) o rango
     * personalizado, con el período anterior equivalente para la comparativa.
     *
     * @return array{0:string,1:Carbon,2:Carbon,3:Carbon,4:Carbon}
     */
    protected function resolverPeriodo(Request $request): array
    {
        $desde = $request->get('fecha_inicio');
        $hasta = $request->get('fecha_fin');

        if ($desde && $hasta) {
            try {
                $fechaInicio = Carbon::parse($desde)->startOfDay();
                $fechaFin = Carbon::parse($hasta)->endOfDay();
            } catch (\Throwable $e) {
                $fechaInicio = $fechaFin = null;
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
