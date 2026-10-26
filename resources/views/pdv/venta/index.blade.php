<x-app-layout>
    @section('title', 'Historial de Ventas')

    @push('styles')
    <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    @endpush

    <div class="container-fluid py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4 class="fw-bold mb-0"><i class="bi bi-list-ul me-2"></i>Historial de Ventas</h4>
            <div>
                <button type="button" class="btn btn-success me-2" onclick="exportarExcel()">
                    <i class="bi bi-file-earmark-excel me-1"></i>Exportar Excel
                </button>
                <a href="{{ route('pdv.ventas.crear') }}" class="btn text-white" style="background: var(--miracle-pink);">
                    <i class="bi bi-cart-plus me-1"></i>Nueva Venta
                </a>
            </div>
        </div>

        <div id="bannerActualizacionEstados" class="alert alert-info py-2 small d-none">
            <span class="spinner-border spinner-border-sm me-2" role="status"></span>
            <span id="bannerActualizacionEstadosText">Actualizando estados de facturas SIIGO pendientes...</span>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <div class="row g-2">
                    <div class="col-md-2">
                        <select id="filtroEstado" class="form-select form-select-sm">
                            <option value="">Todos los estados</option>
                            <option value="completada">Completada</option>
                            <option value="anulada">Anulada</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select id="filtroCaja" class="form-select form-select-sm">
                            <option value="">Todas las cajas</option>
                            @foreach($cajas as $c)
                                <option value="{{ $c->id }}">{{ $c->nombre }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select id="filtroMetodo" class="form-select form-select-sm">
                            <option value="">Todos los métodos</option>
                            <option value="efectivo">Efectivo</option>
                            <option value="transferencia">Transferencia</option>
                            <option value="mixto">Mixto</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <input type="date" id="filtroDesde" class="form-control form-control-sm" placeholder="Desde">
                    </div>
                    <div class="col-md-2">
                        <input type="date" id="filtroHasta" class="form-control form-control-sm" placeholder="Hasta">
                    </div>
                    <div class="col-md-2">
                        <button class="btn btn-sm btn-outline-secondary w-100" onclick="limpiarFiltros()">
                            <i class="bi bi-x-circle me-1"></i>Limpiar
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <table id="tablaVentas" class="table table-hover w-100">
                    <thead class="table-light">
                        <tr>
                            <th>Nro. Venta</th>
                            <th>Caja</th>
                            <th>Cliente</th>
                            <th>Total</th>
                            <th>Método</th>
                            <th>Cajero</th>
                            <th>Estado</th>
                            <th>Factura</th>
                            <th>Fecha</th>
                            <th width="120">Acciones</th>
                        </tr>
                    </thead>
                </table>
            </div>
        </div>
    </div>

    {{-- Detail Modal --}}
    <div class="modal fade" id="modalDetalle" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content border-0 shadow" id="modalDetalleContent"></div>
        </div>
    </div>

    {{-- Devolución Parcial Modal --}}
    <div class="modal fade" id="modalDevolucionParcial" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content border-0 shadow">
                <div class="modal-header bg-warning bg-opacity-10">
                    <h6 class="modal-title fw-bold"><i class="bi bi-arrow-return-left me-2"></i>Devolución Parcial - <span id="devNumeroVenta"></span></h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info py-2 small">
                        <i class="bi bi-info-circle me-1"></i>Seleccione los productos y cantidades a devolver.
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover" id="tablaDevolucion">
                            <thead class="table-light">
                                <tr>
                                    <th width="40"><input type="checkbox" id="devCheckAll" onchange="toggleCheckAll(this)"></th>
                                    <th>Producto</th>
                                    <th class="text-center" width="80">Vendidos</th>
                                    <th class="text-center" width="80">Devueltos</th>
                                    <th class="text-center" width="100">Cantidad</th>
                                    <th class="text-end" width="100">Precio</th>
                                    <th class="text-end" width="100">Subtotal</th>
                                </tr>
                            </thead>
                            <tbody id="devItemsBody"></tbody>
                        </table>
                    </div>
                    <div class="mt-3">
                        <label class="form-label fw-semibold">Motivo de devolución <span class="text-danger">*</span></label>
                        <textarea id="devMotivo" class="form-control" rows="2" placeholder="Ingrese el motivo (mínimo 10 caracteres)..."></textarea>
                    </div>
                </div>
                <div class="modal-footer d-flex justify-content-between">
                    <div>
                        <span class="fw-bold text-danger" id="devTotalLabel">Total a devolver: $0</span>
                    </div>
                    <div>
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                        <button type="button" class="btn btn-warning btn-sm" onclick="confirmarDevolucion()">
                            <i class="bi bi-arrow-return-left me-1"></i>Confirmar Devolución
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @push('scripts')
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script>
        let tabla;
        $(function() {
            tabla = $('#tablaVentas').DataTable({
                processing: true, serverSide: true,
                ajax: {
                    url: '{{ route("pdv.ventas.index") }}',
                    data: function(d) {
                        d.estado = $('#filtroEstado').val();
                        d.caja_id = $('#filtroCaja').val();
                        d.metodo_pago = $('#filtroMetodo').val();
                        d.fecha_desde = $('#filtroDesde').val();
                        d.fecha_hasta = $('#filtroHasta').val();
                    }
                },
                columns: [
                    { data: 'numero_venta', name: 'numero_venta' },
                    { data: 'caja_nombre', name: 'caja_nombre', orderable: false },
                    { data: 'cliente_display', name: 'cliente_display', orderable: false },
                    { data: 'total', name: 'total', render: v => '$' + parseFloat(v).toLocaleString('es-CO', {minimumFractionDigits: 2}) },
                    { data: 'metodo_badge', name: 'metodo_pago', orderable: false },
                    { data: 'usuario_nombre', name: 'usuario_nombre', orderable: false },
                    { data: 'estado_badge', name: 'estado', orderable: false },
                    { data: 'factura_badge', name: 'factura_badge', orderable: false },
                    { data: 'created_at', name: 'created_at' },
                    { data: 'action', orderable: false, searchable: false },
                ],
                language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json' },
                order: [[8, 'desc']],
            });

            $('#filtroEstado, #filtroCaja, #filtroMetodo, #filtroDesde, #filtroHasta').change(() => tabla.ajax.reload());

            actualizarEstadosFacturasPendientes();
        });

        function actualizarEstadosFacturasPendientes() {
            const banner = document.getElementById('bannerActualizacionEstados');
            const bannerText = document.getElementById('bannerActualizacionEstadosText');
            banner.classList.remove('d-none', 'alert-success', 'alert-warning', 'alert-danger');
            banner.classList.add('alert-info');
            bannerText.textContent = 'Actualizando estados de facturas SIIGO pendientes...';

            fetch('{{ route("pdv.ventas.actualizar-estados-pendientes") }}', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                },
            })
            .then(r => r.json())
            .then(data => {
                banner.querySelector('.spinner-border')?.remove();
                if (!data.exito) {
                    banner.classList.replace('alert-info', 'alert-danger');
                    bannerText.textContent = data.mensaje || 'Error al actualizar estados.';
                    return;
                }
                if (data.total === 0) {
                    banner.classList.add('d-none');
                    return;
                }
                banner.classList.replace('alert-info', data.actualizadas > 0 ? 'alert-success' : 'alert-warning');
                bannerText.textContent = `Estados consultados: ${data.total}. Actualizadas: ${data.actualizadas}.` + (data.errores ? ` Errores: ${data.errores}.` : '');
                if (data.actualizadas > 0) {
                    tabla.ajax.reload(null, false);
                }
                setTimeout(() => banner.classList.add('d-none'), 5000);
            })
            .catch(() => {
                banner.querySelector('.spinner-border')?.remove();
                banner.classList.replace('alert-info', 'alert-danger');
                bannerText.textContent = 'Error de conexión al actualizar estados de facturas.';
            });
        }

        function limpiarFiltros() {
            $('#filtroEstado, #filtroCaja, #filtroMetodo').val('');
            $('#filtroDesde, #filtroHasta').val('');
            tabla.ajax.reload();
        }

        function exportarExcel() {
            const params = new URLSearchParams();
            const estado = $('#filtroEstado').val();
            const caja = $('#filtroCaja').val();
            const metodo = $('#filtroMetodo').val();
            const desde = $('#filtroDesde').val();
            const hasta = $('#filtroHasta').val();
            if (estado) params.append('estado', estado);
            if (caja) params.append('caja_id', caja);
            if (metodo) params.append('metodo_pago', metodo);
            if (desde) params.append('fecha_desde', desde);
            if (hasta) params.append('fecha_hasta', hasta);
            const qs = params.toString();
            window.location = "{{ route('pdv.ventas.export.excel') }}" + (qs ? ('?' + qs) : '');
        }

        function verDetalle(id) {
            fetch(`/pdv/ventas/${id}/detalle`).then(r => r.text()).then(html => {
                document.getElementById('modalDetalleContent').innerHTML = html;
                new bootstrap.Modal(document.getElementById('modalDetalle')).show();
            });
        }

        function anularVenta(id) {
            Swal.fire({
                title: 'Anular Venta',
                input: 'textarea',
                inputLabel: 'Motivo de anulación (mínimo 10 caracteres)',
                inputValidator: (v) => { if (!v || v.length < 10) return 'Ingrese un motivo de al menos 10 caracteres'; },
                showCancelButton: true, confirmButtonColor: '#dc3545', confirmButtonText: 'Anular',
            }).then(result => {
                if (result.isConfirmed) {
                    fetch(`/pdv/ventas/${id}/anular`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, 'Accept': 'application/json' },
                        body: JSON.stringify({ motivo_anulacion: result.value }),
                    }).then(r => r.json()).then(data => {
                        let msg = data.mensaje;
                        if (data.nota_credito) {
                            msg += `\nNota Crédito: ${data.nota_credito.numero || 'Pendiente'} (${data.nota_credito.estado})`;
                        }
                        Swal.fire(data.exito ? 'Anulada' : 'Error', msg, data.exito ? 'success' : 'error');
                        tabla.ajax.reload();
                    });
                }
            });
        }

        // ===== Devolución Parcial =====
        let devVentaId = null;
        let devItemsData = [];

        function devolucionParcial(id) {
            devVentaId = id;
            document.getElementById('devItemsBody').innerHTML = '<tr><td colspan="7" class="text-center py-3"><div class="spinner-border spinner-border-sm"></div> Cargando...</td></tr>';
            document.getElementById('devMotivo').value = '';
            document.getElementById('devTotalLabel').textContent = 'Total a devolver: $0';
            document.getElementById('devCheckAll').checked = false;

            new bootstrap.Modal(document.getElementById('modalDevolucionParcial')).show();

            fetch(`/pdv/ventas/${id}/items-devolucion`, { headers: { 'Accept': 'application/json' } })
                .then(r => r.json())
                .then(data => {
                    devItemsData = data.items;
                    document.getElementById('devNumeroVenta').textContent = data.numero_venta;
                    renderDevItems();
                })
                .catch(() => {
                    document.getElementById('devItemsBody').innerHTML = '<tr><td colspan="7" class="text-center text-danger">Error al cargar items</td></tr>';
                });
        }

        function renderDevItems() {
            const tbody = document.getElementById('devItemsBody');
            if (!devItemsData.length) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-3">No hay items disponibles para devolución</td></tr>';
                return;
            }
            tbody.innerHTML = devItemsData.map((item, i) => {
                const nombre = item.variante_nombre
                    ? `${item.producto_nombre} <small class="text-muted">- ${item.variante_nombre}</small>`
                    : item.producto_nombre;
                const precioNeto = item.precio_unitario * (1 - item.descuento_porcentaje / 100);
                return `<tr>
                    <td><input type="checkbox" class="devCheck" data-index="${i}" onchange="actualizarTotalDevolucion()"></td>
                    <td>${nombre}</td>
                    <td class="text-center">${item.cantidad_original}</td>
                    <td class="text-center">${item.cantidad_devuelta > 0 ? '<span class=\'text-danger\'>' + item.cantidad_devuelta + '</span>' : '0'}</td>
                    <td class="text-center">
                        <input type="number" class="form-control form-control-sm text-center devCantidad"
                            data-index="${i}" min="1" max="${item.cantidad_disponible}"
                            value="${item.cantidad_disponible}" style="width:70px;display:inline-block"
                            onchange="actualizarTotalDevolucion()" oninput="actualizarTotalDevolucion()">
                    </td>
                    <td class="text-end">$${precioNeto.toLocaleString('es-CO', {minimumFractionDigits: 2})}</td>
                    <td class="text-end devSubtotal" data-index="${i}">$0</td>
                </tr>`;
            }).join('');
            actualizarTotalDevolucion();
        }

        function toggleCheckAll(el) {
            document.querySelectorAll('.devCheck').forEach(cb => { cb.checked = el.checked; });
            actualizarTotalDevolucion();
        }

        function actualizarTotalDevolucion() {
            let total = 0;
            document.querySelectorAll('.devCheck').forEach(cb => {
                const i = parseInt(cb.dataset.index);
                const item = devItemsData[i];
                const cantInput = document.querySelector(`.devCantidad[data-index="${i}"]`);
                const subtotalCell = document.querySelector(`.devSubtotal[data-index="${i}"]`);
                let cant = parseInt(cantInput.value) || 0;
                if (cant > item.cantidad_disponible) { cant = item.cantidad_disponible; cantInput.value = cant; }
                if (cant < 1) { cant = 1; cantInput.value = cant; }

                if (cb.checked) {
                    const precioNeto = item.precio_unitario * (1 - item.descuento_porcentaje / 100);
                    const ivaItem = item.iva_unitario * cant;
                    const sub = (precioNeto * cant) + ivaItem;
                    subtotalCell.textContent = '$' + sub.toLocaleString('es-CO', {minimumFractionDigits: 2});
                    total += sub;
                } else {
                    subtotalCell.textContent = '$0';
                }
            });
            document.getElementById('devTotalLabel').textContent = 'Total a devolver: $' + total.toLocaleString('es-CO', {minimumFractionDigits: 2});
        }

        function confirmarDevolucion() {
            const motivo = document.getElementById('devMotivo').value.trim();
            if (!motivo || motivo.length < 10) {
                Swal.fire('Error', 'Ingrese un motivo de al menos 10 caracteres', 'error');
                return;
            }

            const itemsSeleccionados = [];
            document.querySelectorAll('.devCheck:checked').forEach(cb => {
                const i = parseInt(cb.dataset.index);
                const cant = parseInt(document.querySelector(`.devCantidad[data-index="${i}"]`).value) || 0;
                if (cant > 0) {
                    itemsSeleccionados.push({
                        item_venta_pdv_id: devItemsData[i].item_venta_pdv_id,
                        cantidad: cant,
                    });
                }
            });

            if (!itemsSeleccionados.length) {
                Swal.fire('Error', 'Seleccione al menos un producto para devolver', 'error');
                return;
            }

            Swal.fire({
                title: '¿Confirmar devolución parcial?',
                text: `Se devolverán ${itemsSeleccionados.length} producto(s). Esta acción no se puede deshacer.`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ffc107',
                confirmButtonText: 'Sí, devolver',
                cancelButtonText: 'Cancelar',
            }).then(result => {
                if (result.isConfirmed) {
                    Swal.fire({ title: 'Procesando...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

                    fetch(`/pdv/ventas/${devVentaId}/devolucion-parcial`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            'Accept': 'application/json',
                        },
                        body: JSON.stringify({ motivo_anulacion: motivo, items: itemsSeleccionados }),
                    })
                    .then(r => r.json())
                    .then(data => {
                        Swal.close();
                        bootstrap.Modal.getInstance(document.getElementById('modalDevolucionParcial'))?.hide();

                        let msg = data.mensaje;
                        if (data.nota_credito) {
                            msg += `\nNota Crédito: ${data.nota_credito.numero || 'Pendiente'} (${data.nota_credito.estado})`;
                        }
                        if (data.nota_credito_error) {
                            msg += `\n⚠️ ${data.nota_credito_error}`;
                        }

                        Swal.fire(data.exito ? 'Devolución Exitosa' : 'Error', msg, data.exito ? 'success' : 'error');
                        tabla.ajax.reload();
                    })
                    .catch(() => {
                        Swal.fire('Error', 'Error de conexión', 'error');
                    });
                }
            });
        }

        // SIIGO invoice functions (called from detalle partial)
        function reenviarEmailFactura(ventaId) {
            fetch(`/pdv/ventas/${ventaId}/factura/reenviar`, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, 'Accept': 'application/json' }
            }).then(r => r.json()).then(data => {
                Swal.fire(data.exito ? 'Enviado' : 'Error', data.mensaje, data.exito ? 'success' : 'error');
                if (data.exito) verDetalle(ventaId);
            }).catch(() => Swal.fire('Error', 'Error de conexión', 'error'));
        }

        function consultarEstadoFactura(ventaId) {
            fetch(`/pdv/ventas/${ventaId}/factura/estado`, { headers: { 'Accept': 'application/json' } })
            .then(r => r.json()).then(data => {
                Swal.fire('Estado Actualizado', data.mensaje, 'info');
                verDetalle(ventaId);
                tabla.ajax.reload();
            }).catch(() => Swal.fire('Error', 'Error de conexión', 'error'));
        }

        function reintentarFactura(ventaId) {
            fetch(`/pdv/ventas/${ventaId}/factura/reintentar`, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, 'Accept': 'application/json' }
            }).then(r => r.json()).then(data => {
                Swal.fire(data.exito ? 'Reintento exitoso' : 'Error', data.mensaje, data.exito ? 'success' : 'error');
                verDetalle(ventaId);
                tabla.ajax.reload();
            }).catch(() => Swal.fire('Error', 'Error de conexión', 'error'));
        }

        function reintentarNotaCredito(ventaId, ncId) {
            fetch(`/pdv/ventas/${ventaId}/nota-credito/${ncId}/reintentar`, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, 'Accept': 'application/json' }
            }).then(r => r.json()).then(data => {
                Swal.fire(data.exito ? 'Reintento NC' : 'Error', data.mensaje, data.exito ? 'success' : 'error');
                verDetalle(ventaId);
            }).catch(() => Swal.fire('Error', 'Error de conexión', 'error'));
        }
    </script>
    @endpush
</x-app-layout>
