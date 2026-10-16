{{-- Envío de cotizaciones a la DIAN: revisión previa, envío uno a uno o por lote, y estado --}}
<div class="modal fade" id="modalRevisionDian" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-send me-2"></i>Revisar antes de enviar a la DIAN</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body" id="revisionDianContenido"></div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn btn-primary" id="btnConfirmarDian" disabled>
          <i class="bi bi-send me-1"></i>Enviar a la DIAN
        </button>
      </div>
    </div>
  </div>
</div>

<script>
  (function () {
    const csrf = '{{ csrf_token() }}';
    const pesos = v => '$' + Number(v || 0).toLocaleString('es-CO', { maximumFractionDigits: 2 });
    const esc = s => $('<div>').text(s ?? '').html();
    let enviando = false;

    const recargar = () => {
      if ($.fn.dataTable.isDataTable('#solicitudes-table')) {
        $('#solicitudes-table').DataTable().ajax.reload(null, false);
      }
      actualizarSeleccionDian();
    };

    window.revisarEnvioDian = function (id) {
      const $btn = $('#btnConfirmarDian').prop('disabled', true).data('id', id);
      $('#revisionDianContenido').html('<div class="text-center py-4"><div class="spinner-border"></div></div>');
      bootstrap.Modal.getOrCreateInstance(document.getElementById('modalRevisionDian')).show();

      $.get(`/solicitudes/${id}/siigo/revision`).done(r => {
        let h = '';
        r.errores.forEach(e => h += `<div class="alert alert-danger py-2 mb-2"><i class="bi bi-x-circle me-1"></i>${esc(e)}</div>`);
        r.avisos.forEach(a => h += `<div class="alert alert-warning py-2 mb-2"><i class="bi bi-exclamation-triangle me-1"></i>${esc(a)}</div>`);

        h += `<h6 class="mt-3">Cliente</h6>
              <p class="mb-2">${esc(r.cliente.nombre)} · ${esc(r.cliente.identificacion || 'sin identificación')}
              · ${esc(r.cliente.email || 'sin correo')} ${r.cliente.en_siigo ? '<span class="badge bg-success">en SIIGO</span>' : '<span class="badge bg-secondary">se creará en SIIGO</span>'}</p>`;

        if (r.lineas.length) {
          h += `<h6>Líneas</h6><div class="table-responsive"><table class="table table-sm align-middle">
                <thead><tr><th>Código</th><th>Descripción</th><th class="text-end">Cant.</th><th class="text-end">Precio sin IVA</th><th class="text-end">Desc. %</th><th class="text-center">IVA</th></tr></thead><tbody>`;
          r.lineas.forEach(l => h += `<tr><td><code>${esc(l.code)}</code></td><td>${esc(l.description)}</td>
                <td class="text-end">${l.quantity}</td><td class="text-end">${pesos(l.price)}</td>
                <td class="text-end">${l.discount ?? ''}</td><td class="text-center">${l.taxes ? 'Sí' : 'No'}</td></tr>`);
          h += '</tbody></table></div>';
        }

        if (r.totales && r.totales.total !== undefined) {
          h += `<div class="row"><div class="col-md-6"><h6>Pagos</h6><ul class="mb-2">`;
          r.pagos.forEach(p => h += `<li>Medio ${p.id}: ${pesos(p.value)} · vence ${esc(p.due_date)}</li>`);
          h += `</ul></div><div class="col-md-6"><table class="table table-sm mb-0">
                <tr><td>Subtotal</td><td class="text-end">${pesos(r.totales.subtotal)}</td></tr>
                <tr><td>Descuento</td><td class="text-end">-${pesos(r.totales.descuento)}</td></tr>
                <tr><td>IVA</td><td class="text-end">${pesos(r.totales.iva)}</td></tr>
                <tr class="fw-bold"><td>Total factura</td><td class="text-end">${pesos(r.totales.total)}</td></tr>
                </table></div></div>`;
        }

        if (r.observaciones) {
          h += `<h6 class="mt-3">Observaciones de la factura</h6>
                <div class="alert alert-warning py-2 mb-0" style="white-space: pre-line">${esc(r.observaciones)}</div>`;
        }

        $('#revisionDianContenido').html(h);
        $btn.prop('disabled', r.errores.length > 0);
      }).fail(xhr => {
        $('#revisionDianContenido').html(`<div class="alert alert-danger">${esc(xhr.responseJSON?.message || 'No se pudo revisar la cotización.')}</div>`);
      });
    };

    $(document).on('click', '#btnConfirmarDian', function () {
      if (enviando) return;
      enviando = true;
      const $btn = $(this).prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Enviando…');
      const id = $btn.data('id');

      $.post(`/solicitudes/${id}/siigo/enviar`, { _token: csrf }).done(r => {
        bootstrap.Modal.getInstance(document.getElementById('modalRevisionDian')).hide();
        Swal.fire(r.success ? 'Enviada' : 'No se pudo enviar', r.mensaje, r.success ? 'success' : 'error');
        recargar();
        if ($('#modalDetalle').hasClass('show')) verDetalle(id);
      }).fail(xhr => {
        Swal.fire('Error', xhr.responseJSON?.message || xhr.responseJSON?.mensaje || 'Error al enviar a la DIAN.', 'error');
      }).always(() => {
        enviando = false;
        $btn.html('<i class="bi bi-send me-1"></i>Enviar a la DIAN');
      });
    });

    window.consultarEstadoDian = function (id, boton) {
      $(boton).prop('disabled', true);
      $.post(`/solicitudes/${id}/siigo/estado`, { _token: csrf }).done(r => {
        Swal.fire('Estado en la DIAN', r.mensaje + (r.numero ? ` (${r.numero})` : ''), 'info');
        recargar();
        if ($('#modalDetalle').hasClass('show')) verDetalle(id);
      }).fail(xhr => {
        Swal.fire('Error', xhr.responseJSON?.message || 'No se pudo consultar el estado.', 'error');
      }).always(() => $(boton).prop('disabled', false));
    };

    // Envío selectivo: las cotizaciones marcadas en el listado
    window.actualizarSeleccionDian = function () {
      const n = $('.chk-dian:checked').length;
      $('#btnEnviarLoteDian').prop('disabled', n === 0).find('.js-n').text(n);
    };
    $(document).on('change', '.chk-dian', actualizarSeleccionDian);
    $(document).on('draw.dt', '#solicitudes-table', actualizarSeleccionDian);

    $(document).on('click', '#btnEnviarLoteDian', function () {
      const ids = $('.chk-dian:checked').map((_, c) => c.value).get();
      if (!ids.length || enviando) return;

      Swal.fire({
        title: `¿Enviar ${ids.length} cotización(es) a la DIAN?`,
        text: 'Cada una se revisa antes de enviarse; las que tengan problemas se saltan. Una factura enviada no se puede deshacer sin nota crédito.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Enviar',
        cancelButtonText: 'Cancelar',
      }).then(res => {
        if (!res.isConfirmed) return;
        enviando = true;
        const $btn = $('#btnEnviarLoteDian').prop('disabled', true);
        Swal.fire({ title: 'Enviando a la DIAN…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

        $.post('/solicitudes/siigo/enviar-lote', { _token: csrf, ids }).done(r => {
          const lista = r.resultados.map(x =>
            `<li class="${x.success ? 'text-success' : 'text-danger'}"><strong>${esc(x.numero)}</strong>: ${esc(x.mensaje)}</li>`).join('');
          Swal.fire({
            title: `${r.enviadas} de ${r.resultados.length} enviadas`,
            html: `<ul class="text-start small">${lista}</ul>`,
            icon: r.enviadas === r.resultados.length ? 'success' : 'warning',
          });
          recargar();
        }).fail(xhr => {
          Swal.fire('Error', xhr.responseJSON?.message || 'Error al enviar el lote.', 'error');
        }).always(() => {
          enviando = false;
          $btn.prop('disabled', false);
        });
      });
    });
  })();
</script>
