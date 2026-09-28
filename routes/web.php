<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\UsuariosController;
use App\Http\Controllers\LeadsController;
use App\Http\Controllers\LlamadasController;
use App\Http\Controllers\ClientesController;
use App\Http\Controllers\SalesController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\CiudadController;
use App\Http\Controllers\CategoriasController;
use App\Http\Controllers\ProductosController;
use App\Http\Controllers\CatalogoController;
use App\Http\Controllers\SolicitudController;
use App\Http\Controllers\ActualizacionPreciosController;
use App\Http\Controllers\ListaPreciosController;
use App\Http\Controllers\UbicacionesController;
use App\Http\Controllers\TrasladosController;
use App\Http\Controllers\GarantiaController;
use App\Http\Controllers\FeriaController;
use App\Http\Controllers\VendedoraPrefacturaController;
use App\Http\Controllers\PortalClienteController;
use App\Http\Controllers\PuntoVentaController;
use App\Http\Controllers\Pdv\PdvDashboardController;
use App\Http\Controllers\Pdv\CajasController;
use App\Http\Controllers\Pdv\SesionesCajaController;
use App\Http\Controllers\Pdv\VentaPdvController;
use App\Http\Controllers\Pdv\PrefacturasController;
use App\Http\Controllers\Pdv\ValesCajaController;
use App\Http\Controllers\Pdv\ReportesPdvController;
use App\Http\Controllers\Pdv\StockPdvController;
use App\Http\Controllers\Pdv\SiigoConfigController;
/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::redirect('/', '/login'); // 302 por defecto

Route::get('/dashboard',[HomeController::class, 'index'] )->middleware(['auth', 'verified'])->name('dashboard');

// Dashboard de Métricas (solo admin)
Route::get('/dashboard-metricas', [App\Http\Controllers\DashboardMetricasController::class, 'index'])
    ->middleware(['auth', 'role:admin'])
    ->name('dashboard.metricas');

// Exportaciones de métricas (solo admin)
Route::middleware(['auth', 'role:admin'])->prefix('reportes')->name('reportes.')->group(function () {
    Route::get('/ventas-excel', [HomeController::class, 'exportarVentasExcel'])->name('ventas.excel');
    Route::get('/metricas-pdf', [HomeController::class, 'exportarMetricasPdf'])->name('metricas.pdf');
});

// ============================================================
// MÉTRICAS DE PRODUCTOS (solo admin)
// ============================================================
Route::middleware(['auth', 'role:admin'])
    ->prefix('metricas-productos')
    ->name('metricas.productos.')
    ->group(function () {
        Route::get('/', [\App\Http\Controllers\MetricasProductosController::class, 'index'])->name('index');
        Route::get('/kpis', [\App\Http\Controllers\MetricasProductosController::class, 'kpis'])->name('kpis');
        Route::get('/graficas', [\App\Http\Controllers\MetricasProductosController::class, 'graficas'])->name('graficas');
        Route::get('/graficas/data', [\App\Http\Controllers\MetricasProductosController::class, 'graficasData'])->name('graficas.data');
    });

Route::middleware('auth')->group(function () {
    // Perfil - Todos los usuarios autenticados
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // AJAX Ciudades - Disponible para todos los usuarios autenticados
    Route::get('ajax/ciudades', [CiudadController::class,'byDepartamento'])
         ->name('ajax.ciudades');
});

// ============================================================
// USUARIOS (Admin, Inventarios y Facturación)
// ============================================================
Route::middleware(['auth', 'role:admin,auxiliar_administrativo,inventarios,facturacion'])->group(function () {
    Route::get('/usuarios', [UsuariosController::class, 'index'])->name('usuarios');
    Route::get('/importar_usuarios', [UsuariosController::class, 'importar_usuarios'])->name('importar_usuarios');
    Route::get('/usuarios_form/{user?}', [UsuariosController::class, 'form'])->name('usuarios.form');
    Route::post('/usuarios/guardar', [UsuariosController::class, 'guardar'])->name('usuarios.guardar');
});

// ============================================================
// CATEGORÍAS (Admin e Inventarios)
// ============================================================
Route::middleware(['auth', 'role:admin,auxiliar_administrativo,inventarios'])->group(function () {
    Route::get('categorias', [CategoriasController::class, 'index'])->name('categorias');
    Route::get('categorias/form/{categoria?}', [CategoriasController::class, 'form'])->name('categorias.form');
    Route::post('categorias/guardar', [CategoriasController::class, 'guardar'])->name('categorias.guardar');
    Route::delete('categorias/{categoria}/eliminar', [CategoriasController::class, 'eliminar'])->name('categorias.eliminar');
});

// ============================================================
// PRODUCTOS (Admin, Inventarios y Facturación)
// ============================================================
Route::middleware(['auth', 'role:admin,auxiliar_administrativo,inventarios,facturacion'])->prefix('productos')->group(function () {
    Route::get('/', [ProductosController::class, 'index'])->name('productos');
    Route::get('/form/{producto?}', [ProductosController::class, 'form'])->name('productos.form');
    Route::post('/guardar', [ProductosController::class, 'guardar'])->name('productos.guardar');
    Route::delete('/{id}/eliminar', [ProductosController::class, 'eliminar'])->name('productos.eliminar');
    Route::get('/{producto}/variantes-ajax', [ProductosController::class, 'variantesAjax'])->name('productos.variantes-ajax');
    Route::get('/{producto}/imagenes-ajax', [ProductosController::class, 'imagenesAjax'])->name('productos.imagenes-ajax');
    Route::get('/{producto}/precios-ajax', [ProductosController::class, 'preciosAjax'])->name('productos.precios-ajax');
    Route::get('/{producto}/stock-ajax', [ProductosController::class, 'stockAjax'])->name('productos.stock-ajax');
    Route::get('/exportar-con-imagenes', [ProductosController::class, 'exportarConImagenes'])->name('productos.exportar-con-imagenes');
});

// Homologación con SIIGO — accesible para roles administrativos, inventarios, facturación y PdV
Route::middleware(['auth', 'role:admin,auxiliar_administrativo,inventarios,facturacion,cajero_principal,auxiliar_venta,vendedor'])
    ->prefix('productos')->group(function () {
    Route::get('/siigo/productos', [ProductosController::class, 'siigoProductosAjax'])->name('productos.siigo.listar');
    Route::post('/siigo/sincronizar', [ProductosController::class, 'siigoSincronizarCatalogo'])->name('productos.siigo.sincronizar');
    Route::get('/{producto}/siigo/homologacion', [ProductosController::class, 'siigoHomologacionAjax'])->name('productos.siigo.homologacion');
    Route::post('/{producto}/siigo/homologar', [ProductosController::class, 'siigoHomologarProducto'])->name('productos.siigo.homologar');
});

