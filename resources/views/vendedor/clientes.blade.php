<x-app-layout>
    <x-slot name="header">
        {{ __('Mis Ventas por Cliente') }}
    </x-slot>

    <div class="container-fluid py-4">

        @include('vendedor.partials._filtros', [
            'ruta' => 'vendedor.clientes',
            'titulo' => 'Mis ventas por cliente',
            'icono' => 'people',
            'exportar' => true,
        ])

        @php
            $totalGeneral = collect($clientes)->sum('total_facturado');
            $pedidosGeneral = collect($clientes)->sum('pedidos');
        @endphp

        {{-- Resumen --}}
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <x-card-metric
                    title="Clientes con compras"
                    :value="count($clientes)"
                    icon="bi-people"
                    color="lilac"
                />
            </div>
            <div class="col-md-4">
                <x-card-metric
                    title="Total facturado"
                    :value="'$' . number_format($totalGeneral, 0, ',', '.')"
                    icon="bi-currency-dollar"
                    color="success"
                    :subtitle="$pedidosGeneral . ' pedidos'"
                />
            </div>
            <div class="col-md-4">
                <x-card-metric
                    title="Promedio por cliente"
                    :value="'$' . number_format(count($clientes) > 0 ? $totalGeneral / count($clientes) : 0, 0, ',', '.')"
                    icon="bi-graph-up"
                    color="gold"
                />
            </div>
        </div>

        {{-- Ranking de clientes --}}
        <div class="card">
            <div class="card-header bg-white">
                <h6 class="mb-0"><i class="bi bi-trophy me-2"></i>Ranking de clientes por ventas</h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th style="width:60px">#</th>
                                <th>Cliente</th>
                                <th class="text-center">Pedidos</th>
                                <th class="text-end">Total facturado</th>
                                <th class="text-center">Última compra</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($clientes as $index => $cliente)
                                <tr>
                                    <td>
                                        @if($index === 0)
                                            <span class="badge bg-warning text-dark"><i class="bi bi-trophy-fill"></i></span>
                                        @else
                                            {{ $index + 1 }}
                                        @endif
                                    </td>
                                    <td class="fw-semibold">{{ $cliente['cliente'] }}</td>
                                    <td class="text-center">
                                        <span class="badge bg-secondary">{{ $cliente['pedidos'] }}</span>
                                    </td>
                                    <td class="text-end fw-semibold">${{ number_format($cliente['total_facturado'], 0, ',', '.') }}</td>
                                    <td class="text-center">
                                        @if($cliente['ultima_compra'])
                                            <span title="{{ $cliente['ultima_compra']->isoFormat('D MMM YYYY') }}">
                                                {{ $cliente['ultima_compra']->isoFormat('D MMM YYYY') }}
                                            </span>
                                        @else
                                            <span class="text-muted">&mdash;</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-4">
                                        <i class="bi bi-inbox fs-3 d-block mb-2"></i>
                                        Sin ventas por cliente en este período
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
