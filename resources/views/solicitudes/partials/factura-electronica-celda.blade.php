{{-- Columna «Factura» del listado de cotizaciones --}}
@php($factura = $solicitud->facturaSiigo)
@if($factura)
  <div class="d-flex align-items-center gap-1 flex-nowrap">
    {!! $factura->estado_badge !!}
    @if($factura->numero_factura)
      <small class="fw-semibold">{{ $factura->numero_factura }}</small>
    @endif
    @if($factura->siigo_invoice_id)
      <a href="{{ route('solicitudes.siigo.pdf', $solicitud) }}" class="btn btn-sm btn-link p-0" title="Descargar PDF">
        <i class="bi bi-file-earmark-pdf text-danger"></i>
      </a>
      <a href="{{ route('solicitudes.siigo.xml', $solicitud) }}" class="btn btn-sm btn-link p-0" title="Descargar XML">
        <i class="bi bi-filetype-xml text-secondary"></i>
      </a>
      @if($factura->estado_dian !== 'aprobada')
        <button type="button" class="btn btn-sm btn-link p-0" title="Consultar estado en la DIAN" onclick="consultarEstadoDian({{ $solicitud->id }}, this)">
          <i class="bi bi-arrow-repeat text-info"></i>
        </button>
      @endif
    @endif
    @if($puedeFacturar && $solicitud->puedeFacturarSiigo())
      <button type="button" class="btn btn-sm btn-link p-0" title="Reintentar envío a la DIAN" onclick="revisarEnvioDian({{ $solicitud->id }})">
        <i class="bi bi-send text-warning"></i>
      </button>
    @endif
  </div>
@elseif($puedeFacturar && $solicitud->puedeFacturarSiigo())
  <div class="d-flex align-items-center gap-1 flex-nowrap">
    <input type="checkbox" class="form-check-input chk-dian m-0" value="{{ $solicitud->id }}" title="Seleccionar para enviar a la DIAN">
    <button type="button" class="btn btn-sm btn-outline-primary py-0" onclick="revisarEnvioDian({{ $solicitud->id }})">
      <i class="bi bi-send me-1"></i>DIAN
    </button>
  </div>
@elseif($solicitud->puedeFacturarSiigo())
  <span class="badge bg-light text-dark border">Por facturar</span>
@else
  -
@endif