// ============================================================
// RUTAS SOLO ADMIN
// ============================================================
Route::middleware(['auth', 'role:admin,auxiliar_administrativo'])->group(function () {
    // Descargar actualización de precios
    Route::get('actualizaciones/{id}/descargar',
        [ActualizacionPreciosController::class, 'descargarArchivoActualizacion']
    )->name('actualizaciones.descargar');
});

// ============================================================
// LISTAS DE PRECIOS (Admin, Auxiliar Administrativo y Facturación)
// ============================================================
Route::middleware(['auth', 'role:admin,auxiliar_administrativo,facturacion'])->group(function () {
    Route::get('listas-precios', [ListaPreciosController::class, 'index'])->name('listas-precios');
    Route::get('listas-precios/form/{listaPrecio?}', [ListaPreciosController::class, 'form'])->name('listas-precios.form');
    Route::post('listas-precios/guardar', [ListaPreciosController::class, 'guardar'])->name('listas-precios.guardar');
    Route::post('listas-precios/{listaPrecio}/toggle-estado', [ListaPreciosController::class, 'toggleEstado'])->name('listas-precios.toggle-estado');
});

// ============================================================
// RUTAS ADMIN, VENDEDOR, INVENTARIOS Y FACTURACIÓN
// ============================================================
Route::middleware(['auth', 'role:admin,auxiliar_administrativo,vendedor,inventarios,facturacion'])->group(function () {
    // Clientes - Listado (todos pueden ver)
    Route::get('clientes', [ClientesController::class, 'index'])->name('clientes');
    // Documentos - Descargar (todos pueden descargar)
    Route::get('documentos-cliente/{documento}/descargar', [ClientesController::class, 'descargarDocumento'])->name('clientes.documentos.descargar');
});

// Admin, Inventarios y Facturación pueden crear/editar clientes
Route::middleware(['auth', 'role:admin,auxiliar_administrativo,inventarios,facturacion'])->group(function () {
    // Clientes - Crear/Editar
    Route::get('clientes/form/{cliente?}', [ClientesController::class, 'form'])->name('clientes.form');
    Route::post('clientes/guardar', [ClientesController::class, 'guardar'])->name('clientes.guardar');
    Route::delete('clientes/{cliente}/eliminar', [ClientesController::class, 'eliminar'])->name('clientes.eliminar');

    // Sucursales de clientes
    Route::post('clientes/{cliente}/sucursales', [ClientesController::class, 'guardarSucursal'])->name('clientes.sucursales.guardar');
    Route::delete('sucursales/{sucursal}', [ClientesController::class, 'eliminarSucursal'])->name('clientes.sucursales.eliminar');

    // Documentos de clientes
    Route::post('clientes/{cliente}/documentos', [ClientesController::class, 'subirDocumento'])->name('clientes.documentos.subir');
    Route::delete('documentos-cliente/{documento}', [ClientesController::class, 'eliminarDocumento'])->name('clientes.documentos.eliminar');
});
// ============================================================
// CATÁLOGO Y ENLACES (Admin y Vendedor)
// ============================================================

// Catálogo público con token (sin autenticación)
Route::get('/catalogo/{token}', [App\Http\Controllers\CatalogoController::class, 'mostrarPorToken'])->name('catalogo.token');

// Rutas AJAX del catálogo (pueden ser públicas o autenticadas)
Route::post('/catalogo/productos', [CatalogoController::class, 'obtenerProductos'])->name('catalogo.productos');
Route::get('/catalogo/producto/{producto}', [CatalogoController::class, 'detalleProducto'])->name('catalogo.producto.detalle');
Route::post('/catalogo/solicitud', [CatalogoController::class, 'guardarSolicitud'])->name('catalogo.solicitud.guardar');

// Enlaces y Catálogo autenticado (admin y vendedor)
Route::middleware(['auth', 'role:admin,auxiliar_administrativo,vendedor'])->group(function () {
    // Enlaces temporales
    Route::get('/enlaces', [App\Http\Controllers\EnlacesController::class, 'index'])->name('enlaces');
    Route::get('/enlaces/crear', [App\Http\Controllers\EnlacesController::class, 'crear'])->name('enlaces.crear');
    Route::post('/enlaces/guardar', [App\Http\Controllers\EnlacesController::class, 'guardar'])->name('enlaces.guardar');
    Route::get('/enlaces/{enlace}/detalle', [App\Http\Controllers\EnlacesController::class, 'detalle'])->name('enlaces.detalle');
    Route::post('/enlaces/{enlace}/cambiar-estado', [App\Http\Controllers\EnlacesController::class, 'cambiarEstado'])->name('enlaces.cambiar-estado');

    // Catálogo autenticado
    Route::get('/catalogo', [CatalogoController::class, 'index'])->name('catalogo');
    Route::post('/catalogo/cliente', [CatalogoController::class, 'mostrarParaCliente'])->name('catalogo.cliente');
});

// ============================================================
// COTIZACIONES/SOLICITUDES - Lectura (Admin, Vendedor, Facturación, Auxiliar Inventario y Garantías)
// ============================================================
Route::middleware(['auth', 'role:admin,auxiliar_administrativo,vendedor,facturacion,inventarios,auxiliar_inventario,garantias'])->group(function () {
    // Listado principal
    Route::get('/solicitudes', [SolicitudController::class, 'index'])->name('solicitudes');

    // Ver detalle
    Route::get('/solicitudes/{solicitud}/detalle', [SolicitudController::class, 'detalle'])->name('solicitudes.detalle');

    // Exportar PDF
    Route::get('/solicitudes/{solicitud}/pdf', [SolicitudController::class, 'descargarPdf'])->name('solicitudes.pdf');
});

