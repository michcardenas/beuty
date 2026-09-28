<div class="d-flex flex-column h-100">
    {{-- Logo --}}
    <div class="d-flex justify-content-center align-items-center py-3 border-bottom" style="border-color: var(--miracle-lilac) !important; background-color: white;">
        <a href="/" class="text-decoration-none">
            <img style="width: 80%; margin-left: 5%;" src="{{ asset('images/logo.png') }}" class="logo-full" width="100" alt="Miracle Beauty Experts">
            <img src="{{ asset('images/ico.png') }}" class="logo-icon d-none" width="40" alt="Miracle">
        </a>
    </div>

    {{-- Navegación con scroll vertical --}}
    <nav class="nav flex-column flex-nowrap px-2 py-3 flex-grow-1" style="min-height: 0; overflow-y: auto; overflow-x: hidden;">
        <a href="/dashboard"
           class="nav-link mb-2 d-flex align-items-center gap-2 {{ request()->is('dashboard') ? 'active' : 'text-dark' }}">
            <i class="bi bi-house"></i>
            <span>Inicio</span>
        </a>

        {{-- Seguimiento Comercial (rol vendedor) --}}
        @if(auth()->user()->hasRole('vendedor'))
            <div class="border-top my-2" style="border-color: var(--miracle-lilac) !important;"></div>
            <p class="nav-link mb-1 text-muted small fw-semibold text-uppercase">
                <i class="bi bi-graph-up-arrow me-1"></i>
                <span>Mi Gestión Comercial</span>
            </p>
            <a href="{{ route('vendedor.panel') }}"
               class="nav-link mb-2 d-flex align-items-center gap-2 {{ request()->routeIs('vendedor.panel') ? 'active' : 'text-dark' }}">
                <i class="bi bi-speedometer2"></i>
                <span>Mi Panel</span>
            </a>
            <a href="{{ route('vendedor.clientes') }}"
               class="nav-link mb-2 d-flex align-items-center gap-2 {{ request()->routeIs('vendedor.clientes') ? 'active' : 'text-dark' }}">
                <i class="bi bi-people"></i>
                <span>Ventas por Cliente</span>
            </a>
            <a href="{{ route('vendedor.contado-credito') }}"
               class="nav-link mb-2 d-flex align-items-center gap-2 {{ request()->routeIs('vendedor.contado-credito') ? 'active' : 'text-dark' }}">
                <i class="bi bi-cash-coin"></i>
                <span>Contado / Crédito</span>
            </a>
            <a href="{{ route('vendedor.seguimiento') }}"
               class="nav-link mb-2 d-flex align-items-center gap-2 {{ request()->routeIs('vendedor.seguimiento') ? 'active' : 'text-dark' }}">
                <i class="bi bi-list-check"></i>
                <span>Seguimiento</span>
            </a>
            <div class="border-top my-2" style="border-color: var(--miracle-lilac) !important;"></div>
        @endif

        {{-- Servicio Técnico (para admin y técnico) - OCULTO TEMPORALMENTE
        @if(auth()->user()->hasRole(['admin', 'tecnico']))
            <div class="nav-item mb-2">
                <a href="#" class="nav-link d-flex align-items-center gap-2 {{ request()->routeIs('st.*') ? 'active' : 'text-dark' }}"
                   data-bs-toggle="collapse" data-bs-target="#submenuServicioTecnico"
                   aria-expanded="{{ request()->routeIs('st.*') ? 'true' : 'false' }}">
                    <i class="bi bi-tools"></i>
                    <span>Servicio</span>
                    <i class="bi bi-chevron-down ms-auto"></i>
                </a>
                <div class="collapse {{ request()->routeIs('st.*') ? 'show' : '' }}" id="submenuServicioTecnico">
                    <div class="nav flex-column ms-3">
                        <a href="{{ route('st.dashboard') }}"
                           class="nav-link py-2 d-flex align-items-center gap-2 {{ request()->routeIs('st.dashboard') ? 'active' : 'text-dark' }}">
                            <i class="bi bi-speedometer2"></i>
                            <span>Dashboard</span>
                        </a>
                        <a href="{{ route('st.ordenes.index') }}"
                           class="nav-link py-2 d-flex align-items-center gap-2 {{ request()->routeIs('st.ordenes.*') ? 'active' : 'text-dark' }}">
                            <i class="bi bi-file-earmark-text"></i>
                            <span>Órdenes</span>
                        </a>
                        <a href="{{ route('st.tecnicos.index') }}"
                           class="nav-link py-2 d-flex align-items-center gap-2 {{ request()->routeIs('st.tecnicos.*') ? 'active' : 'text-dark' }}">
                            <i class="bi bi-person-gear"></i>
                            <span>Técnicos</span>
                        </a>
                        <a href="{{ route('st.clientes.index') }}"
                           class="nav-link py-2 d-flex align-items-center gap-2 {{ request()->routeIs('st.clientes.*') ? 'active' : 'text-dark' }}">
                            <i class="bi bi-people"></i>
                            <span>Clientes</span>
                        </a>
                        <a href="{{ route('st.equipos.index') }}"
                           class="nav-link py-2 d-flex align-items-center gap-2 {{ request()->routeIs('st.equipos.*') ? 'active' : 'text-dark' }}">
                            <i class="bi bi-camera-video"></i>
                            <span>Equipos</span>
                        </a>
                        <a href="{{ route('st.repuestos.index') }}"
                           class="nav-link py-2 d-flex align-items-center gap-2 {{ request()->routeIs('st.repuestos.*') ? 'active' : 'text-dark' }}">
                            <i class="bi bi-box-seam"></i>
                            <span>Repuestos</span>
                        </a>
                    </div>
                </div>
            </div>
        @endif
        --}}

        @if (auth()->user()->getRoleNames()->first() == 'admin')
            <a href="{{ route('dashboard.metricas') }}"
               class="nav-link mb-2 d-flex align-items-center gap-2 {{ request()->routeIs('dashboard.metricas') ? 'active' : 'text-dark' }}">
                <i class="bi bi-graph-up-arrow"></i>
                <span>Métricas</span>
            </a>
            <a href="{{ route('metricas.productos.index') }}"
               class="nav-link mb-2 d-flex align-items-center gap-2 {{ request()->routeIs('metricas.productos.*') ? 'active' : 'text-dark' }}">
                <i class="bi bi-bar-chart-steps"></i>
                <span>Métricas Productos</span>
            </a>
            <a href="{{ route('admin.vendedores.index') }}"
               class="nav-link mb-2 d-flex align-items-center gap-2 {{ request()->routeIs('admin.vendedores.*') ? 'active' : 'text-dark' }}">
                <i class="bi bi-person-lines-fill"></i>
                <span>Seguimiento Vendedores</span>
            </a>
        @endif

        {{-- Usuarios (para admin, auxiliar_administrativo, inventarios y facturación) --}}
        @if(auth()->user()->hasRole(['admin', 'auxiliar_administrativo', 'inventarios', 'facturacion']))
            <a href="/usuarios"
               class="nav-link mb-2 d-flex align-items-center gap-2 {{ request()->is('usuarios*') ? 'active' : 'text-dark' }}">
                <i class="bi bi-people"></i>
                <span>Usuarios</span>
            </a>
        @endif

        {{-- Cotizaciones (para vendedor, admin, auxiliar_administrativo, facturación, auxiliar inventario y garantías) --}}
        @if(auth()->user()->hasRole(['vendedor', 'admin', 'auxiliar_administrativo', 'facturacion', 'inventarios', 'auxiliar_inventario', 'garantias']))
            <a href="{{ route('solicitudes') }}"
               class="nav-link mb-2 d-flex align-items-center gap-2 {{ request()->routeIs('solicitudes*') ? 'active' : 'text-dark' }}">
                <i class="bi bi-clipboard-data"></i>
                <span>Cotizaciones</span>
            </a>
        @endif

        {{-- Clientes (para vendedor, admin, auxiliar_administrativo, facturación e inventarios) --}}
        @if(auth()->user()->hasRole(['vendedor', 'admin', 'auxiliar_administrativo', 'facturacion', 'inventarios']))
            <a href="/clientes"
               class="nav-link mb-2 d-flex align-items-center gap-2 {{ request()->is('clientes*') ? 'active' : 'text-dark' }}">
                <i class="bi bi-person-badge"></i>
                <span>Clientes</span>
            </a>
        @endif

        {{-- Catálogo (para vendedor, admin y auxiliar_administrativo) --}}
        @if(auth()->user()->hasRole(['vendedor', 'admin', 'auxiliar_administrativo']))
            <a href="{{ route('catalogo') }}"
               class="nav-link mb-2 d-flex align-items-center gap-2 {{ request()->routeIs('catalogo*') ? 'active' : 'text-dark' }}">
                <i class="bi bi-cart"></i>
                <span>Catálogo</span>
            </a>
            <a href="{{ route('enlaces') }}"
               class="nav-link mb-2 d-flex align-items-center gap-2 {{ request()->routeIs('enlaces*') ? 'active' : 'text-dark' }}">
                <i class="bi bi-link-45deg"></i>
                <span>Links</span>
            </a>
        @endif

        {{-- Traslados (solo para centro de experiencia) --}}
        @if(auth()->user()->hasRole('centro_experiencia'))
            <a href="{{ route('traslados') }}"
               class="nav-link mb-2 d-flex align-items-center gap-2 {{ request()->routeIs('traslados*') ? 'active' : 'text-dark' }}">
                <i class="bi bi-arrow-left-right"></i>
                <span>Traslados</span>
            </a>
        @endif

        @if(auth()->user()->hasRole(['admin', 'auxiliar_administrativo', 'facturacion']))
            <a href="/listas-precios"
               class="nav-link mb-2 d-flex align-items-center gap-2 {{ request()->is('listas-precios*') ? 'active' : 'text-dark' }}">
                <i class="bi bi-currency-dollar"></i>
                <span>Listas de Precios</span>
            </a>
        @endif

        {{-- Sección Inventario (para admin, auxiliar_administrativo, inventarios y auxiliar inventario) --}}
        @if(auth()->user()->hasRole(['admin', 'auxiliar_administrativo', 'inventarios', 'auxiliar_inventario', 'facturacion']))
            <div class="border-top my-2" style="border-color: var(--miracle-lilac) !important;"></div>
            <p class="nav-link mb-1 text-muted small fw-semibold text-uppercase">
                <i class="bi bi-boxes me-1"></i>
                <span>Inventario</span>
            </p>
            @if(auth()->user()->hasRole(['admin', 'auxiliar_administrativo', 'inventarios']))
            <a href="/categorias"
               class="nav-link mb-2 d-flex align-items-center gap-2 {{ request()->is('categorias*') ? 'active' : 'text-dark' }}">
                <i class="bi bi-tags"></i>
                <span>Categorías</span>
            </a>
            @endif
            @if(auth()->user()->hasRole(['admin', 'auxiliar_administrativo', 'inventarios', 'facturacion']))
            <a href="/productos"
               class="nav-link mb-2 d-flex align-items-center gap-2 {{ request()->is('productos*') ? 'active' : 'text-dark' }}">
                <i class="bi bi-basket3"></i>
                <span>Productos</span>
            </a>
            @endif
            @if(!auth()->user()->hasRole('facturacion') || auth()->user()->hasRole(['admin', 'auxiliar_administrativo', 'inventarios', 'auxiliar_inventario']))
            <a href="{{ route('stock.index') }}"
               class="nav-link mb-2 d-flex align-items-center gap-2 {{ request()->routeIs('stock.index*') ? 'active' : 'text-dark' }}">
                <i class="bi bi-box-seam"></i>
                <span>Gestión de Stock</span>
            </a>
            @endif
            @if(auth()->user()->hasRole(['admin', 'auxiliar_administrativo', 'inventarios']))
            <a href="{{ route('traslados') }}"
               class="nav-link mb-2 d-flex align-items-center gap-2 {{ request()->routeIs('traslados*') ? 'active' : 'text-dark' }}">
                <i class="bi bi-arrow-left-right"></i>
                <span>Traslados</span>
            </a>
            <a href="/ubicaciones"
               class="nav-link mb-2 d-flex align-items-center gap-2 {{ request()->is('ubicaciones*') ? 'active' : 'text-dark' }}">
                <i class="bi bi-geo-alt"></i>
                <span>Ubicaciones</span>
            </a>
            <a href="{{ route('ferias.index') }}"
               class="nav-link mb-2 d-flex align-items-center gap-2 {{ request()->is('ferias*') ? 'active' : 'text-dark' }}">
                <i class="bi bi-shop-window"></i>
                <span>Ferias</span>
            </a>
            <a href="{{ route('productos.importacion.historial') }}"
               class="nav-link mb-2 d-flex align-items-center gap-2 {{ request()->is('productos/historial-importaciones*') || request()->is('productos/importacion*') ? 'active' : 'text-dark' }}">
                <i class="bi bi-upload"></i>
                <span>Importar Productos</span>
            </a>
            @endif
        @endif


        @if(auth()->user()->hasRole(['admin', 'cajero_principal', 'auxiliar_venta']))
            <div class="border-top my-2" style="border-color: var(--miracle-lilac) !important;"></div>
            <p class="nav-link mb-1 text-muted small fw-semibold text-uppercase">
                <i class="bi bi-shop me-1"></i>
                <span>Punto de Venta</span>
            </p>
            <a href="{{ route('pdv.dashboard') }}"
               class="nav-link mb-2 d-flex align-items-center gap-2 {{ request()->routeIs('pdv.dashboard') ? 'active' : 'text-dark' }}">
                <i class="bi bi-speedometer2"></i>
                <span>Dashboard PdV</span>
            </a>

            @if(auth()->user()->hasRole(['admin']))
                <a href="{{ route('pdv.cajas.index') }}"
                   class="nav-link mb-2 d-flex align-items-center gap-2 {{ request()->routeIs('pdv.cajas.*') ? 'active' : 'text-dark' }}">
                    <i class="bi bi-cash-stack"></i>
                    <span>Config. Cajas</span>
                </a>
                <a href="{{ route('pdv.siigo.config') }}"
                   class="nav-link mb-2 d-flex align-items-center gap-2 {{ request()->routeIs('pdv.siigo.*') ? 'active' : 'text-dark' }}">
                    <i class="bi bi-cloud-arrow-up"></i>
                    <span>Config. SIIGO</span>
                </a>
                <a href="{{ route('pdv.vendedoras.index') }}"
                   class="nav-link mb-2 d-flex align-items-center gap-2 {{ request()->routeIs('pdv.vendedoras.*') ? 'active' : 'text-dark' }}">
                    <i class="bi bi-person-vcard"></i>
                    <span>Vendedoras</span>
                </a>
            @endif

            @if(auth()->user()->hasRole(['admin', 'cajero_principal']))
                <a href="{{ route('pdv.ventas.crear') }}"
                   class="nav-link mb-2 d-flex align-items-center gap-2 {{ request()->routeIs('pdv.ventas.crear') ? 'active' : 'text-dark' }}">
                    <i class="bi bi-cart-plus"></i>
                    <span>Nueva Venta</span>
                </a>
                <a href="{{ route('pdv.ventas.index') }}"
                   class="nav-link mb-2 d-flex align-items-center gap-2 {{ request()->routeIs('pdv.ventas.index') ? 'active' : 'text-dark' }}">
                    <i class="bi bi-list-ul"></i>
                    <span>Historial Ventas</span>
                </a>
                <a href="{{ route('pdv.prefacturas.pendientes') }}"
                   class="nav-link mb-2 d-flex align-items-center gap-2 {{ request()->routeIs('pdv.prefacturas.pendientes') ? 'active' : 'text-dark' }}">
                    <i class="bi bi-receipt"></i>
                    <span>Prefacturas</span>
                </a>
                <a href="{{ route('pdv.vales.index') }}"
                   class="nav-link mb-2 d-flex align-items-center gap-2 {{ request()->routeIs('pdv.vales.*') ? 'active' : 'text-dark' }}">
                    <i class="bi bi-ticket-perforated"></i>
                    <span>Vales</span>
                </a>
                <a href="{{ route('pdv.reportes.index') }}"
                   class="nav-link mb-2 d-flex align-items-center gap-2 {{ request()->routeIs('pdv.reportes.*') ? 'active' : 'text-dark' }}">
                    <i class="bi bi-bar-chart"></i>
                    <span>Reportes PdV</span>
                </a>
                <a href="{{ route('pdv.stock.index') }}"
                   class="nav-link mb-2 d-flex align-items-center gap-2 {{ request()->routeIs('pdv.stock.*') ? 'active' : 'text-dark' }}">
                    <i class="bi bi-box-seam"></i>
                    <span>Stock</span>
                </a>
                <a href="{{ route('traslados') }}"
                   class="nav-link mb-2 d-flex align-items-center gap-2 {{ request()->routeIs('traslados*') ? 'active' : 'text-dark' }}">
                    <i class="bi bi-arrow-left-right"></i>
                    <span>Traslados</span>
                </a>
            @endif

            @if(auth()->user()->hasRole(['auxiliar_venta']) && !auth()->user()->hasRole(['admin', 'cajero_principal']))
                <a href="{{ route('pdv.prefacturas.crear') }}"
                   class="nav-link mb-2 d-flex align-items-center gap-2 {{ request()->routeIs('pdv.prefacturas.crear') ? 'active' : 'text-dark' }}">
                    <i class="bi bi-plus-circle"></i>
                    <span>Crear Prefactura</span>
                </a>
                <a href="{{ route('pdv.prefacturas.index') }}"
                   class="nav-link mb-2 d-flex align-items-center gap-2 {{ request()->routeIs('pdv.prefacturas.index') ? 'active' : 'text-dark' }}">
                    <i class="bi bi-receipt"></i>
                    <span>Mis Prefacturas</span>
                </a>
            @endif
        @endif


        {{-- Portal Cliente (solo para rol cliente) --}}
        @if(auth()->user()->hasRole('cliente'))
            <div class="border-top my-2" style="border-color: var(--miracle-lilac) !important;"></div>
            <p class="nav-link mb-1 text-muted small fw-semibold text-uppercase">
                <i class="bi bi-person-circle me-1"></i>
                <span>Mi Portal</span>
            </p>
            <a href="{{ route('portal.dashboard') }}"
               class="nav-link mb-2 d-flex align-items-center gap-2 {{ request()->routeIs('portal.dashboard') ? 'active' : 'text-dark' }}">
                <i class="bi bi-speedometer2"></i>
                <span>Dashboard</span>
            </a>
            <a href="{{ route('portal.historial') }}"
               class="nav-link mb-2 d-flex align-items-center gap-2 {{ request()->routeIs('portal.historial') ? 'active' : 'text-dark' }}">
                <i class="bi bi-clock-history"></i>
                <span>Mis Pedidos</span>
            </a>
        @endif

        @if(auth()->user()->hasRole(['admin', 'garantias']))
            <div class="border-top my-2" style="border-color: var(--miracle-lilac) !important;"></div>
            <p class="nav-link mb-1 text-muted small fw-semibold text-uppercase">
                <i class="bi bi-shield-check me-1"></i>
                <span>Garantías</span>
            </p>
            <a href="{{ route('garantias.index') }}"
               class="nav-link mb-2 d-flex align-items-center gap-2 {{ request()->routeIs('garantias.*') ? 'active' : 'text-dark' }}">
                <i class="bi bi-shield-check"></i>
                <span>Garantías</span>
            </a>
        @endif
    </nav>

    {{-- Botón Salir --}}
    <div class="mt-auto p-3 border-top" style="border-color: var(--miracle-lilac) !important;">
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="btn btn-logout" title="Cerrar sesión">
                <i class="bi bi-box-arrow-right"></i>
                <span class="logout-label">Salir</span>
            </button>
        </form>
    </div>
</div>
