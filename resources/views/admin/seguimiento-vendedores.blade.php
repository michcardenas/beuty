<x-app-layout>
    <x-slot name="header">
        {{ __('Seguimiento de Vendedores') }}
    </x-slot>

    @php
        $cats = [
            ['key' => 'contado',   'label' => 'Contado',   'color' => '#F7A8DC'],
            ['key' => 'pendiente', 'label' => 'Pendiente', 'color' => '#F6C177'],
            ['key' => 'credito',   'label' => 'Crédito',   'color' => '#C9B8F5'],
            ['key' => 'parcial',   'label' => 'Parcial',   'color' => '#9ED8D6'],
            ['key' => 'mixto',     'label' => 'Mixto',     'color' => '#B0A4E3'],
        ];
        $totalMonto = $estados['total']['monto'] ?? 0;
        $catsActivas = array_values(array_filter($cats, fn($c) => ($estados[$c['key']]['monto'] ?? 0) > 0));
    @endphp

    <div class="container-fluid py-4">

        {{-- Barra de control --}}
        <div class="card mb-4">
            <div class="card-body">
                <div class="row g-3 align-items-end">
                    <div class="col-lg-4">
                        <label class="form-label small text-muted mb-1 fw-semibold"><i class="bi bi-person-badge me-1"></i>Vendedor</label>
                        <form method="GET" action="{{ route('admin.vendedores.index') }}" id="formVendedor">
                            <input type="hidden" name="periodo" value="{{ $periodo }}">
                            @if(request('fecha_inicio'))<input type="hidden" name="fecha_inicio" value="{{ request('fecha_inicio') }}">@endif
                            @if(request('fecha_fin'))<input type="hidden" name="fecha_fin" value="{{ request('fecha_fin') }}">@endif
                            <select name="vendedor_id" class="form-select" onchange="document.getElementById('formVendedor').submit()">
                                <option value="todos" @selected(!$vendedorId)>👥 Todos los vendedores</option>
                                @foreach($vendedores as $v)
                                    <option value="{{ $v->id }}" @selected($v->id == $vendedorId)>{{ $v->name }}</option>
                                @endforeach
                            </select>
                        </form>
                    </div>
                    <div class="col-lg-8">
                        <div class="d-flex flex-wrap gap-2 justify-content-lg-end align-items-center">
                            <div class="btn-group" role="group">
                                @foreach(['hoy' => 'Hoy', 'semana' => 'Semana', 'mes' => 'Mes', 'año' => 'Año'] as $key => $label)
                                    <a href="{{ route('admin.vendedores.index', ['vendedor_id' => $vendedorId ?? 'todos', 'periodo' => $key]) }}"
                                       class="btn btn-sm {{ $periodo === $key ? 'btn-primary' : 'btn-outline-primary' }}">{{ $label }}</a>
                                @endforeach
                            </div>
                            <form method="GET" action="{{ route('admin.vendedores.index') }}" class="d-flex gap-2 align-items-center">
                                <input type="hidden" name="vendedor_id" value="{{ $vendedorId ?? 'todos' }}">
                                <input type="date" name="fecha_inicio" class="form-control form-control-sm" value="{{ request('fecha_inicio') }}" max="{{ now()->format('Y-m-d') }}">
                                <span class="text-muted small">a</span>
                                <input type="date" name="fecha_fin" class="form-control form-control-sm" value="{{ request('fecha_fin') }}" max="{{ now()->format('Y-m-d') }}">
                                <button type="submit" class="btn btn-sm btn-outline-secondary" title="Filtrar"><i class="bi bi-funnel"></i></button>
                            </form>
                            <a href="{{ route('admin.vendedores.exportar', ['vendedor_id' => $vendedorId ?? 'todos', 'periodo' => $periodo, 'fecha_inicio' => request('fecha_inicio'), 'fecha_fin' => request('fecha_fin')]) }}"
                               class="btn btn-sm btn-success" title="Exportar a Excel"><i class="bi bi-file-earmark-excel"></i> Excel</a>
                        </div>
                    </div>
                </div>
                <div class="mt-3">
                    <span class="badge px-3 py-2" style="background: var(--miracle-pink-light); color: var(--miracle-dark); font-size: .85rem;">
                        <i class="bi bi-person-circle me-1"></i>{{ $vendedorSel->name ?? 'Todos los vendedores' }}
                    </span>
                    <small class="text-muted ms-2">
                        {{ \Carbon\Carbon::parse($fechaInicio)->isoFormat('D MMM YYYY') }} &ndash; {{ \Carbon\Carbon::parse($fechaFin)->isoFormat('D MMM YYYY') }}
                    </small>
                </div>
            </div>
        </div>

        {{-- KPIs --}}
        <div class="row g-3 mb-4">
            <div class="col-md-6 col-lg-3">
                <x-card-metric title="Total Ventas" :value="'$' . number_format($resumen['total_ventas'] ?? 0, 0, ',', '.')"
                    icon="bi-currency-dollar" color="success" :subtitle="($resumen['total_pedidos'] ?? 0) . ' pedidos'"
                    :trend="$variacion['ventas']['tendencia'] ?? null" :trendValue="($variacion['ventas']['valor'] ?? 0) . '% vs ant.'" />
            </div>
            <div class="col-md-6 col-lg-3">
                <x-card-metric title="Pedidos" :value="($resumen['total_pedidos'] ?? 0)"
                    icon="bi-bag-check" color="lilac" subtitle="Cotizaciones aplicadas"
                    :trend="$variacion['pedidos']['tendencia'] ?? null" :trendValue="($variacion['pedidos']['valor'] ?? 0) . '%'" />
            </div>
            <div class="col-md-6 col-lg-3">
                <x-card-metric title="Ticket Promedio" :value="'$' . number_format($resumen['ticket_promedio'] ?? 0, 0, ',', '.')"
                    icon="bi-receipt" color="gold" subtitle="Por pedido"
                    :trend="$variacion['ticket']['tendencia'] ?? null" :trendValue="($variacion['ticket']['valor'] ?? 0) . '%'" />
            </div>
            <div class="col-md-6 col-lg-3">
                <x-card-metric title="Tasa de Conversión" :value="($estados['tasa_conversion'] ?? 0) . '%'"
                    icon="bi-graph-up-arrow" color="pink" subtitle="Aplicadas / Total" />
            </div>
        </div>

        {{-- Tendencia + Desglose de pago --}}
        <div class="row g-3 mb-4">
            <div class="col-lg-8">
                <div class="card h-100">
                    <div class="card-header bg-white"><h6 class="mb-0"><i class="bi bi-bar-chart me-2"></i>Ventas — últimos 30 días</h6></div>
                    <div class="card-body"><canvas id="chartTendenciaAdmin" height="90"></canvas></div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="card h-100">
                    <div class="card-header bg-white"><h6 class="mb-0"><i class="bi bi-pie-chart me-2"></i>Por tipo de pago</h6></div>
                    <div class="card-body">
                        @if($totalMonto > 0)
                            <div style="max-width: 200px; margin: 0 auto;"><canvas id="chartDonaAdmin"></canvas></div>
                        @else
                            <p class="text-center text-muted py-3 mb-0"><i class="bi bi-pie-chart d-block fs-4 mb-2"></i>Sin ventas</p>
                        @endif
                        <div class="mt-3 small">
                            @foreach($cats as $cat)
                                @php $d = $estados[$cat['key']] ?? ['monto' => 0]; @endphp
                                @if(($d['monto'] ?? 0) > 0 || in_array($cat['key'], ['contado','pendiente','credito']))
                                    <div class="d-flex justify-content-between mb-1">
                                        <span><i class="bi bi-circle-fill me-1" style="color:{{ $cat['color'] }};"></i>{{ $cat['label'] }}</span>
                                        <strong>${{ number_format($d['monto'] ?? 0, 0, ',', '.') }}</strong>
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Top clientes + Pendientes --}}
        <div class="row g-3">
            <div class="col-lg-7">
                <div class="card h-100">
                    <div class="card-header bg-white"><h6 class="mb-0"><i class="bi bi-people me-2"></i>Top clientes por ventas</h6></div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover mb-0 align-middle">
                                <thead class="table-light">
                                    <tr><th>#</th><th>Cliente</th><th class="text-center">Pedidos</th><th class="text-end">Total</th><th class="text-center">Últ. compra</th></tr>
                                </thead>
                                <tbody>
                                    @forelse($clientes as $i => $c)
                                        <tr>
                                            <td>{{ $i + 1 }}</td>
                                            <td>{{ $c['cliente'] }}</td>
                                            <td class="text-center"><span class="badge bg-secondary">{{ $c['pedidos'] }}</span></td>
                                            <td class="text-end fw-semibold">${{ number_format($c['total_facturado'], 0, ',', '.') }}</td>
                                            <td class="text-center">{{ $c['ultima_compra'] ? $c['ultima_compra']->isoFormat('D MMM YYYY') : '—' }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="5" class="text-center text-muted py-4">Sin ventas por cliente en este período</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="card h-100">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <h6 class="mb-0"><i class="bi bi-cash-coin me-2 text-warning"></i>Pagos por cobrar</h6>
                        <span class="badge bg-warning text-dark">{{ $seguimiento['por_cobrar']->count() }}</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive" style="max-height: 340px; overflow-y: auto;">
                            <table class="table table-sm table-hover mb-0 align-middle">
                                <thead class="table-light"><tr><th>N.º</th><th>Cliente</th><th class="text-end">Monto</th></tr></thead>
                                <tbody>
                                    @forelse($seguimiento['por_cobrar'] as $p)
                                        <tr>
                                            <td class="small">{{ $p->numero_solicitud }}</td>
                                            <td class="small">{{ optional($p->cliente)->nombre_contacto ?? 'N/A' }}</td>
                                            <td class="text-end">${{ number_format($p->monto_total, 0, ',', '.') }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="3" class="text-center text-muted py-4"><i class="bi bi-check2-circle text-success d-block fs-4 mb-2"></i>Nada por cobrar</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Cotizaciones sin aplicar --}}
        <div class="row g-3 mt-1">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <h6 class="mb-0"><i class="bi bi-hourglass-split me-2 text-secondary"></i>Cotizaciones sin aplicar</h6>
                        <span class="badge bg-secondary">{{ $seguimiento['pendientes']->count() }}</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive" style="max-height: 300px; overflow-y: auto;">
                            <table class="table table-sm table-hover mb-0 align-middle">
                                <thead class="table-light"><tr><th>N.º</th><th>Cliente</th><th class="text-end">Monto</th><th class="text-center">Creada</th></tr></thead>
                                <tbody>
                                    @forelse($seguimiento['pendientes'] as $p)
                                        <tr>
                                            <td class="small">{{ $p->numero_solicitud }}</td>
                                            <td class="small">{{ optional($p->cliente)->nombre_contacto ?? 'N/A' }}</td>
                                            <td class="text-end">${{ number_format($p->monto_total, 0, ',', '.') }}</td>
                                            <td class="text-center small">{{ $p->created_at->isoFormat('D MMM YYYY') }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="4" class="text-center text-muted py-4"><i class="bi bi-check2-circle text-success d-block fs-4 mb-2"></i>Todas las cotizaciones están aplicadas</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
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
                if (typeof Chart === 'undefined') return;
                if (typeof ChartDataLabels !== 'undefined') Chart.register(ChartDataLabels);

                const ct = document.getElementById('chartTendenciaAdmin');
                if (ct) {
                    const datos = @json($tendencia);
                    new Chart(ct.getContext('2d'), {
                        type: 'line',
                        data: { labels: datos.map(d => d.fecha_corta), datasets: [{
                            data: datos.map(d => Number(d.monto)), borderColor: '#FF84D5',
                            backgroundColor: 'rgba(255,132,213,0.12)', fill: true, tension: 0.35, pointRadius: 2 }] },
                        options: { responsive: true, plugins: { legend: { display: false }, datalabels: { display: false } },
                            scales: { y: { beginAtZero: true, ticks: { callback: v => '$' + new Intl.NumberFormat('es-CO').format(v) } } } }
                    });
                }

                const cd = document.getElementById('chartDonaAdmin');
                if (cd) {
                    const cats = @json($catsActivas);
                    const montos = {!! json_encode(collect($catsActivas)->map(fn($c) => $estados[$c['key']]['monto'] ?? 0)->all()) !!};
                    new Chart(cd.getContext('2d'), {
                        type: 'doughnut',
                        data: { labels: cats.map(c => c.label), datasets: [{ data: montos, backgroundColor: cats.map(c => c.color), borderWidth: 0, hoverOffset: 6 }] },
                        options: { responsive: true, cutout: '70%',
                            plugins: {
                                legend: { display: false },
                                datalabels: { color: '#5b4b8a', font: { weight: '600', size: 11 },
                                    formatter: (value, ctx) => { const t = ctx.chart.data.datasets[0].data.reduce((a,b)=>a+Number(b),0);
                                        if (!t || value === 0) return ''; const p = Math.round(value/t*100); return p >= 8 ? p + '%' : ''; } }
                            } }
                    });
                }
            })();
        </script>
    @endpush
</x-app-layout>