// COTIZACIONES/SOLICITUDES - Acciones (Admin, Vendedor, Facturación e Inventarios)
Route::middleware(['auth', 'role:admin,auxiliar_administrativo,vendedor,facturacion,inventarios'])->group(function () {
    // Acciones de estado (métodos antiguos, mantener por compatibilidad)
    Route::post('/solicitudes/{solicitud}/aplicar', [SolicitudController::class, 'aplicar'])->name('solicitudes.aplicar');
    Route::post('/solicitudes/{solicitud}/rechazar', [SolicitudController::class, 'rechazar'])->name('solicitudes.rechazar');
    Route::post('/solicitudes/{solicitud}/descontar-stock', [SolicitudController::class, 'descontarStock'])->name('solicitudes.descontar-stock');

    // CRUD completo (Fase 5) - Editar/Eliminar solo admin y facturación (ver grupo aparte abajo)

    // Acciones adicionales
    Route::post('/solicitudes/{solicitud}/clonar', [SolicitudController::class, 'clonar'])->name('solicitudes.clonar');
    Route::post('/solicitudes/{solicitud}/cambiar-estado', [SolicitudController::class, 'cambiarEstado'])->name('solicitudes.cambiar-estado');

    // Gestión de reservas
    Route::post('/solicitudes/{solicitud}/renovar-reserva', [SolicitudController::class, 'renovarReserva'])->name('solicitudes.renovar-reserva');
    Route::post('/solicitudes/{solicitud}/liberar-reserva', [SolicitudController::class, 'liberarReserva'])->name('solicitudes.liberar-reserva');

    // AJAX helpers para edición
    Route::get('/solicitudes/productos', [SolicitudController::class, 'getProductos'])->name('solicitudes.productos');
    Route::get('/solicitudes/producto/{producto}/variantes', [SolicitudController::class, 'getVariantes'])->name('solicitudes.variantes');
    Route::post('/solicitudes/precio', [SolicitudController::class, 'getPrecio'])->name('solicitudes.precio');

    // Exportar Excel
    Route::get('/solicitudes/exportar-excel', [SolicitudController::class, 'exportarExcel'])->name('solicitudes.exportar-excel');

    // Toggle marcada (corazón) - inventarios
    Route::post('/solicitudes/{solicitud}/toggle-marcada', [SolicitudController::class, 'toggleMarcada'])->name('solicitudes.toggle-marcada');
});

// ============================================================
// EDICIÓN/ELIMINACIÓN DE COTIZACIONES (Admin, Facturación e Inventarios)
// ============================================================
Route::middleware(['auth', 'role:admin,auxiliar_administrativo,facturacion,inventarios,vendedor'])->group(function () {
    Route::get('/solicitudes/buscar-clientes', [SolicitudController::class, 'buscarClientes'])->name('solicitudes.buscar-clientes');
    Route::get('/solicitudes/{solicitud}/editar', [SolicitudController::class, 'edit'])->name('solicitudes.edit');
    Route::put('/solicitudes/{solicitud}', [SolicitudController::class, 'update'])->name('solicitudes.update');
    Route::delete('/solicitudes/{solicitud}', [SolicitudController::class, 'destroy'])->name('solicitudes.destroy');
});

// Logs de cotizaciones — accesible para roles administrativos y garantías (lectura de auditoría)
Route::middleware(['auth', 'role:admin,auxiliar_administrativo,facturacion,inventarios,vendedor,garantias'])->group(function () {
    Route::get('/solicitudes/{solicitud}/logs', [SolicitudController::class, 'logs'])->name('solicitudes.logs');
});

// ============================================================
// PAGOS Y FACTURACIÓN (Admin, Facturación, Vendedor e Inventarios)
// ============================================================
Route::middleware(['auth', 'role:admin,auxiliar_administrativo,facturacion,vendedor,inventarios'])->group(function () {
    // Pagos
    Route::get('/solicitudes/{solicitud}/pago', [App\Http\Controllers\PagosController::class, 'create'])->name('pagos.create');
    Route::post('/solicitudes/{solicitud}/pago', [App\Http\Controllers\PagosController::class, 'store'])->name('pagos.store');
    Route::get('/solicitudes/{solicitud}/pago/detalle', [App\Http\Controllers\PagosController::class, 'show'])->name('pagos.show');
    Route::get('/solicitudes/{solicitud}/comprobante', [App\Http\Controllers\PagosController::class, 'descargarComprobante'])->name('pagos.comprobante');
    Route::get('/solicitudes/{solicitud}/pagos/{pago}/comprobante', [App\Http\Controllers\PagosController::class, 'descargarComprobantePago'])->name('pagos.comprobante-individual');

    // Aprobación/Rechazo de pagos (solo admin y facturación)
    Route::post('/solicitudes/{solicitud}/pagos/{pago}/aprobar', [App\Http\Controllers\PagosController::class, 'aprobar'])->name('pagos.aprobar');
    Route::post('/solicitudes/{solicitud}/pagos/{pago}/rechazar', [App\Http\Controllers\PagosController::class, 'rechazar'])->name('pagos.rechazar');

    // Facturación
    Route::post('/solicitudes/{solicitud}/factura', [SolicitudController::class, 'generarFactura'])->name('solicitudes.generar-factura');
    Route::get('/solicitudes/{solicitud}/factura/pdf', [SolicitudController::class, 'descargarFactura'])->name('solicitudes.factura-pdf');
});

// ============================================================
// STOCK - Lectura (Admin, Inventarios y Auxiliar Inventario)
// ============================================================
Route::middleware(['auth', 'role:admin,auxiliar_administrativo,inventarios,auxiliar_inventario'])->prefix('stock')->name('stock.')->group(function () {
    // Vistas principales
    Route::get('/', [App\Http\Controllers\StockController::class, 'index'])->name('index');
    Route::get('/dashboard', [App\Http\Controllers\StockController::class, 'dashboard'])->name('dashboard');

    // Consultas AJAX (lectura)
    Route::get('/productos-json', [App\Http\Controllers\StockController::class, 'productosJson'])->name('productos-json');
    Route::get('/{id}/obtener', [App\Http\Controllers\StockController::class, 'obtenerStock'])->name('obtener');
    Route::get('/historial', [App\Http\Controllers\StockController::class, 'historial'])->name('historial');
    Route::get('/reservas', [App\Http\Controllers\StockController::class, 'reservas'])->name('reservas');
    Route::get('/ubicaciones-ajax', [App\Http\Controllers\StockController::class, 'ubicacionesAjax'])->name('ubicaciones-ajax');

    // Reportes (lectura)
    Route::get('/reporte-movimientos', [App\Http\Controllers\StockController::class, 'reporteMovimientos'])->name('reporte-movimientos');
    Route::get('/exportar', [App\Http\Controllers\StockController::class, 'exportar'])->name('exportar');

    // PDFs de notas de movimiento
    Route::get('/movimiento/{id}/pdf', [App\Http\Controllers\StockController::class, 'generarNotaPdf'])->name('movimiento-pdf');
    Route::get('/movimiento/{id}/ver-pdf', [App\Http\Controllers\StockController::class, 'verNotaPdf'])->name('movimiento-ver-pdf');

    // Historial de cambios de código de barras (lectura)
    Route::get('/{id}/codigo-barras-historial-ajax', [App\Http\Controllers\StockController::class, 'historialCodigoBarras'])->name('codigo-barras.historial-ajax');
});

