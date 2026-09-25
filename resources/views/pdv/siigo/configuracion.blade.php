<x-app-layout>
    @section('title', 'Configuración SIIGO')

    <div class="container-fluid py-4">
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <div class="d-flex align-items-center mb-4">
                    <a href="{{ route('pdv.dashboard') }}" class="btn btn-outline-secondary me-3">
                        <i class="bi bi-arrow-left"></i>
                    </a>
                    <h4 class="fw-bold mb-0"><i class="bi bi-cloud-arrow-up me-2"></i>Configuración SIIGO - Facturación Electrónica</h4>
                </div>

                @if(session('success'))
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="bi bi-check-circle me-2"></i>{{ session('success') }}
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                @endif

                <form action="{{ route('pdv.siigo.config.guardar') }}" method="POST" id="formSiigoConfig">
                    @csrf
                    <div class="row">
                        {{-- Columna izquierda: Credenciales y conexión --}}
                        <div class="col-md-6 mb-4">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white border-bottom">
                                    <h6 class="fw-bold mb-0"><i class="bi bi-key me-2"></i>Credenciales API SIIGO</h6>
                                </div>
                                <div class="card-body">
                                    <div class="mb-3">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" id="siigo_activo" name="siigo_activo"
                                                {{ $config['siigo_activo'] ? 'checked' : '' }}>
                                            <label class="form-check-label fw-semibold" for="siigo_activo">
                                                Facturación SIIGO Activa
                                            </label>
                                        </div>
                                        <small class="text-muted">Activa/desactiva la integración con SIIGO</small>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label fw-semibold">Modo</label>
                                        <select name="siigo_modo" class="form-select">
                                            <option value="test" {{ $config['siigo_modo'] === 'test' ? 'selected' : '' }}>Test (Pruebas)</option>
                                            <option value="produccion" {{ $config['siigo_modo'] === 'produccion' ? 'selected' : '' }}>Producción</option>
                                        </select>
                                        <small class="text-muted">Usar modo Test para pruebas con SIIGO</small>
                                    </div>

                                    {{-- Credenciales de Producción --}}
                                    <div id="credencialesProduccion" class="{{ $config['siigo_modo'] === 'test' ? 'd-none' : '' }}">
                                        <div class="mb-3">
                                            <label class="form-label fw-semibold">Usuario API - Producción (Email)</label>
                                            <input type="email" name="siigo_username" class="form-control"
                                                value="{{ $config['siigo_username'] }}" placeholder="usuario@empresa.com">
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label fw-semibold">Access Key - Producción</label>
                                            <div class="input-group">
                                                <input type="password" name="siigo_access_key" class="form-control" id="inputAccessKey"
                                                    value="{{ $config['siigo_access_key'] }}" placeholder="Access Key de SIIGO">
                                                <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('inputAccessKey', 'iconPassword')">
                                                    <i class="bi bi-eye" id="iconPassword"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>

                                    {{-- Credenciales de Test --}}
                                    <div id="credencialesTest" class="{{ $config['siigo_modo'] !== 'test' ? 'd-none' : '' }}">
                                        <div class="alert alert-warning py-2 mb-3">
                                            <i class="bi bi-exclamation-triangle me-1"></i>
                                            <strong>MODO PRUEBA:</strong> Use credenciales de prueba proporcionadas por Siigo. Las credenciales de producción NO se usarán en este modo.
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label fw-semibold">Usuario API - Test (Email)</label>
                                            <input type="email" name="siigo_username_test" class="form-control"
                                                value="{{ $config['siigo_username_test'] }}" placeholder="usuario-test@empresa.com">
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label fw-semibold">Access Key - Test</label>
                                            <div class="input-group">
                                                <input type="password" name="siigo_access_key_test" class="form-control" id="inputAccessKeyTest"
                                                    value="{{ $config['siigo_access_key_test'] }}" placeholder="Access Key de prueba de SIIGO">
                                                <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('inputAccessKeyTest', 'iconPasswordTest')">
                                                    <i class="bi bi-eye" id="iconPasswordTest"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label fw-semibold">Partner ID</label>
                                        <input type="text" name="siigo_partner_id" class="form-control"
                                            value="{{ $config['siigo_partner_id'] }}" placeholder="MiraclePdV">
                                        <small class="text-muted">Identificador de la integración para SIIGO</small>
                                    </div>

                                    <div class="d-flex gap-2">
                                        <button type="button" class="btn btn-outline-primary" id="btnTestConexion">
                                            <i class="bi bi-wifi me-1"></i>Test Conexión
                                        </button>
                                        <span id="testResultado" class="align-self-center"></span>
                                    </div>
                                </div>
                            </div>

                            {{-- Opciones generales --}}
                            <div class="card border-0 shadow-sm mt-4">
                                <div class="card-header bg-white border-bottom">
                                    <h6 class="fw-bold mb-0"><i class="bi bi-sliders me-2"></i>Opciones Generales</h6>
                                </div>
                                <div class="card-body">
                                    <div class="mb-3">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" id="siigo_facturar_siempre"
                                                name="siigo_facturar_siempre" {{ $config['siigo_facturar_siempre'] ? 'checked' : '' }}>
                                            <label class="form-check-label fw-semibold" for="siigo_facturar_siempre">
                                                Facturar automáticamente todas las ventas
                                            </label>
                                        </div>
                                        <small class="text-muted">Si está activo, todas las ventas generan factura sin preguntar</small>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label fw-semibold">NIT Consumidor Final</label>
                                        <input type="text" name="siigo_consumidor_final_nit" class="form-control"
                                            value="{{ $config['siigo_consumidor_final_nit'] }}">
                                        <small class="text-muted">NIT para ventas sin identificación del cliente (por defecto: 222222222222)</small>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label fw-semibold">Máximo reintentos</label>
                                        <input type="number" name="siigo_max_reintentos" class="form-control" min="1" max="10"
                                            value="{{ $config['siigo_max_reintentos'] }}">
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- Columna derecha: Catálogos SIIGO --}}
                        <div class="col-md-6 mb-4">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center">
                                    <h6 class="fw-bold mb-0"><i class="bi bi-list-check me-2"></i>Catálogos SIIGO</h6>
                                    <button type="button" class="btn btn-sm btn-outline-primary" id="btnCargarCatalogos">
                                        <i class="bi bi-arrow-repeat me-1"></i>Cargar de SIIGO
                                    </button>
                                </div>
                                <div class="card-body">
                                    <div id="catalogosLoading" class="text-center py-3 d-none">
                                        <div class="spinner-border spinner-border-sm text-primary" role="status"></div>
                                        <span class="ms-2">Cargando catálogos de SIIGO...</span>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label fw-semibold">Tipo de Documento - Factura de Venta</label>
                                        <select name="siigo_document_type_id" class="form-select" id="selectDocumentType">
                                            <option value="">-- Seleccione --</option>
                                            @if($config['siigo_document_type_id'])
                                                <option value="{{ $config['siigo_document_type_id'] }}" selected>
                                                    ID: {{ $config['siigo_document_type_id'] }} (guardado)
                                                </option>
                                            @endif
                                        </select>
                                        <small class="text-muted">Tipo de documento para facturas de venta en SIIGO</small>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label fw-semibold">Tipo de Documento - Nota Crédito</label>
                                        <select name="siigo_credit_note_type_id" class="form-select" id="selectCreditNoteType">
                                            <option value="">-- Seleccione --</option>
                                            @if($config['siigo_credit_note_type_id'])
                                                <option value="{{ $config['siigo_credit_note_type_id'] }}" selected>
                                                    ID: {{ $config['siigo_credit_note_type_id'] }} (guardado)
                                                </option>
                                            @endif
                                        </select>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label fw-semibold">Método de Pago - Efectivo</label>
                                        <select name="siigo_payment_type_efectivo_id" class="form-select" id="selectPaymentTypeEfectivo">
                                            <option value="">-- Seleccione --</option>
                                            @if($config['siigo_payment_type_efectivo_id'])
                                                <option value="{{ $config['siigo_payment_type_efectivo_id'] }}" selected>
                                                    ID: {{ $config['siigo_payment_type_efectivo_id'] }} (guardado)
                                                </option>
                                            @endif
                                        </select>
                                        <small class="text-muted">Para ventas pagadas en efectivo</small>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label fw-semibold">Método de Pago - Transferencia</label>
                                        <select name="siigo_payment_type_transferencia_id" class="form-select" id="selectPaymentTypeTransferencia">
                                            <option value="">-- Seleccione --</option>
                                            @if($config['siigo_payment_type_transferencia_id'])
                                                <option value="{{ $config['siigo_payment_type_transferencia_id'] }}" selected>
                                                    ID: {{ $config['siigo_payment_type_transferencia_id'] }} (guardado)
                                                </option>
                                            @endif
                                        </select>
                                        <small class="text-muted">Para ventas por transferencia o pago mixto</small>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label fw-semibold">Impuesto IVA</label>
                                        <select name="siigo_tax_id" class="form-select" id="selectTax">
                                            <option value="">-- Seleccione (o dejar vacío si no aplica IVA) --</option>
                                            @if($config['siigo_tax_id'])
                                                <option value="{{ $config['siigo_tax_id'] }}" selected>
                                                    ID: {{ $config['siigo_tax_id'] }} (guardado)
                                                </option>
                                            @endif
                                        </select>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label fw-semibold">Vendedor en SIIGO</label>
                                        <select name="siigo_seller_id" class="form-select" id="selectSeller">
                                            <option value="">-- Seleccione --</option>
                                            @if($config['siigo_seller_id'])
                                                <option value="{{ $config['siigo_seller_id'] }}" selected>
                                                    ID: {{ $config['siigo_seller_id'] }} (guardado)
                                                </option>
                                            @endif
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Numeración propia por sede --}}
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-header bg-white border-bottom">
                            <h6 class="fw-bold mb-0"><i class="bi bi-shop me-2"></i>Numeración por sede</h6>
                        </div>
                        <div class="card-body">
                            <p class="text-muted small mb-3">
                                Cada sede puede facturar con su propio comprobante de SIIGO (su prefijo y su resolución DIAN).
                                Las ventas de esa sede salen con ese comprobante, y sus notas crédito con el de notas crédito.
                                Si se deja en <strong>General</strong>, la sede usa los comprobantes de arriba.
                                Pulse <strong>Cargar de SIIGO</strong> para ver los nombres de los comprobantes.
                            </p>
                            @if($sedes->isEmpty())
                                <p class="text-muted mb-0">No hay tiendas activas ni ubicaciones con caja.</p>
                            @else
                                <div class="table-responsive">
                                    <table class="table align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th>Sede</th>
                                                <th style="min-width: 240px;">Comprobante factura de venta</th>
                                                <th style="min-width: 240px;">Comprobante nota crédito</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach($sedes as $sede)
                                                <tr>
                                                    <td>
                                                        <div class="fw-semibold">{{ $sede->nombre }}</div>
                                                        <small class="text-muted">{{ $sede->codigo }}{{ $sede->es_principal ? ' · principal' : '' }}</small>
                                                    </td>
                                                    <td>
                                                        <select name="sedes[{{ $sede->id }}][siigo_document_type_id]" class="form-select form-select-sm js-sede-fv">
                                                            <option value="">General</option>
                                                            @if($sede->siigo_document_type_id)
                                                                <option value="{{ $sede->siigo_document_type_id }}" selected>
                                                                    ID: {{ $sede->siigo_document_type_id }} (guardado)
                                                                </option>
                                                            @endif
                                                        </select>
                                                    </td>
                                                    <td>
                                                        <select name="sedes[{{ $sede->id }}][siigo_credit_note_type_id]" class="form-select form-select-sm js-sede-nc">
                                                            <option value="">General</option>
                                                            @if($sede->siigo_credit_note_type_id)
                                                                <option value="{{ $sede->siigo_credit_note_type_id }}" selected>
                                                                    ID: {{ $sede->siigo_credit_note_type_id }} (guardado)
                                                                </option>
                                                            @endif
                                                        </select>
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                        </div>
                    </div>

                    <div class="d-flex justify-content-end mb-4">
                        <button type="submit" class="btn text-white px-4" style="background: var(--miracle-pink);">
                            <i class="bi bi-check-lg me-1"></i>Guardar Configuración
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    @push('scripts')
    <script>
        function togglePassword(inputId, iconId) {
            const input = document.getElementById(inputId);
            const icon = document.getElementById(iconId);
            if (input.type === 'password') {
                input.type = 'text';
                icon.className = 'bi bi-eye-slash';
            } else {
                input.type = 'password';
                icon.className = 'bi bi-eye';
            }
        }

        // Toggle credential fields based on mode
        document.querySelector('select[name="siigo_modo"]').addEventListener('change', function() {
            const esTest = this.value === 'test';
            document.getElementById('credencialesTest').classList.toggle('d-none', !esTest);
            document.getElementById('credencialesProduccion').classList.toggle('d-none', esTest);
        });

        document.getElementById('btnTestConexion').addEventListener('click', function() {
            const btn = this;
            const resultado = document.getElementById('testResultado');
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Probando...';
            resultado.innerHTML = '';

            fetch('{{ route("pdv.siigo.test") }}', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                }
            })
            .then(r => r.json())
            .then(data => {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-wifi me-1"></i>Test Conexión';
                if (data.exito) {
                    resultado.innerHTML = '<span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>' + data.mensaje + '</span>';
                } else {
                    resultado.innerHTML = '<span class="badge bg-danger"><i class="bi bi-x-circle me-1"></i>' + data.mensaje + '</span>';
                }
            })
            .catch(() => {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-wifi me-1"></i>Test Conexión';
                resultado.innerHTML = '<span class="badge bg-danger">Error de conexión</span>';
            });
        });

        document.getElementById('btnCargarCatalogos').addEventListener('click', function() {
            const btn = this;
            const loading = document.getElementById('catalogosLoading');
            btn.disabled = true;
            loading.classList.remove('d-none');

            fetch('{{ route("pdv.siigo.catalogos") }}', {
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                }
            })
            .then(r => r.json())
            .then(data => {
                btn.disabled = false;
                loading.classList.add('d-none');

                if (!data.exito) {
                    alert(data.mensaje || 'Error al cargar catálogos');
                    return;
                }

                // Populate Document Types (FV)
                const selectDoc = document.getElementById('selectDocumentType');
                const currentDoc = selectDoc.value;
                selectDoc.innerHTML = '<option value="">-- Seleccione --</option>';
                if (Array.isArray(data.document_types)) {
                    data.document_types.forEach(dt => {
                        const opt = new Option(dt.id + ' - ' + (dt.name || dt.description || ''), dt.id);
                        if (dt.id == currentDoc) opt.selected = true;
                        selectDoc.add(opt);
                    });
                }

                // Populate Credit Note Types
                const selectCN = document.getElementById('selectCreditNoteType');
                const currentCN = selectCN.value;
                selectCN.innerHTML = '<option value="">-- Seleccione --</option>';
                if (Array.isArray(data.credit_note_types)) {
                    data.credit_note_types.forEach(dt => {
                        const opt = new Option(dt.id + ' - ' + (dt.name || dt.description || ''), dt.id);
                        if (dt.id == currentCN) opt.selected = true;
                        selectCN.add(opt);
                    });
                }

                // Populate per-sede comprobantes (empty = General)
                const fillSede = (select, types) => {
                    const current = select.value;
                    select.innerHTML = '<option value="">General</option>';
                    if (Array.isArray(types)) {
                        types.forEach(dt => {
                            const opt = new Option(dt.id + ' - ' + (dt.name || dt.description || ''), dt.id);
                            if (dt.id == current) opt.selected = true;
                            select.add(opt);
                        });
                    }
                };
                document.querySelectorAll('.js-sede-fv').forEach(s => fillSede(s, data.document_types));
                document.querySelectorAll('.js-sede-nc').forEach(s => fillSede(s, data.credit_note_types));

                // Populate Payment Types - Efectivo
                const selectPayEfectivo = document.getElementById('selectPaymentTypeEfectivo');
                const currentPayEfectivo = selectPayEfectivo.value;
                selectPayEfectivo.innerHTML = '<option value="">-- Seleccione --</option>';
                if (Array.isArray(data.payment_types)) {
                    data.payment_types.forEach(pt => {
                        const opt = new Option(pt.id + ' - ' + (pt.name || ''), pt.id);
                        if (pt.id == currentPayEfectivo) opt.selected = true;
                        selectPayEfectivo.add(opt);
                    });
                }

                // Populate Payment Types - Transferencia
                const selectPayTransferencia = document.getElementById('selectPaymentTypeTransferencia');
                const currentPayTransferencia = selectPayTransferencia.value;
                selectPayTransferencia.innerHTML = '<option value="">-- Seleccione --</option>';
                if (Array.isArray(data.payment_types)) {
                    data.payment_types.forEach(pt => {
                        const opt = new Option(pt.id + ' - ' + (pt.name || ''), pt.id);
                        if (pt.id == currentPayTransferencia) opt.selected = true;
                        selectPayTransferencia.add(opt);
                    });
                }

                // Populate Taxes
                const selectTax = document.getElementById('selectTax');
                const currentTax = selectTax.value;
                selectTax.innerHTML = '<option value="">-- Seleccione (o dejar vacío si no aplica IVA) --</option>';
                if (Array.isArray(data.taxes)) {
                    data.taxes.forEach(t => {
                        const label = t.id + ' - ' + (t.name || '') + (t.percentage ? ' (' + t.percentage + '%)' : '');
                        const opt = new Option(label, t.id);
                        if (t.id == currentTax) opt.selected = true;
                        selectTax.add(opt);
                    });
                }

                // Populate Sellers
                const selectSeller = document.getElementById('selectSeller');
                const currentSeller = selectSeller.value;
                selectSeller.innerHTML = '<option value="">-- Seleccione --</option>';
                const sellersList = data.sellers?.results || (Array.isArray(data.sellers) ? data.sellers : []);
                sellersList.forEach(s => {
                    const name = s.first_name ? (s.first_name + ' ' + (s.last_name || '')) : (s.username || s.identification || '');
                    const opt = new Option(s.id + ' - ' + name, s.id);
                    if (s.id == currentSeller) opt.selected = true;
                    selectSeller.add(opt);
                });
            })
            .catch(err => {
                btn.disabled = false;
                loading.classList.add('d-none');
                alert('Error al cargar catálogos: ' + err.message);
            });
        });
    </script>
    @endpush
</x-app-layout>
