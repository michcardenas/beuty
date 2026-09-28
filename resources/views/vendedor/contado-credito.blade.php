<x-app-layout>
    <x-slot name="header">
        {{ __('Contado vs Crédito') }}
    </x-slot>

    <div class="container-fluid py-4">

        @include('vendedor.partials._filtros', [
            'ruta' => 'vendedor.contado-credito',
            'titulo' => 'Mis ventas: contado vs crédito',
            'icono' => 'cash-coin',
            'exportar' => true,
        ])

        @php
            $contadoMonto = $estados['contado']['monto'] ?? 0;
            $creditoMonto = $estados['credito']['monto'] ?? 0;
            $totalPagado = $contadoMonto + $creditoMonto;
        @endphp

        {{-- Tarjetas --}}
        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <x-card-metric
                    title="Contado"
                    :value="'$' . number_format($contadoMonto, 0, ',', '.')"
                    icon="bi-cash-stack"
                    color="pink"
                    :subtitle="($estados['contado']['cantidad'] ?? 0) . ' ventas · ' . ($estados['contado']['participacion'] ?? 0) . '% de participación'"
                />
            </div>
            <div class="col-md-6">
                <x-card-metric
                    title="Crédito"
                    :value="'$' . number_format($creditoMonto, 0, ',', '.')"
                    icon="bi-credit-card"
                    color="lilac"
                    :subtitle="($estados['credito']['cantidad'] ?? 0) . ' ventas · ' . ($estados['credito']['participacion'] ?? 0) . '% de participación'"
                />
            </div>
        </div>

        <div class="row g-3">
            {{-- Gráfico de dona --}}
            <div class="col-lg-5">
                <div class="card h-100">
                    <div class="card-header bg-white">
                        <h6 class="mb-0"><i class="bi bi-pie-chart me-2"></i>Distribución</h6>
                    </div>
                    <div class="card-body d-flex align-items-center justify-content-center">
                        @if($totalPagado > 0)
                            <div style="max-width: 250px; width: 100%; margin: 0 auto;">
                                <canvas id="chartContadoCredito"></canvas>
                            </div>
                        @else
                            <div class="text-center text-muted py-4">
                                <i class="bi bi-pie-chart fs-3 d-block mb-2"></i>
                                Sin ventas pagadas en este período
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Detalle de participación --}}
            <div class="col-lg-7">
                <div class="card h-100">
                    <div class="card-header bg-white">
                        <h6 class="mb-0"><i class="bi bi-bar-chart-line me-2"></i>Participación por tipo de operación</h6>
                    </div>
                    <div class="card-body">
                        {{-- Contado --}}
                        <div class="mb-4">
                            <div class="d-flex justify-content-between mb-1">
                                <span class="fw-semibold"><i class="bi bi-cash-stack me-1" style="color: #F7A8DC;"></i>Contado</span>
                                <span>${{ number_format($contadoMonto, 0, ',', '.') }} · {{ $estados['contado']['participacion'] ?? 0 }}%</span>
                            </div>
                            <div class="progress" style="height: 14px; background-color: #f3eefb;">
                                <div class="progress-bar" role="progressbar"
                                     style="width: {{ $estados['contado']['participacion'] ?? 0 }}%; background-color: #F7A8DC;"></div>
                            </div>
                            <small class="text-muted">{{ $estados['contado']['cantidad'] ?? 0 }} ventas</small>
                        </div>

                        {{-- Crédito --}}
                        <div class="mb-4">
                            <div class="d-flex justify-content-between mb-1">
                                <span class="fw-semibold"><i class="bi bi-credit-card me-1" style="color: #C9B8F5;"></i>Crédito</span>
                                <span>${{ number_format($creditoMonto, 0, ',', '.') }} · {{ $estados['credito']['participacion'] ?? 0 }}%</span>
                            </div>
                            <div class="progress" style="height: 14px; background-color: #f3eefb;">
                                <div class="progress-bar" role="progressbar"
                                     style="width: {{ $estados['credito']['participacion'] ?? 0 }}%; background-color: #C9B8F5;"></div>
                            </div>
                            <small class="text-muted">{{ $estados['credito']['cantidad'] ?? 0 }} ventas</small>
                        </div>

                        <hr>
                        <div class="d-flex justify-content-between">
                            <span class="fw-bold">Total pagado</span>
                            <span class="fw-bold">${{ number_format($totalPagado, 0, ',', '.') }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @push('scripts')
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0"></script>
        <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.2.0"></script>
        <script>
            (function () {
                const canvas = document.getElementById('chartContadoCredito');
                if (!canvas || typeof Chart === 'undefined') return;

                if (typeof ChartDataLabels !== 'undefined') {
                    Chart.register(ChartDataLabels);
                }

                // Paleta pastel de marca Miracle
                const COLOR_CONTADO = '#F7A8DC'; // rosa pastel
                const COLOR_CREDITO = '#C9B8F5'; // lila pastel

                new Chart(canvas.getContext('2d'), {
                    type: 'doughnut',
                    data: {
                        labels: ['Contado', 'Crédito'],
                        datasets: [{
                            data: [{{ $contadoMonto }}, {{ $creditoMonto }}],
                            backgroundColor: [COLOR_CONTADO, COLOR_CREDITO],
                            borderWidth: 0,
                            hoverOffset: 6,
                        }]
                    },
                    options: {
                        responsive: true,
                        cutout: '72%',
                        layout: { padding: 6 },
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: {
                                    usePointStyle: true,
                                    pointStyle: 'circle',
                                    padding: 16,
                                    font: { size: 12 },
                                    color: '#382E65',
                                    generateLabels: (chart) => {
                                        const ds = chart.data.datasets[0];
                                        const total = ds.data.reduce((a, b) => a + Number(b), 0);
                                        return chart.data.labels.map((label, i) => {
                                            const pct = total ? Math.round((ds.data[i] / total) * 100) : 0;
                                            return {
                                                text: `${label} (${pct}%)`,
                                                fillStyle: ds.backgroundColor[i],
                                                strokeStyle: ds.backgroundColor[i],
                                                pointStyle: 'circle',
                                                index: i,
                                            };
                                        });
                                    }
                                }
                            },
                            tooltip: {
                                callbacks: {
                                    label: (ctx) => ' ' + ctx.label + ': $' + new Intl.NumberFormat('es-CO').format(ctx.parsed)
                                }
                            },
                            datalabels: {
                                color: '#5b4b8a',
                                font: { weight: '600', size: 13 },
                                formatter: (value, ctx) => {
                                    const total = ctx.chart.data.datasets[0].data.reduce((a, b) => a + Number(b), 0);
                                    if (!total || value === 0) return '';
                                    const pct = Math.round((value / total) * 100);
                                    return pct >= 8 ? pct + '%' : '';
                                }
                            }
                        }
                    }
                });
            })();
        </script>
    @endpush
</x-app-layout>
