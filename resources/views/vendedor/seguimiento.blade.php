<x-app-layout>
    <x-slot name="header">
        {{ __('Seguimiento de Pedidos') }}
    </x-slot>

    @php
        $badge = function ($estado) {
            return match ($estado) {
                'aplicada' => 'bg-success',
                'pendiente' => 'bg-warning text-dark',
                'rechazada' => 'bg-danger',
                default => 'bg-secondary',
            };
        };
    @endphp

    <div class="container-fluid py-4">

        {{-- Pendientes (prioridad de atención) --}}
        <div class="card mb-4">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="bi bi-hourglass-split me-2 text-secondary"></i>Cotizaciones sin aplicar</h6>
                <span class="badge bg-secondary">{{ $pendientes->count() }}</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>N.º</th>
                                <th>Cliente</th>
                                <th class="text-end">Monto</th>
                                <th class="text-center">Creada</th>
                                <th class="text-center">Acción</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($pendientes as $p)
                                <tr>
                                    <td class="fw-semibold">{{ $p->numero_solicitud }}</td>
                                    <td>{{ optional($p->cliente)->nombre_contacto ?? 'N/A' }}</td>
                                    <td class="text-end">${{ number_format($p->monto_total, 0, ',', '.') }}</td>
                                    <td class="text-center">
                                        <span title="{{ $p->created_at->isoFormat('D MMM YYYY HH:mm') }}">
                                            {{ $p->created_at->diffForHumans() }}
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <a href="{{ route('solicitudes.detalle', $p->id) }}" class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-eye"></i> Ver
                                        </a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-4">
                                        <i class="bi bi-check2-circle fs-3 d-block mb-2 text-success"></i>
                                        No tienes pedidos pendientes. ¡Todo al día!
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- Pagos por cobrar --}}
        <div class="card mb-4">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="bi bi-cash-coin me-2 text-warning"></i>Pagos por cobrar</h6>
                <span class="badge bg-warning text-dark">{{ $porCobrar->count() }}</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>N.º</th>
                                <th>Cliente</th>
                                <th class="text-end">Monto</th>
                                <th class="text-center">Fecha</th>
                                <th class="text-center">Acción</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($porCobrar as $p)
                                <tr>
                                    <td class="fw-semibold">{{ $p->numero_solicitud }}</td>
                                    <td>{{ optional($p->cliente)->nombre_contacto ?? 'N/A' }}</td>
                                    <td class="text-end">${{ number_format($p->monto_total, 0, ',', '.') }}</td>
                                    <td class="text-center">{{ $p->created_at->isoFormat('D MMM YYYY') }}</td>
                                    <td class="text-center">
                                        <a href="{{ route('solicitudes.detalle', $p->id) }}" class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-eye"></i> Ver
                                        </a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-4">
                                        <i class="bi bi-check2-circle fs-3 d-block mb-2 text-success"></i>
                                        No tienes pagos por cobrar. ¡Todo cobrado!
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- Últimos movimientos --}}
        <div class="card">
            <div class="card-header bg-white">
                <h6 class="mb-0"><i class="bi bi-clock-history me-2"></i>Últimos movimientos</h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>N.º</th>
                                <th>Cliente</th>
                                <th class="text-end">Monto</th>
                                <th class="text-center">Estado</th>
                                <th class="text-center">Fecha</th>
                                <th class="text-center">Acción</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($ultimos as $u)
                                <tr>
                                    <td class="fw-semibold">{{ $u->numero_solicitud }}</td>
                                    <td>{{ optional($u->cliente)->nombre_contacto ?? 'N/A' }}</td>
                                    <td class="text-end">${{ number_format($u->monto_total, 0, ',', '.') }}</td>
                                    <td class="text-center">
                                        <span class="badge {{ $badge($u->estado) }}">{{ ucfirst($u->estado) }}</span>
                                    </td>
                                    <td class="text-center">{{ $u->created_at->isoFormat('D MMM YYYY') }}</td>
                                    <td class="text-center">
                                        <a href="{{ route('solicitudes.detalle', $u->id) }}" class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-eye"></i> Ver
                                        </a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4">Sin movimientos recientes</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
