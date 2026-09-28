<x-app-layout>
    <x-slot name="header">
        {{ __('Ventas por Tipo de Pago') }}
    </x-slot>

    <div class="container-fluid py-4">

        @include('vendedor.partials._filtros', [
            'ruta' => 'vendedor.contado-credito',
            'titulo' => 'Mis ventas por tipo de pago',
            'icono' => 'cash-coin',
            'exportar' => true,
        ])

        @php
            // Categorías de pago (mismo criterio que el badge del sistema)
            $cats = [
                ['key' => 'contado',   'label' => 'Contado (pagado)', 'color' => '#F7A8DC', 'icon' => 'bi-cash-stack'],
                ['key' => 'pendiente', 'label' => 'Pendiente',        'color' => '#F6C177', 'icon' => 'bi-hourglass-split'],
                ['key' => 'credito',   'label' => 'Crédito',          'color' => '#C9B8F5', 'icon' => 'bi-credit-card'],
                ['key' => 'parcial',   'label' => 'Parcial',          'color' => '#9ED8D6', 'icon' => 'bi-pie-chart-half'],
                ['key' => 'mixto',     'label' => 'Mixto',            'color' => '#B0A4E3', 'icon' => 'bi-shuffle'],
            ];
            $totalMonto = $estados['total']['monto'] ?? 0;
            $catsActivas = array_values(array_filter($cats, fn($c) => ($estados[$c['key']]['monto'] ?? 0) > 0));
        @endphp

        {{-- Tarjetas principales --}}
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <x-card-metric title="Contado (pagado)"
                    :value="'$' . number_format($estados['contado']['monto'] ?? 0, 0, ',', '.')"
                    icon="bi-cash-stack" color="pink"
                    :subtitle="($estados['contado']['cantidad'] ?? 0) . ' ventas · ' . ($estados['contado']['participacion'] ?? 0) . '%'" />
            </div>
            <div class="col-md-4">
                <x-card-metric title="Pendiente de pago"
                    :value="'$' . number_format($estados['pendiente']['monto'] ?? 0, 0, ',', '.')"
                    icon="bi-hourglass-split" color="gold"
                    :subtitle="($estados['pendiente']['cantidad'] ?? 0) . ' ventas · ' . ($estados['pendiente']['participacion'] ?? 0) . '%'" />
            </div>
            <div class="col-md-4">
                <x-card-metric title="Crédito"
                    :value="'$' . number_format($estados['credito']['monto'] ?? 0, 0, ',', '.')"
                    icon="bi-credit-card" color="lilac"
                    :subtitle="($estados['credito']['cantidad'] ?? 0) . ' ventas · ' . ($estados['credito']['participacion'] ?? 0) . '%'" />
            </div>
        </div>

        <div class="row g-3">
            {{-- Dona --}}
            <div class="col-lg-5">
                <div class="card h-100">
                    <div class="card-header bg-white"><h6 class="mb-0"><i class="bi bi-pie-chart me-2"></i>Distribución</h6></div>
                    <div class="card-body d-flex align-items-center justify-content-center">
                        @if($totalMonto > 0)
                            <div style="max-width: 260px; width: 100%; margin: 0 auto;"><canvas id="chartPagos"></canvas></div>
                        @else
                            <div class="text-center text-muted py-4"><i class="bi bi-pie-chart fs-3 d-block mb-2"></i>Sin ventas en este período</div>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Participación por tipo --}}
            <div class="col-lg-7">
                <div class="card h-100">
                    <div class="card-header bg-white"><h6 class="mb-0"><i class="bi bi-bar-chart-line me-2"></i>Participación por tipo de pago</h6></div>
                    <div class="card-body">
                        @foreach($cats as $cat)
                            @php $d = $estados[$cat['key']] ?? ['monto' => 0, 'cantidad' => 0, 'participacion' => 0]; @endphp
                            @if(($d['monto'] ?? 0) > 0 || in_array($cat['key'], ['contado','pendiente','credito']))
                                <div class="mb-3">
                                    <div class="d-flex justify-content-between mb-1">
                                        <span class="fw-semibold"><i class="bi {{ $cat['icon'] }} me-1" style="color: {{ $cat['color'] }};"></i>{{ $cat['label'] }}</span>
                                        <span>${{ number_format($d['monto'] ?? 0, 0, ',', '.') }} · {{ $d['participacion'] ?? 0 }}%</span>
                                    </div>
                                    <div class="progress" style="height: 13px; background-color: #f3eefb;">
                                        <div class="progress-bar" role="progressbar"
                                             style="width: {{ $d['participacion'] ?? 0 }}%; background-color: {{ $cat['color'] }};"></div>
                                    </div>
                                    <small class="text-muted">{{ $d['cantidad'] ?? 0 }} ventas</small>
                                </div>
                            @endif
                        @endforeach

                        <hr>
                        <div class="d-flex justify-content-between">
                            <span class="fw-bold">Total ventas</span>
                            <span class="fw-bold">${{ number_format($totalMonto, 0, ',', '.') }}</span>
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
                const canvas = document.getElementById('chartPagos');
                if (!canvas || typeof Chart === 'undefined') return;
                if (typeof ChartDataLabels !== 'undefined') Chart.register(ChartDataLabels);

                const cats = @json($catsActivas);
                const montos = {!! json_encode(collect($catsActivas)->map(fn($c) => $estados[$c['key']]['monto'] ?? 0)->all()) !!};

                new Chart(canvas.getContext('2d'), {
                    type: 'doughnut',
                    data: {
                        labels: cats.map(c => c.label),
                        datasets: [{
                            data: montos,
                            backgroundColor: cats.map(c => c.color),
                            borderWidth: 0, hoverOffset: 6,
                        }]
                    },
                    options: {
                        responsive: true, cutout: '70%',
                        plugins: {
                            legend: { position: 'bottom', labels: { usePointStyle: true, pointStyle: 'circle', padding: 12, font: { size: 11 }, color: '#382E65' } },
                            tooltip: { callbacks: { label: (ctx) => ' ' + ctx.label + ': $' + new Intl.NumberFormat('es-CO').format(ctx.parsed) } },
                            datalabels: {
                                color: '#5b4b8a', font: { weight: '600', size: 12 },
                                formatter: (value, ctx) => {
                                    const t = ctx.chart.data.datasets[0].data.reduce((a, b) => a + Number(b), 0);
                                    if (!t || value === 0) return '';
                                    const p = Math.round(value / t * 100);
                                    return p >= 8 ? p + '%' : '';
                                }
                            }
                        }
                    }
                });
            })();
        </script>
    @endpush
</x-app-layout>