// Operaciones de stock (admin, inventarios y auxiliar_inventario)
Route::middleware(['auth', 'role:admin,auxiliar_administrativo,inventarios,auxiliar_inventario'])->prefix('stock')->name('stock.')->group(function () {
    Route::post('/entrada', [App\Http\Controllers\StockController::class, 'entrada'])->name('entrada');
    Route::post('/salida', [App\Http\Controllers\StockController::class, 'salida'])->name('salida');
    Route::post('/ajuste', [App\Http\Controllers\StockController::class, 'ajuste'])->name('ajuste');
    Route::post('/configurar', [App\Http\Controllers\StockController::class, 'configurar'])->name('configurar');
    Route::post('/agregar-ubicacion', [App\Http\Controllers\StockController::class, 'agregarUbicacion'])->name('agregar-ubicacion');
    Route::post('/importar', [App\Http\Controllers\StockController::class, 'importar'])->name('importar');
    Route::post('/inicializar-todos', [App\Http\Controllers\StockController::class, 'inicializarTodos'])->name('inicializar-todos');

    // Guardar código de barras desde modal de escaneo
    Route::post('/{id}/codigo-barras', [App\Http\Controllers\StockController::class, 'guardarCodigoBarras'])->name('codigo-barras.guardar');

    // Eliminar código de barras desde modal
    Route::delete('/{id}/codigo-barras', [App\Http\Controllers\StockController::class, 'eliminarCodigoBarras'])->name('codigo-barras.eliminar');
});

// ============================================================
// UBICACIONES (Admin e Inventarios)
// ============================================================
Route::middleware(['auth', 'role:admin,auxiliar_administrativo,inventarios'])->group(function () {
    Route::get('ubicaciones', [UbicacionesController::class, 'index'])->name('ubicaciones');
    Route::get('ubicaciones/form/{id?}', [UbicacionesController::class, 'form'])->name('ubicaciones.form');
    Route::post('ubicaciones/guardar', [UbicacionesController::class, 'guardar'])->name('ubicaciones.guardar');
    Route::delete('ubicaciones/{id}/eliminar', [UbicacionesController::class, 'eliminar'])->name('ubicaciones.eliminar');
    Route::post('ubicaciones/{ubicacion}/toggle-estado', [UbicacionesController::class, 'toggleEstado'])->name('ubicaciones.toggle-estado');
    Route::post('ubicaciones/{ubicacion}/marcar-principal', [UbicacionesController::class, 'marcarPrincipal'])->name('ubicaciones.marcar-principal');
    Route::post('ubicaciones/{ubicacion}/reiniciar-inventario', [UbicacionesController::class, 'reiniciarInventario'])->name('ubicaciones.reiniciar-inventario');
});

// ============================================================
// TRASLADOS DE STOCK (Admin, Inventarios y Centro de Experiencia)
// ============================================================
// Ver, aprobar (recibir) y rechazar (cancelar) — accesible para admin, inventarios y centro_experiencia
Route::middleware(['auth', 'role:admin,auxiliar_administrativo,inventarios,centro_experiencia,cajero_principal'])->group(function () {
    Route::get('traslados', [TrasladosController::class, 'index'])->name('traslados');
    Route::get('traslados/{id}/detalle', [TrasladosController::class, 'detalle'])->name('traslados.detalle');
    Route::get('traslados/{id}/pdf', [TrasladosController::class, 'generarPdf'])->name('traslados.pdf');
    Route::get('traslados/{id}/logs', [TrasladosController::class, 'logs'])->name('traslados.logs');
    Route::post('traslados/{id}/recibir', [TrasladosController::class, 'recibir'])->name('traslados.recibir');
    Route::post('traslados/{id}/cancelar', [TrasladosController::class, 'cancelar'])->name('traslados.cancelar');
});

// Crear, enviar y AJAX — solo admin e inventarios
Route::middleware(['auth', 'role:admin,auxiliar_administrativo,inventarios,cajero_principal'])->group(function () {
    Route::get('traslados/form/{id?}', [TrasladosController::class, 'form'])->name('traslados.form');
    Route::post('traslados/guardar', [TrasladosController::class, 'guardar'])->name('traslados.guardar');
    Route::post('traslados/{id}/actualizar', [TrasladosController::class, 'actualizar'])->name('traslados.actualizar');
    Route::post('traslados/{id}/enviar', [TrasladosController::class, 'enviar'])->name('traslados.enviar');
    Route::get('traslados/variantes/{productoId}', [TrasladosController::class, 'getVariantesPorProducto'])->name('traslados.variantes');
    Route::get('traslados/stock-disponible', [TrasladosController::class, 'getStockDisponible'])->name('traslados.stock-disponible');
    Route::get('traslados/productos-por-ubicacion/{ubicacionId}', [TrasladosController::class, 'getProductosPorUbicacion'])->name('traslados.productos-por-ubicacion');
    Route::get('traslados/variantes-por-ubicacion/{productoId}/{ubicacionId}', [TrasladosController::class, 'getVariantesPorProductoYUbicacion'])->name('traslados.variantes-por-ubicacion');
});

