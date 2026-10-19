{{-- Sección «Factura electrónica» del detalle de la cotización --}}
@php($factura = $solicitud->facturaSiigo)
<div class="col-12 mt-3">
  <hr>
  <h6><i class="bi bi-receipt me-1"></i> Factura electrónica (DIAN)</h6>

  @if($factura)
    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
      {!! $factura->estado_badge !!}
      @if($factura->numero_factura)
        <strong>{{ $factura->numero_factura }}</strong>
      @endif
      @if($factura->fecha_emision)
        <small class="text-muted">{{ $factura->fecha_emision->format('d/m/Y') }}</small>
      @endif
      <small class="text-muted">· Total ${{ number_format((float) $factura->total, 0, ',', '.') }}</small>
    </div>
    @if($factura->cufe)
      <div class="small text-muted text-break mb-2">CUFE: {{ $factura->cufe }}</div>
    @endif
    @if($factura->errores && $factura->estado_dian !== 'aprobada')
      <div class="alert alert-danger py-2 small mb-2">{{ $factura->errores }}</div>
    @endif
    <div class="d-flex flex-wrap gap-2">
      @if($factura->siigo_invoice_id)
        <a href="{{ route('solicitudes.siigo.pdf', $solicitud) }}" class="btn btn-sm btn-outline-danger">
          <i class="bi bi-file-earmark-pdf me-1"></i>PDF
        </a>
        <a href="{{ route('solicitudes.siigo.xml', $solicitud) }}" class="btn btn-sm btn-outline-secondary">
          <i class="bi bi-filetype-xml me-1"></i>XML
        </a>
        @if($factura->estado_dian !== 'aprobada')
          <button type="button" class="btn btn-sm btn-outline-info" onclick="consultarEstadoDian({{ $solicitud->id }}, this)">
            <i class="bi bi-arrow-repeat me-1"></i>Consultar estado
          </button>
        @endif
      @endif
      @if($puedeFacturar && $solicitud->puedeFacturarSiigo())
        <button type="button" class="btn btn-sm btn-warning" onclick="revisarEnvioDian({{ $solicitud->id }})">
          <i class="bi bi-send me-1"></i>Reintentar envío a la DIAN
        </button>
      @endif
    </div>
  @elseif($solicitud->puedeFacturarSiigo())
    @if($puedeFacturar)
      <div class="alert alert-info d-flex align-items-center justify-content-between mb-0">
        <span><i class="bi bi-info-circle me-2"></i>Esta cotización aún no tiene factura electrónica.</span>
        <button type="button" class="btn btn-primary btn-sm" onclick="revisarEnvioDian({{ $solicitud->id }})">
          <i class="bi bi-send me-1"></i>Enviar a la DIAN
        </button>
      </div>
    @else
      <p class="text-muted mb-0">Pendiente de facturar. Solo las personas autorizadas pueden enviarla a la DIAN.</p>
    @endif
  @else
    <p class="text-muted mb-0">La cotización está rechazada: no se factura.</p>
  @endif
</div>
