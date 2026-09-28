<x-app-layout>
    <x-slot name="header">
        {{ __('Mi Panel de Ventas') }}
    </x-slot>

    <div class="container-fluid py-4">

        {{-- Filtros de período --}}
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-body py-3">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                            <div>
                                <h5 class="mb-0">
                                    <i class="bi bi-person-badge me-2"></i>
                                    Mi gestión comercial
                                </h5>
                                <small class="text-muted">
                                    {{ \Carbon\Carbon::parse($fechaInicio)->isoFormat('D MMM YYYY') }}
                                    &ndash;
                                    {{ \Carbon\Carbon::parse($fechaFin)->isoFormat('D MMM YYYY') }}
                                </small>
                            </div>

                            <div class="d-flex flex-wrap gap-2 align-items-center">
                                {{-- Presets --}}
                                <div class="btn-group" role="group">
                                    @foreach(['hoy' => 'Hoy', 'semana' => 'Semana', 'mes' => 'Mes', 'año' => 'Año'] as $key => $label)
                                        <a href="{{ route('vendedor.panel', ['periodo' => $key]) }}"
                                           class="btn btn-sm {{ $periodo === $key ? 'btn-primary' : 'btn-outline-primary' }}">
                                            {{ $label }}
                                        </a>
                                    @endforeach
                                </div>

                                {{-- Rango personalizado --}}
                                <form method="GET" action="{{ route('vendedor.panel') }}" class="d-flex gap-2 align-items-center">
                                    <input type="date" name="fecha_inicio" class="form-control form-control-sm"
                                           value="{{ request('fecha_inicio') }}" max="{{ now()->format('Y-m-d') }}">
                                    <span class="text-muted small">a</span>
                                    <input type="date" name="fecha_fin" class="form-control form-control-sm"
                                           value="{{ request('fecha_fin') }}" max="{{ now()->format('Y-m-d') }}">
                                    <button type="submit" class="btn btn-sm btn-outline-secondary" title="Filtrar por rango">
                                        <i class="bi bi-funnel"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- KPIs principales --}}
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <x-card-metric
                    title="Total Ventas del Período"
                    :value="'$' . number_format($resumen['total_ventas'] ?? 0, 0, ',', '.')"
                    icon="bi-currency-dollar"
                    color="success"
                    :subtitle="($resumen['total_pedidos'] ?? 0) . ' pedidos aplicados'"
                    :trend="$variacion['ventas']['tendencia'] ?? null"
                    :trendValue="($variacion['ventas']['valor'] ?? 0) . '% vs período anterior'"
                />
            </div>
            <div class="col-md-4">
                <x-card-metric
                    title="Pedidos"
                    :value="($resumen['total_pedidos'] ?? 0)"
                    icon="bi-bag-check"
                    color="lilac"
                    subtitle="Cotizaciones aplicadas"
                    :trend="$variacion['pedidos']['tendencia'] ?? null"
                    :trendValue="($variacion['pedidos']['valor'] ?? 0) . '%'"
                />
            </div>
            <div class="col-md-4">
                <x-card-metric
                    title="Ticket Promedio"
                    :value="'$' . number_format($resumen['ticket_promedio'] ?? 0, 0, ',', '.')"
                    icon="bi-receipt"
                    color="gold"
                    subtitle="Por pedido"
                    :trend="$variacion['ticket']['tendencia'] ?? null"
                    :trendValue="($variacion['ticket']['valor'] ?? 0) . '%'"
                />
            </div>
        </div>

        {{-- Desglose del período --}}
        <div class="row g-3 mb-4">
            <div class="col-md-6 col-lg-3">
                <x-card-metric
                    title="Contado"
                    :value="'$' . number_format($estados['contado']['monto'] ?? 0, 0, ',', '.')"
                    icon="bi-cash-stack"
                    color="pink"
                    :subtitle="($estados['contado']['cantidad'] ?? 0) . ' ventas · ' . ($estados['contado']['participacion'] ?? 0) . '%'"
                />
            </div>
            <div class="col-md-6 col-lg-3">
                <x-card-metric
                    title="Crédito"
                    :value="'$' . number_format($estados['credito']['monto'] ?? 0, 0, ',', '.')"
                    icon="bi-credit-card"
                    color="lilac"
                    :subtitle="($estados['credito']['cantidad'] ?? 0) . ' ventas · ' . ($estados['credito']['participacion'] ?? 0) . '%'"
                />
            </div>
            <div class="col-md-6 col-lg-3">
                <x-card-metric
                    title="Pago Pendiente"
                    :value="'$' . number_format($estados['pendiente']['monto'] ?? 0, 0, ',', '.')"
                    icon="bi-hourglass-split"
                    color="warning"
                    :subtitle="($estados['pendiente']['cantidad'] ?? 0) . ' ventas por cobrar · ' . ($estados['pendiente']['participacion'] ?? 0) . '%'"
                />
            </div>
            <div class="col-md-6 col-lg-3">
                <x-card-metric
                    title="Tasa de Conversión"
                    :value="($estados['tasa_conversion'] ?? 0) . '%'"
                    icon="bi-graph-up-arrow"
                    color="lilac"
                    subtitle="Aplicadas / Total"
                />
            </div>
        </div>

        {{-- Tendencia de ventas (últimos 30 días) --}}
        <div class="row g-3">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-white d-flex align-items-center">
                        <h6 class="mb-0"><i class="bi bi-bar-chart me-2"></i>Mis ventas — últimos 30 días</h6>
                    </div>
                    <div class="card-body">
                        <canvas id="chartMisVentas" height="80"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @push('scripts')
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0"></script>
        <script>
            (function () {
                const canvas = document.getElementById('chartMisVentas');
                if (!canvas || typeof Chart === 'undefined') return;

                const datos = @json($tendencia);
                new Chart(canvas.getContext('2d'), {
                    type: 'line',
                    data: {
                        labels: datos.map(d => d.fecha_corta),
                        datasets: [{
                            label: 'Ventas',
                            data: datos.map(d => Number(d.monto)),
                            borderColor: '#FF84D5',
                            backgroundColor: 'rgba(255, 132, 213, 0.12)',
                            fill: true,
                            tension: 0.35,
                            pointRadius: 2,
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: true,
                        plugins: { legend: { display: false } },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    callback: v => '$' + new Intl.NumberFormat('es-CO').format(v)
                                }
                            }
                        }
                    }
                });
            })();
        </script>
    @endpush
</x-app-layout>