// ============================================================
// GARANTÍAS (Admin y Garantías)
// ============================================================
Route::middleware(['auth', 'role:admin,garantias'])->prefix('garantias')->group(function () {
    Route::get('/', [GarantiaController::class, 'index'])->name('garantias.index');
    Route::get('/crear', [GarantiaController::class, 'create'])->name('garantias.crear');
    Route::post('/', [GarantiaController::class, 'store'])->name('garantias.store');
    Route::get('/buscar-productos', [GarantiaController::class, 'buscarProductos'])->name('garantias.buscar-productos');
    Route::get('/productos-por-ubicacion/{ubicacionId}', [GarantiaController::class, 'productosPorUbicacion'])->name('garantias.productos-por-ubicacion');
    Route::get('/variantes-por-ubicacion/{productoId}/{ubicacionId}', [GarantiaController::class, 'variantesPorProductoYUbicacion'])->name('garantias.variantes-por-ubicacion');
    Route::get('/cliente/{id}/pendientes', [GarantiaController::class, 'garantiasPendientesCliente'])->name('garantias.cliente.pendientes');
    Route::get('/documentos/{id}/descargar', [GarantiaController::class, 'descargarDocumento'])->name('garantias.documentos.descargar');
    Route::get('/{id}/pdf', [GarantiaController::class, 'descargarPdf'])->name('garantias.pdf');
    Route::get('/{id}', [GarantiaController::class, 'show'])->name('garantias.show');
    Route::post('/{id}/liberar', [GarantiaController::class, 'liberar'])->name('garantias.liberar');
});

// ============================================================
// VENDEDORAS DE PREFACTURA (Admin) — CRUD
// ============================================================
Route::middleware(['auth', 'role:admin'])->prefix('pdv/vendedoras')->name('pdv.vendedoras.')->group(function () {
    Route::get('/', [VendedoraPrefacturaController::class, 'index'])->name('index');
    Route::post('/', [VendedoraPrefacturaController::class, 'store'])->name('store');
    Route::post('/{id}', [VendedoraPrefacturaController::class, 'update'])->name('update');
    Route::post('/{id}/toggle', [VendedoraPrefacturaController::class, 'toggle'])->name('toggle');
    Route::post('/{id}/eliminar', [VendedoraPrefacturaController::class, 'destroy'])->name('destroy');
});

// ============================================================
// MÓDULO DE FERIAS (Admin e Inventarios)
// ============================================================
Route::middleware(['auth', 'role:admin,auxiliar_administrativo,inventarios'])->prefix('ferias')->group(function () {
    Route::get('/', [FeriaController::class, 'index'])->name('ferias.index');
    Route::get('/crear', [FeriaController::class, 'create'])->name('ferias.crear');
    Route::post('/', [FeriaController::class, 'store'])->name('ferias.store');
    Route::get('/{id}/buscar-productos', [FeriaController::class, 'buscarProductos'])->name('ferias.buscar-productos');
    Route::post('/{id}/precio', [FeriaController::class, 'actualizarPrecio'])->name('ferias.actualizar-precio');
    Route::post('/{id}/precios-masivos', [FeriaController::class, 'preciosMasivos'])->name('ferias.precios-masivos');
    Route::get('/{id}/promociones', [FeriaController::class, 'promociones'])->name('ferias.promociones');
    Route::post('/{id}/promociones', [FeriaController::class, 'crearPromocion'])->name('ferias.promociones.crear');
    Route::post('/{id}/promociones/{promoId}/eliminar', [FeriaController::class, 'eliminarPromocion'])->name('ferias.promociones.eliminar');
    Route::get('/{id}/inventario', [FeriaController::class, 'inventario'])->name('ferias.inventario');
    Route::get('/{id}/inventario/excel', [FeriaController::class, 'exportarInventarioExcel'])->name('ferias.inventario.excel');
    Route::get('/{id}/traslados-pendientes', [FeriaController::class, 'trasladosPendientes'])->name('ferias.traslados-pendientes');
    Route::post('/{id}/traslados/{trasladoId}/recibir', [FeriaController::class, 'recibirTraslado'])->name('ferias.recibir-traslado');
    Route::get('/{id}/buscar-bodega', [FeriaController::class, 'buscarProductosBodega'])->name('ferias.buscar-bodega');
    Route::post('/{id}/inventario/cargar', [FeriaController::class, 'prepararInventario'])->name('ferias.inventario.cargar');
    Route::post('/{id}/inventario/devolver', [FeriaController::class, 'devolverInventario'])->name('ferias.inventario.devolver');
    Route::post('/{id}/inventario/devolver-todo', [FeriaController::class, 'devolverTodo'])->name('ferias.inventario.devolver-todo');
    Route::post('/{id}/cerrar-con-conteo', [FeriaController::class, 'cerrarConConteo'])->name('ferias.cerrar-con-conteo');
    Route::post('/{id}/activar', [FeriaController::class, 'activar'])->name('ferias.activar');
    Route::post('/{id}/cajera', [FeriaController::class, 'asignarCajera'])->name('ferias.asignar-cajera');
    Route::post('/{id}/cerrar', [FeriaController::class, 'cerrar'])->name('ferias.cerrar');
    Route::get('/{id}', [FeriaController::class, 'show'])->name('ferias.show');
});

// ============================================================
// IMPORTACIÓN DE PRODUCTOS (Admin e Inventarios)
// ============================================================
Route::middleware(['auth', 'role:admin,auxiliar_administrativo,inventarios'])->group(function () {
    Route::get('/productos/importacion/descargar-plantilla-csv', [App\Http\Controllers\ImportacionProductosController::class, 'descargarPlantillaCsv'])->name('productos.importacion.descargar-plantilla-csv');
    Route::get('/productos/importacion/descargar-plantilla-excel', [App\Http\Controllers\ImportacionProductosController::class, 'descargarPlantillaExcel'])->name('productos.importacion.descargar-plantilla-excel');
    Route::post('/productos/importar-productos', [App\Http\Controllers\ImportacionProductosController::class, 'importarProductos'])->name('productos.importacion.importar');
    Route::get('/productos/historial-importaciones', [App\Http\Controllers\ImportacionProductosController::class, 'historial'])->name('productos.importacion.historial');
    Route::get('/productos/importacion/{id}', [App\Http\Controllers\ImportacionProductosController::class, 'verDetalle'])->name('productos.importacion.detalle');
});

// ACTUALIZACIÓN DE PRECIOS (Solo Admin)
// ============================================================
Route::middleware(['auth', 'role:admin,auxiliar_administrativo'])->group(function () {
    Route::post('/productos/actualizar-precios-excel', [ProductosController::class, 'actualizarPreciosExcel'])->name('productos.actualizar-precios-excel');
    Route::get('/productos/historial-precios', [ActualizacionPreciosController::class, 'historial'])->name('productos.historial-precios');
    Route::get('/productos/actualizacion-precios/{id}', [ActualizacionPreciosController::class, 'verDetalle'])->name('productos.actualizacion-precios.detalle');

    // Plantillas de actualización de precios
    Route::get('/productos/descargar-plantilla-csv', [ActualizacionPreciosController::class, 'descargarPlantillaCsv'])->name('productos.descargar-plantilla-csv');
    Route::get('/productos/descargar-plantilla-excel', [ActualizacionPreciosController::class, 'descargarPlantillaExcel'])->name('productos.descargar-plantilla-excel');
});

