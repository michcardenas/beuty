@php($ruta = $ruta ?? 'vendedor.panel')
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-body py-3">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                    <div>
                        <h5 class="mb-0">
                            <i class="bi bi-{{ $icono ?? 'person-badge' }} me-2"></i>
                            {{ $titulo ?? 'Mi gestión comercial' }}
                        </h5>
                        <small class="text-muted">
                            {{ \Carbon\Carbon::parse($fechaInicio)->isoFormat('D MMM YYYY') }}
                            &ndash;
                            {{ \Carbon\Carbon::parse($fechaFin)->isoFormat('D MMM YYYY') }}
                        </small>
                    </div>

                    <div class="d-flex flex-wrap gap-2 align-items-center">
                        <div class="btn-group" role="group">
                            @foreach(['hoy' => 'Hoy', 'semana' => 'Semana', 'mes' => 'Mes', 'año' => 'Año'] as $key => $label)
                                <a href="{{ route($ruta, ['periodo' => $key]) }}"
                                   class="btn btn-sm {{ $periodo === $key ? 'btn-primary' : 'btn-outline-primary' }}">
                                    {{ $label }}
                                </a>
                            @endforeach
                        </div>

                        <form method="GET" action="{{ route($ruta) }}" class="d-flex gap-2 align-items-center">
                            <input type="date" name="fecha_inicio" class="form-control form-control-sm"
                                   value="{{ request('fecha_inicio') }}" max="{{ now()->format('Y-m-d') }}">
                            <span class="text-muted small">a</span>
                            <input type="date" name="fecha_fin" class="form-control form-control-sm"
                                   value="{{ request('fecha_fin') }}" max="{{ now()->format('Y-m-d') }}">
                            <button type="submit" class="btn btn-sm btn-outline-secondary" title="Filtrar por rango">
                                <i class="bi bi-funnel"></i>
                            </button>
                        </form>

                        @if(($exportar ?? false))
                            <a href="{{ route('vendedor.exportar', request()->only('periodo', 'fecha_inicio', 'fecha_fin')) }}"
                               class="btn btn-sm btn-success" title="Exportar a Excel">
                                <i class="bi bi-file-earmark-excel"></i> Excel
                            </a>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