// ============================================================
// PORTAL CLIENTE (Rol: cliente)
// ============================================================
Route::middleware(['auth', 'role:cliente'])->prefix('portal')->name('portal.')->group(function () {
    // Dashboard del cliente
    Route::get('/', [PortalClienteController::class, 'dashboard'])->name('dashboard');

    // Historial de pedidos
    Route::get('/historial', [PortalClienteController::class, 'historial'])->name('historial');

    // Ver detalle de pedido
    Route::get('/pedido/{solicitud}', [PortalClienteController::class, 'detallePedido'])->name('pedido.detalle');

    // Seguimiento de envío
    Route::get('/pedido/{solicitud}/seguimiento', [PortalClienteController::class, 'seguimiento'])->name('pedido.seguimiento');

    // Descargar guía de envío
    Route::get('/pedido/{solicitud}/guia', [PortalClienteController::class, 'descargarGuia'])->name('pedido.guia');

    // Descargar factura
    Route::get('/pedido/{solicitud}/factura', [PortalClienteController::class, 'descargarFactura'])->name('pedido.factura');
});

// ============================================================
// GESTIÓN DE ENVÍOS (Admin, Facturación, Inventarios)
// ============================================================
Route::middleware(['auth', 'role:admin,auxiliar_administrativo,facturacion,inventarios,auxiliar_inventario'])->group(function () {
    // Actualizar estado de envío
    Route::post('/solicitudes/{solicitud}/envio', [SolicitudController::class, 'actualizarEnvio'])->name('solicitudes.envio.update');

    // Subir guía de envío
    Route::post('/solicitudes/{solicitud}/guia', [SolicitudController::class, 'subirGuia'])->name('solicitudes.guia.upload');

    // Historial de envío
    Route::get('/solicitudes/{solicitud}/envio/historial', [SolicitudController::class, 'historialEnvio'])->name('solicitudes.envio.historial');
});

// ============================================================
// PUNTO DE VENTA V2 — Módulo Caja / PdV (Fase 2)
// ============================================================

// Legacy POS redirects
Route::middleware(['auth'])->prefix('punto-venta')->group(function () {
    Route::get('/', fn() => redirect()->route('pdv.dashboard'));
    Route::get('/nueva', fn() => redirect()->route('pdv.ventas.crear'));
    Route::get('/ventas', fn() => redirect()->route('pdv.ventas.index'));
    Route::get('/reportes', fn() => redirect()->route('pdv.reportes.index'));
});

// PdV Dashboard — All PdV roles
Route::middleware(['auth', 'role:admin,cajero_principal,auxiliar_venta,vendedor'])
    ->prefix('pdv')->name('pdv.')->group(function () {
        Route::get('/', [PdvDashboardController::class, 'index'])->name('dashboard');
    });

// Abrir cajón monedero — Cajero Principal
Route::middleware(['auth', 'role:admin,cajero_principal'])
    ->prefix('pdv/caja')->name('pdv.caja.')->group(function () {
        Route::get('/abrir-cajon', fn() => view('pdv.pdf.abrir-cajon'))->name('abrir-cajon');
    });

// Caja Configuration — Admin only
Route::middleware(['auth', 'role:admin'])
    ->prefix('pdv/cajas')->name('pdv.cajas.')->group(function () {
        Route::get('/', [CajasController::class, 'index'])->name('index');
        Route::get('/form/{id?}', [CajasController::class, 'form'])->name('form');
        Route::post('/guardar', [CajasController::class, 'guardar'])->name('guardar');
        Route::post('/{id}/toggle', [CajasController::class, 'toggleActivo'])->name('toggle');
        Route::get('/configuracion', [CajasController::class, 'configuracion'])->name('configuracion');
        Route::post('/configuracion', [CajasController::class, 'guardarConfiguracion'])->name('configuracion.guardar');
        Route::post('/pin/guardar', [CajasController::class, 'guardarPin'])->name('pin.guardar');
        Route::post('/pin/eliminar', [CajasController::class, 'eliminarPin'])->name('pin.eliminar');
    });

// Sesiones de Caja — Cajero Principal + Admin
Route::middleware(['auth', 'role:admin,cajero_principal'])
    ->prefix('pdv/sesiones')->name('pdv.sesiones.')->group(function () {
        Route::get('/abrir/{cajaId}', [SesionesCajaController::class, 'formAbrir'])->name('abrir.form');
        Route::post('/abrir', [SesionesCajaController::class, 'abrir'])->name('abrir');
        Route::get('/cerrar/{sesionId}', [SesionesCajaController::class, 'formCerrar'])->name('cerrar.form');
        Route::post('/cerrar', [SesionesCajaController::class, 'cerrar'])->name('cerrar');
        Route::get('/historial', [SesionesCajaController::class, 'historial'])->name('historial');
        Route::get('/{id}/resumen', [SesionesCajaController::class, 'resumen'])->name('resumen');
        Route::get('/{id}/ticket-print', [SesionesCajaController::class, 'ticketPrintCierre'])->name('ticket-print');
    });

// Ventas PdV — Cajero Principal + Admin
Route::middleware(['auth', 'role:admin,cajero_principal'])
    ->prefix('pdv/ventas')->name('pdv.ventas.')->group(function () {
        Route::get('/', [VentaPdvController::class, 'index'])->name('index');
        Route::post('/actualizar-estados-pendientes', [VentaPdvController::class, 'actualizarEstadosPendientes'])->name('actualizar-estados-pendientes');
        Route::get('/crear', [VentaPdvController::class, 'crear'])->name('crear');
        Route::post('/procesar', [VentaPdvController::class, 'procesarVenta'])->name('procesar');
        Route::get('/{id}/detalle', [VentaPdvController::class, 'detalle'])->name('detalle');
        Route::get('/{id}/ticket', [VentaPdvController::class, 'ticket'])->name('ticket');
        Route::get('/{id}/ticket-print', [VentaPdvController::class, 'ticketPrint'])->name('ticket-print');
        // AJAX
        Route::get('/buscar-productos', [VentaPdvController::class, 'buscarProductos'])->name('buscar-productos');
        Route::get('/buscar-clientes', [VentaPdvController::class, 'buscarClientes'])->name('buscar-clientes');
        Route::post('/obtener-precios', [VentaPdvController::class, 'obtenerPrecios'])->name('obtener-precios');
        Route::post('/verificar-stock', [VentaPdvController::class, 'verificarStock'])->name('verificar-stock');
        Route::post('/cliente-rapido', [VentaPdvController::class, 'crearClienteRapido'])->name('cliente-rapido');
        Route::post('/verificar-pin', [VentaPdvController::class, 'verificarPin'])->name('verificar-pin');
        // Facturación electrónica SIIGO
        Route::post('/{id}/factura/generar', [VentaPdvController::class, 'generarFactura'])->name('factura.generar');
        Route::post('/{id}/factura/reintentar', [VentaPdvController::class, 'reintentarFactura'])->name('factura.reintentar');
        Route::get('/{id}/factura/pdf', [VentaPdvController::class, 'descargarFacturaPdf'])->name('factura.pdf');
        Route::post('/{id}/factura/reenviar', [VentaPdvController::class, 'reenviarFacturaEmail'])->name('factura.reenviar');
        Route::get('/{id}/factura/estado', [VentaPdvController::class, 'consultarEstadoFactura'])->name('factura.estado');
        Route::post('/{id}/nota-credito/{ncId}/reintentar', [VentaPdvController::class, 'reintentarNotaCredito'])->name('nota-credito.reintentar');
    });

// Anulación de ventas — Admin only
Route::middleware(['auth', 'role:admin'])
    ->prefix('pdv/ventas')->name('pdv.ventas.')->group(function () {
        Route::post('/{id}/anular', [VentaPdvController::class, 'anular'])->name('anular');
        Route::get('/{id}/items-devolucion', [VentaPdvController::class, 'itemsParaDevolucion'])->name('items-devolucion');
        Route::post('/{id}/devolucion-parcial', [VentaPdvController::class, 'devolverParcial'])->name('devolucion-parcial');
    });

// SIIGO Configuración — Admin only
Route::middleware(['auth', 'role:admin'])
    ->prefix('pdv/siigo')->name('pdv.siigo.')->group(function () {
        Route::get('/configuracion', [SiigoConfigController::class, 'index'])->name('config');
        Route::post('/configuracion', [SiigoConfigController::class, 'guardar'])->name('config.guardar');
        Route::post('/test-conexion', [SiigoConfigController::class, 'testConexion'])->name('test');
        Route::get('/catalogos', [SiigoConfigController::class, 'cargarCatalogos'])->name('catalogos');
    });

// Prefacturas — Role-based access
Route::middleware(['auth', 'role:admin,cajero_principal,auxiliar_venta'])
    ->prefix('pdv/prefacturas')->name('pdv.prefacturas.')->group(function () {
        Route::get('/', [PrefacturasController::class, 'index'])->name('index');
        Route::get('/{id}/detalle', [PrefacturasController::class, 'detalle'])->name('detalle');
    });

Route::middleware(['auth', 'role:admin,auxiliar_venta'])
    ->prefix('pdv/prefacturas')->name('pdv.prefacturas.')->group(function () {
        Route::get('/crear', [PrefacturasController::class, 'crear'])->name('crear');
        Route::post('/guardar', [PrefacturasController::class, 'guardar'])->name('guardar');
    });

// AJAX compartido PdV — Todos los roles PdV (buscar productos/clientes)
Route::middleware(['auth', 'role:admin,cajero_principal,auxiliar_venta'])
    ->prefix('pdv/ajax')->name('pdv.ajax.')->group(function () {
        Route::get('/buscar-productos', [VentaPdvController::class, 'buscarProductos'])->name('buscar-productos');
        Route::get('/buscar-clientes', [VentaPdvController::class, 'buscarClientes'])->name('buscar-clientes');
        Route::post('/obtener-precios', [VentaPdvController::class, 'obtenerPrecios'])->name('obtener-precios');
        Route::post('/cliente-rapido', [VentaPdvController::class, 'crearClienteRapido'])->name('cliente-rapido');
    });

Route::middleware(['auth', 'role:admin,cajero_principal'])
    ->prefix('pdv/prefacturas')->name('pdv.prefacturas.')->group(function () {
        Route::get('/pendientes', [PrefacturasController::class, 'pendientes'])->name('pendientes');
        Route::post('/{id}/aceptar', [PrefacturasController::class, 'aceptar'])->name('aceptar');
        Route::post('/{id}/anular', [PrefacturasController::class, 'anular'])->name('anular');
        Route::post('/{id}/actualizar', [PrefacturasController::class, 'actualizar'])->name('actualizar');
    });

// Vales — Cajero Principal + Admin
Route::middleware(['auth', 'role:admin,cajero_principal'])
    ->prefix('pdv/vales')->name('pdv.vales.')->group(function () {
        Route::get('/', [ValesCajaController::class, 'index'])->name('index');
        Route::post('/guardar', [ValesCajaController::class, 'guardar'])->name('guardar');
        Route::post('/{id}/redimir', [ValesCajaController::class, 'redimir'])->name('redimir');
        Route::post('/{id}/anular', [ValesCajaController::class, 'anular'])->name('anular');
        Route::get('/{id}/ticket-print', [ValesCajaController::class, 'ticketPrint'])->name('ticket-print');
        Route::get('/exportar-excel', [ValesCajaController::class, 'exportarExcel'])->name('exportar-excel');
        Route::get('/exportar-pdf', [ValesCajaController::class, 'exportarPdf'])->name('exportar-pdf');
    });

// Reportes PdV — Cajero Principal + Admin
Route::middleware(['auth', 'role:admin,cajero_principal'])
    ->prefix('pdv/reportes')->name('pdv.reportes.')->group(function () {
        Route::get('/', [ReportesPdvController::class, 'index'])->name('index');
        Route::get('/ventas-diarias', [ReportesPdvController::class, 'ventasDiarias'])->name('ventas-diarias');
        Route::get('/cierre-turno', [ReportesPdvController::class, 'cierreTurno'])->name('cierre-turno');
        Route::get('/top-productos', [ReportesPdvController::class, 'topProductos'])->name('top-productos');
        Route::get('/comparativa-cajas', [ReportesPdvController::class, 'comparativaCajas'])->name('comparativa-cajas');
        Route::get('/vales', [ReportesPdvController::class, 'reporteVales'])->name('vales');
        Route::get('/prefacturas', [ReportesPdvController::class, 'reportePrefacturas'])->name('prefacturas');
        Route::get('/exportar', [ReportesPdvController::class, 'exportar'])->name('exportar');
    });

// Stock visual PdV — Admin + Cajero Principal
Route::middleware(['auth', 'role:admin,cajero_principal'])
    ->get('pdv/stock', [StockPdvController::class, 'index'])->name('pdv.stock.index');

// ============================================================
// MÓDULO DE SERVICIO TÉCNICO (Admin y Técnico)
// ============================================================
Route::middleware(['auth', 'role:admin,auxiliar_administrativo,tecnico'])->prefix('servicio-tecnico')->name('st.')->group(function () {
    // Dashboard
    Route::get('/dashboard', [App\Http\Controllers\ServicioTecnico\DashboardSTController::class, 'index'])->name('dashboard');

    // Clientes de Servicio Técnico
    Route::resource('clientes', App\Http\Controllers\ServicioTecnico\STClienteController::class)->parameters(['clientes' => 'cliente']);

    // Técnicos
    Route::resource('tecnicos', App\Http\Controllers\ServicioTecnico\STTecnicoController::class)->parameters(['tecnicos' => 'tecnico']);

    // Equipos
    Route::resource('equipos', App\Http\Controllers\ServicioTecnico\STEquipoController::class)->parameters(['equipos' => 'equipo']);
    Route::get('equipos/cliente/{cliente}', [App\Http\Controllers\ServicioTecnico\STEquipoController::class, 'porCliente'])->name('equipos.por-cliente');

    // Órdenes de Servicio
    Route::resource('ordenes', App\Http\Controllers\ServicioTecnico\STOrdenServicioController::class)->parameters(['ordenes' => 'orden']);
    Route::post('ordenes/{orden}/cambiar-estado', [App\Http\Controllers\ServicioTecnico\STOrdenServicioController::class, 'cambiarEstado'])->name('ordenes.cambiar-estado');
    Route::get('ordenes/{orden}/pdf', [App\Http\Controllers\ServicioTecnico\STOrdenServicioController::class, 'generarPdf'])->name('ordenes.pdf');
    Route::get('equipos-cliente/{clienteId}', [App\Http\Controllers\ServicioTecnico\STOrdenServicioController::class, 'getEquiposByCliente'])->name('equipos-cliente');

    // Diagnósticos
    Route::post('ordenes/{orden}/diagnosticos', [App\Http\Controllers\ServicioTecnico\STOrdenServicioController::class, 'agregarDiagnostico'])->name('ordenes.diagnostico.store');
    Route::post('diagnosticos', [App\Http\Controllers\ServicioTecnico\STDiagnosticoController::class, 'store'])->name('diagnosticos.store');
    Route::put('diagnosticos/{diagnostico}', [App\Http\Controllers\ServicioTecnico\STDiagnosticoController::class, 'update'])->name('diagnosticos.update');

    // Repuestos
    Route::resource('repuestos', App\Http\Controllers\ServicioTecnico\STRepuestoController::class)->parameters(['repuestos' => 'repuesto']);
    Route::get('repuestos-json', [App\Http\Controllers\ServicioTecnico\STRepuestoController::class, 'json'])->name('repuestos.json');

    // Agregar/Quitar repuestos a órdenes
    Route::post('ordenes/{orden}/repuestos', [App\Http\Controllers\ServicioTecnico\STOrdenServicioController::class, 'agregarRepuesto'])->name('ordenes.repuesto.store');
    Route::delete('repuestos-usados/{repuestoUsado}', [App\Http\Controllers\ServicioTecnico\STOrdenServicioController::class, 'eliminarRepuesto'])->name('ordenes.eliminar-repuesto');

    // Imágenes
    Route::post('ordenes/{orden}/imagenes', [App\Http\Controllers\ServicioTecnico\STOrdenServicioController::class, 'subirImagen'])->name('ordenes.subir-imagen');
    Route::delete('imagenes/{imagen}', [App\Http\Controllers\ServicioTecnico\STOrdenServicioController::class, 'eliminarImagen'])->name('imagenes.eliminar');

    // Reportes
    Route::get('reportes/ordenes', [App\Http\Controllers\ServicioTecnico\ReportesSTController::class, 'ordenes'])->name('reportes.ordenes');
    Route::get('reportes/tecnicos', [App\Http\Controllers\ServicioTecnico\ReportesSTController::class, 'tecnicos'])->name('reportes.tecnicos');
});

// ============================================================
// SEGUIMIENTO COMERCIAL DEL VENDEDOR (Rol: vendedor) — Módulo aditivo
// ============================================================
Route::middleware(['auth', 'role:vendedor,admin'])
    ->prefix('mi-panel')->name('vendedor.')->group(function () {
        // Etapa 2 — Panel de ventas del vendedor
        Route::get('/', [App\Http\Controllers\Vendedor\PanelVendedorController::class, 'index'])->name('panel');
        // Etapa 3 — Ventas por cliente
        Route::get('/clientes', [App\Http\Controllers\Vendedor\PanelVendedorController::class, 'ventasPorCliente'])->name('clientes');
        // Etapa 4 — Contado vs crédito
        Route::get('/contado-credito', [App\Http\Controllers\Vendedor\PanelVendedorController::class, 'contadoCredito'])->name('contado-credito');
        // Etapa 5 — Seguimiento de pedidos
        Route::get('/seguimiento', [App\Http\Controllers\Vendedor\PanelVendedorController::class, 'seguimiento'])->name('seguimiento');
        // Etapa 6 — Exportación a Excel
        Route::get('/exportar', [App\Http\Controllers\Vendedor\PanelVendedorController::class, 'exportar'])->name('exportar');
    });

// ============================================================
// PANEL ADMIN — Seguimiento comercial por vendedor (una sola pantalla)
// ============================================================
Route::middleware(['auth', 'role:admin'])
    ->prefix('admin/vendedores')->name('admin.vendedores.')->group(function () {
        Route::get('/', [App\Http\Controllers\Admin\PanelAdminVendedoresController::class, 'index'])->name('index');
        Route::get('/exportar', [App\Http\Controllers\Admin\PanelAdminVendedoresController::class, 'exportar'])->name('exportar');
    });

require __DIR__.'/auth.php';
