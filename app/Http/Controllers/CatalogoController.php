<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\EnlaceAcceso;
use App\Models\Cliente;
use App\Models\Producto;
use App\Models\Categoria;
use App\Models\SolicitudCotizacion;
use App\Models\ItemSolicitudCotizacion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Events\CotizacionCreada;
use App\Services\ReservaStockService;
use App\Models\StockProducto;

class CatalogoController extends Controller
{
    /**
     * Flujo A: Acceso por cliente vía link/token
     */
    public function mostrarPorToken($token)
    {
        $enlace = EnlaceAcceso::where('token', $token)->first();
        
        if (!$enlace || !$enlace->esValido()) {
            return view('catalogo.enlace_invalido');
        }
        
        // Registrar acceso
        $enlace->registrarAcceso();
        
        $cliente = $enlace->cliente->load('sucursalesActivas');
        $categorias = Categoria::activas()->get();

        return view('catalogo.index_cliente', compact('enlace', 'cliente', 'categorias'));
    }
    
    /**
     * Flujo B: Acceso por vendedor (Tienda a Tienda)
     */
    public function index()
    {
        // Solo vendedores autenticados
        $this->middleware('auth');

        $user = Auth::user();

        // Admin y auxiliar_administrativo ven todos los clientes, vendedor solo los suyos
        if ($user->hasRole(['admin', 'auxiliar_administrativo'])) {
            $clientes = Cliente::activos()
                              ->with('vendedor')
                              ->orderBy('nombre_contacto')
                              ->get();
        } elseif ($user->hasRole('vendedor')) {
            $clientes = Cliente::activos()
                              ->with('vendedor')
                              ->orderBy('nombre_contacto')
                              ->get();
        } else {
            return redirect()->route('dashboard')->with('error', 'No tiene permisos para acceder al catálogo.');
        }

        return view('catalogo.seleccionar_cliente', compact('clientes'));
    }

    /**
     * Flujo B: Mostrar catálogo para cliente seleccionado
     */
    public function mostrarParaCliente(Request $request)
    {
        $this->middleware('auth');

        $request->validate([
            'cliente_id' => 'required|exists:clientes,id'
        ]);

        $cliente = Cliente::with(['sucursalesActivas', 'garantiasPendientes.documentos', 'garantiasPendientes.producto', 'garantiasPendientes.variante', 'garantiasPendientes.items.producto', 'garantiasPendientes.items.variante'])
            ->findOrFail($request->cliente_id);

        $categorias = Categoria::activas()->get();
        $enlace = null; // No hay enlace en el flujo B
        $ubicaciones = \App\Models\Ubicacion::where('activo', true)->orderBy('nombre')->get(['id', 'nombre', 'tipo']);

        return view('catalogo.index', compact('cliente', 'categorias', 'enlace', 'ubicaciones'));
    }
    
    /**
     * Obtener productos del catálogo (AJAX)
     */
    public function obtenerProductos(Request $request)
    {
        $query = Producto::activos()
            ->with([
                'imagenPrincipal',
                'todasImagenes',
                'categoria',
                'stock' => function($q) {
                    $q->select('producto_id', 'variante_producto_id', 'cantidad_disponible', 'cantidad_reservada', 'stock_maximo', 'ubicacion_id')
                      ->where(function($sub) {
                          $sub->whereNull('ubicacion_id')
                              ->orWhereHas('ubicacionRelacion', fn($u) => $u->where('tipo', '!=', 'tienda'));
                      });
                },
                'variantes' => function($q) {
                    $q->activas()->with(['stock' => function($sq) {
                        $sq->select('producto_id', 'variante_producto_id', 'cantidad_disponible', 'cantidad_reservada', 'stock_maximo', 'ubicacion_id')
                          ->where(function($sub) {
                              $sub->whereNull('ubicacion_id')
                                  ->orWhereHas('ubicacionRelacion', fn($u) => $u->where('tipo', '!=', 'tienda'));
                          });
                    }]);
                }
            ])
            ->select('productos.*'); // Asegurarse de que se incluyan todos los campos, incluyendo unidad_venta
        
        // Filtro por categoría
        if ($request->has('categoria_id') && $request->categoria_id) {
            $query->where('categoria_id', $request->categoria_id);
        }
        
        // Búsqueda por nombre o referencia
        if ($request->has('busqueda') && $request->busqueda) {
            $query->buscar($request->busqueda);
        }
        
        $perPage = max(1, min(50, (int) ($request->per_page ?? 12)));
        $productos = $query->orderBy('nombre')->paginate($perPage);
        
        // Obtener configuración de visualización
        $listaPrecioId = null;
        $mostrarPrecios = false;
        $mostrarStock = false;
        
        if ($request->has('cliente_id')) {
            // Flujo B: Cliente seleccionado por vendedor
            $cliente = Cliente::find($request->cliente_id);
            if ($cliente) {
                $listaPrecioId = $cliente->lista_precio_id;
                $mostrarPrecios = true; // Siempre mostrar precios en flujo B
                $mostrarStock = true;   // Siempre mostrar stock en flujo B
            }
        } elseif ($request->has('enlace_token')) {
            // Flujo A: Acceso por token
            $enlace = EnlaceAcceso::where('token', $request->enlace_token)->first();
            if ($enlace && $enlace->esValido()) {
                $listaPrecioId = $enlace->cliente->lista_precio_id;
                $mostrarPrecios = $enlace->mostrar_precios;
                $mostrarStock = $enlace->mostrar_stock;
            }
        }
        
        // Agregar precios y stock a los productos
        foreach ($productos as $producto) {
            // Agregar precios
            if ($mostrarPrecios && $listaPrecioId) {
                $producto->precio = $producto->getPrecioPorLista($listaPrecioId);
            } else {
                $producto->precio = null;
            }
            
            // Agregar información de stock solo si se muestra Y se controla
            if ($mostrarStock) {
                $producto->stock_info = $this->obtenerStockProducto($producto);
            } else {
                $producto->stock_info = null;
            }
            
            // Asegurarse de que unidad_venta esté disponible en la respuesta
            $producto->unidad_venta = $producto->unidad_venta;

            // Fallback: si no hay imagenPrincipal product-level, usar mejor imagen disponible
            if (!$producto->imagenPrincipal) {
                $mejor = $producto->mejor_imagen;
                if ($mejor) {
                    $producto->setRelation('imagenPrincipal', $mejor);
                }
            }
        }

        return response()->json([
            'productos' => $productos,
            'mostrar_precios' => $mostrarPrecios,
            'mostrar_stock' => $mostrarStock
        ]);
    }
    
    /**
     * Obtener detalle de producto con variantes (AJAX)
     */
    public function detalleProducto(Request $request, Producto $producto)
    {
        $producto->load([
            'variantes' => function($q) {
                $q->activas()->with([
                    'stock' => function($sq) {
                        $sq->select('producto_id', 'variante_producto_id', 'cantidad_disponible', 'cantidad_reservada', 'stock_maximo', 'ubicacion_id')
                          ->where(function($sub) {
                              $sub->whereNull('ubicacion_id')
                                  ->orWhereHas('ubicacionRelacion', fn($u) => $u->where('tipo', '!=', 'tienda'));
                          });
                    },
                    'imagenes' => function($iq) {
                        $iq->orderBy('orden');
                    }
                ]);
            },
            'imagenes' => function($q) {
                $q->orderBy('orden');
            },
            'stock' => function($q) {
                $q->select('producto_id', 'variante_producto_id', 'cantidad_disponible', 'cantidad_reservada', 'stock_maximo', 'ubicacion_id')
                  ->where(function($sub) {
                      $sub->whereNull('ubicacion_id')
                          ->orWhereHas('ubicacionRelacion', fn($u) => $u->where('tipo', '!=', 'tienda'));
                  });
            }
        ]);

        // Obtener configuración según el contexto
        $listaPrecioId = null;
        $mostrarPrecios = false;
        $mostrarStock = false;
        $esFlujoBInterno = false;

        if ($request->has('cliente_id')) {
            $cliente = Cliente::find($request->cliente_id);
            if ($cliente) {
                $listaPrecioId = $cliente->lista_precio_id;
                $mostrarPrecios = true;
                $mostrarStock = true;
                $esFlujoBInterno = true;
            }
        } elseif ($request->has('enlace_token')) {
            $enlace = EnlaceAcceso::where('token', $request->enlace_token)->first();
            if ($enlace && $enlace->esValido()) {
                $listaPrecioId = $enlace->cliente->lista_precio_id;
                $mostrarPrecios = $enlace->mostrar_precios;
                $mostrarStock = $enlace->mostrar_stock;
            }
        }

        // Agregar precios y stock
        if ($mostrarPrecios && $listaPrecioId) {
            $producto->precio = $producto->getPrecioPorLista($listaPrecioId);

            // Precios de variantes
            foreach ($producto->variantes as $variante) {
                $variante->precio_final = $variante->getPrecioFinal($listaPrecioId);
            }
        }

        // No enviar todas las listas de precios - solo el precio asignado al cliente
        $todasListasPrecios = [];

        if ($mostrarStock) {
            $producto->stock_info = $this->obtenerStockProducto($producto);

            // Stock de variantes
            foreach ($producto->variantes as $variante) {
                $variante->stock_info = $this->obtenerStockVariante($producto, $variante);
            }
        }

        // Asegurarse de que unidad_venta esté incluida
        $producto->unidad_venta = $producto->unidad_venta;

        return response()->json([
            'producto' => $producto,
            'mostrar_precios' => $mostrarPrecios,
            'mostrar_stock' => $mostrarStock,
            'todas_listas_precios' => $todasListasPrecios
        ]);
    }
    
    /**
     * Obtener información de stock de un producto
     */
    private function obtenerStockProducto($producto)
    {
        // Si no controla stock, siempre disponible
        if (!$producto->controlar_stock) {
            return [
                'tiene_stock' => true,
                'cantidad_disponible' => 999999,
                'estado' => 'disponible',
                'mensaje' => 'Disponible',
                'controla_stock' => false
            ];
        }

        if ($producto->tiene_variantes) {
            // Para productos con variantes, sumar el stock de todas las variantes
            $stockTotal = $producto->stock->sum(function($stock) {
                return $stock->cantidad_disponible - $stock->cantidad_reservada;
            });

            return [
                'tiene_stock' => $stockTotal > 0 || $producto->permitir_venta_sin_stock,
                'cantidad_disponible' => $stockTotal,
                'estado' => $this->getEstadoStock($stockTotal, false, $producto->permitir_venta_sin_stock),
                'mensaje' => $this->getMensajeStock($stockTotal, false, $producto->permitir_venta_sin_stock),
                'controla_stock' => true,
                'permite_sin_stock' => $producto->permitir_venta_sin_stock,
                'stock_maximo' => null
            ];
        } else {
            // Para productos sin variantes
            $stock = $producto->stockPrincipal;
            if (!$stock) {
                return [
                    'tiene_stock' => $producto->permitir_venta_sin_stock,
                    'cantidad_disponible' => 0,
                    'estado' => $producto->permitir_venta_sin_stock ? 'sin_stock_permitido' : 'sin_stock',
                    'mensaje' => $producto->permitir_venta_sin_stock ? 'Sin stock (se permite venta)' : 'Sin stock',
                    'controla_stock' => true,
                    'permite_sin_stock' => $producto->permitir_venta_sin_stock,
                    'stock_maximo' => null
                ];
            }

            $disponible = $stock->cantidad_disponible - $stock->cantidad_reservada;

            return [
                'tiene_stock' => $disponible > 0 || $producto->permitir_venta_sin_stock,
                'cantidad_disponible' => $disponible,
                'stock_bajo' => $stock->stock_bajo,
                'estado' => $this->getEstadoStock($disponible, $stock->stock_bajo, $producto->permitir_venta_sin_stock),
                'mensaje' => $this->getMensajeStock($disponible, $stock->stock_bajo, $producto->permitir_venta_sin_stock),
                'controla_stock' => true,
                'permite_sin_stock' => $producto->permitir_venta_sin_stock,
                'stock_maximo' => $stock->stock_maximo
            ];
        }
    }
    
    /**
     * Obtener información de stock de una variante
     */
    private function obtenerStockVariante($producto, $variante)
    {
        // Si no controla stock, siempre disponible
        if (!$producto->controlar_stock) {
            return [
                'tiene_stock' => true,
                'cantidad_disponible' => 999999,
                'estado' => 'disponible',
                'mensaje' => 'Disponible',
                'controla_stock' => false
            ];
        }

        $stock = $variante->stock;
        if (!$stock) {
            return [
                'tiene_stock' => $producto->permitir_venta_sin_stock,
                'cantidad_disponible' => 0,
                'estado' => $producto->permitir_venta_sin_stock ? 'sin_stock_permitido' : 'sin_stock',
                'mensaje' => $producto->permitir_venta_sin_stock ? 'Sin stock (se permite venta)' : 'Sin stock',
                'controla_stock' => true,
                'permite_sin_stock' => $producto->permitir_venta_sin_stock,
                'stock_maximo' => null
            ];
        }

        $disponible = $stock->cantidad_disponible - $stock->cantidad_reservada;

        return [
            'tiene_stock' => $disponible > 0 || $producto->permitir_venta_sin_stock,
            'cantidad_disponible' => $disponible,
            'stock_bajo' => $stock->stock_bajo,
            'estado' => $this->getEstadoStock($disponible, $stock->stock_bajo, $producto->permitir_venta_sin_stock),
            'mensaje' => $this->getMensajeStock($disponible, $stock->stock_bajo, $producto->permitir_venta_sin_stock),
            'controla_stock' => true,
            'permite_sin_stock' => $producto->permitir_venta_sin_stock,
            'stock_maximo' => $stock->stock_maximo
        ];
    }
    
    /**
     * Obtener estado de stock
     */
    private function getEstadoStock($cantidad, $stockBajo = false, $permiteSinStock = false)
    {
        if ($cantidad <= 0) {
            return $permiteSinStock ? 'sin_stock_permitido' : 'sin_stock';
        } elseif ($stockBajo) {
            return 'stock_bajo';
        } elseif ($cantidad <= 5) {
            return 'stock_limitado';
        } else {
            return 'disponible';
        }
    }
    
    /**
     * Obtener mensaje de stock
     */
    private function getMensajeStock($cantidad, $stockBajo = false, $permiteSinStock = false)
    {
        if ($cantidad <= 0) {
            return $permiteSinStock ? 'Sin stock (se permite venta)' : 'Sin stock';
        } elseif ($stockBajo) {
            return "Stock bajo ({$cantidad} disponibles)";
        } elseif ($cantidad <= 5) {
            return "Últimas {$cantidad} unidades";
        } else {
            return "{$cantidad} disponibles";
        }
    }
    
    /**
     * Verificar si se puede agregar al carrito
     */
    private function puedeAgregarAlCarrito($producto, $cantidad, $varianteId = null)
    {
        // Si no controla stock, siempre se puede agregar
        if (!$producto->controlar_stock) {
            return ['puede' => true, 'mensaje' => ''];
        }

        // Si permite venta sin stock, siempre se puede agregar
        if ($producto->permitir_venta_sin_stock) {
            return ['puede' => true, 'mensaje' => ''];
        }

        // Si controla stock y NO permite venta sin stock, verificar disponibilidad
        return [
            'puede' => $producto->hayStock($cantidad, $varianteId),
            'mensaje' => $producto->hayStock($cantidad, $varianteId) ? '' : 'Stock insuficiente'
        ];
    }
    
    /**
     * Guardar solicitud de cotización
     */
    public function guardarSolicitud(Request $request)
    {
        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.producto_id' => 'required|exists:productos,id',
            'items.*.cantidad' => 'required|integer|min:1',
            'items.*.variante_id' => 'nullable|exists:variantes_productos,id',
            'items.*.observacion' => 'nullable|string|max:500',
            'notas_cliente' => 'nullable|string|max:1000',
            'observaciones_vendedor' => 'nullable|string|max:1000',
            'sucursal_id' => 'nullable|exists:sucursales,id'
        ]);
        
        DB::beginTransaction();

        try {
            // Determinar cliente y enlace
            $cliente = null;
            $enlace = null;

            if ($request->filled('enlace_token')) {
                // Flujo A: Cliente con token (acceso público)
                $enlace = EnlaceAcceso::where('token', $request->enlace_token)->first();
                if (!$enlace || !$enlace->esValido()) {
                    throw new \Exception('El enlace de acceso no es válido.');
                }
                $cliente = $enlace->cliente;
            }
            elseif ($request->filled('cliente_id')) {
                // Flujo B: Vendedor/Admin (usuario autenticado)
                $cliente = Cliente::findOrFail($request->cliente_id);
            }
            else {
                throw new \Exception('No se pudo identificar el cliente.');
            }

            // Validar variantes y stock ANTES de crear la cotización
            foreach ($request->items as $item) {
                $producto = Producto::findOrFail($item['producto_id']);

                // Validar que productos con variantes tengan variante seleccionada
                if ($producto->tiene_variantes && empty($item['variante_id'])) {
                    throw new \Exception(
                        "El producto '{$producto->nombre}' tiene variantes. Debe seleccionar una variante específica."
                    );
                }

                // Bloqueo pesimista para evitar race conditions. Se descarta el stock de tienda
                // y se PRIORIZA la bodega principal: los registros sin ubicación (legacy/fantasma)
                // quedan de último, así nunca se elige uno con 0 si hay una bodega con stock.
                $stockQuery = StockProducto::where('producto_id', $item['producto_id'])
                    ->with('ubicacionRelacion')
                    ->where(function($q) {
                        $q->whereNull('ubicacion_id')
                          ->orWhereHas('ubicacionRelacion', fn($u) => $u->where('tipo', '!=', 'tienda'));
                    });

                if (!empty($item['variante_id'])) {
                    $stockQuery->where('variante_producto_id', $item['variante_id']);
                } else {
                    $stockQuery->whereNull('variante_producto_id');
                }

                $stock = $stockQuery->lockForUpdate()->get()
                    ->sortByDesc(function ($s) {
                        return [
                            $s->ubicacion_id ? 1 : 0,                                  // con ubicación antes que sin ubicación
                            optional($s->ubicacionRelacion)->es_principal ? 1 : 0,     // bodega principal primero
                            $s->cantidad_disponible - $s->cantidad_reservada,          // luego la de mayor disponible real
                        ];
                    })
                    ->first();

                // Validar con el stock BLOQUEADO
                if ($producto->controlar_stock && !$producto->permitir_venta_sin_stock) {
                    $disponibleReal = ($stock->cantidad_disponible ?? 0) - ($stock->cantidad_reservada ?? 0);

                    if ($disponibleReal < $item['cantidad']) {
                        // Con variantes, decir CUÁL (tono/color): el nombre del producto solo no alcanza.
                        $nombre = $producto->nombre;
                        if (!empty($item['variante_id'])) {
                            $variante = $producto->variantes()->find($item['variante_id']);
                            if ($variante && $variante->nombre_variante) {
                                $nombre .= " ({$variante->nombre_variante})";
                            }
                        }

                        // El inventario muestra las unidades físicas; aquí cuentan las de bodega
                        // menos las reservadas por otras cotizaciones. Se explica la diferencia.
                        if (!$stock) {
                            $detalle = 'No tiene stock registrado en bodega (el de tiendas no cuenta para cotizaciones).';
                        } else {
                            $detalle = 'En ' . (optional($stock->ubicacionRelacion)->nombre ?? 'bodega')
                                . ": {$stock->cantidad_disponible} en existencia";
                            if ($stock->cantidad_reservada > 0) {
                                $detalle .= ", {$stock->cantidad_reservada} reservadas por otras cotizaciones";
                            }
                            $detalle .= '.';
                        }

                        // Lo más común: en inventario se ve stock, pero está en una tienda.
                        $enTiendas = StockProducto::where('producto_id', $item['producto_id'])
                            ->where('variante_producto_id', $item['variante_id'] ?? null)
                            ->whereHas('ubicacionRelacion', fn($u) => $u->where('tipo', 'tienda'))
                            ->where('cantidad_disponible', '>', 0)
                            ->with('ubicacionRelacion')
                            ->get();
                        if ($enTiendas->isNotEmpty()) {
                            $detalle .= ' Hay ' . $enTiendas->map(fn($s) => "{$s->cantidad_disponible} en {$s->ubicacionRelacion->nombre}")->implode(', ')
                                . ', pero el stock de tiendas no cuenta para cotizaciones: hay que trasladarlo a bodega.';
                        }

                        throw new \Exception("Stock insuficiente para {$nombre}. Disponible: {$disponibleReal}, Solicitado: {$item['cantidad']}. {$detalle}");
                    }
                }

                // Validar stock máximo (aplica siempre que esté configurado, incluso si permite venta sin stock)
                if ($stock && $stock->stock_maximo && $item['cantidad'] > $stock->stock_maximo) {
                    throw new \Exception("La cantidad ({$item['cantidad']}) supera el máximo permitido ({$stock->stock_maximo}) para {$producto->nombre}");
                }
            }

            // Determinar flete del cliente (solo si el vendedor lo incluyó)
            $valorFlete = 0;
            if ($cliente->aplica_flete && $cliente->valor_flete > 0 && $request->input('incluir_flete', 1)) {
                $valorFlete = $cliente->valor_flete;
            }

            // Crear solicitud
            $solicitud = new SolicitudCotizacion([
                'cliente_id' => $cliente->id,
                'sucursal_id' => $request->sucursal_id,
                'enlace_acceso_id' => $enlace ? $enlace->id : null,
                'created_by' => Auth::check() ? Auth::id() : null,
                'estado' => 'aplicada',
                'aplicada_en' => now(),
                'aplicada_por' => Auth::check() ? Auth::id() : null,
                'valor_flete' => $valorFlete,
                'notas_cliente' => $request->notas_cliente,
                'observaciones_vendedor' => $request->observaciones_vendedor
            ]);
            $solicitud->save();

            // Obtener lista de precios
            $listaPrecioId = $cliente->lista_precio_id;
            $montoTotal = 0;

            // Agregar items (NO descontar stock, solo se RESERVA)
            foreach ($request->items as $item) {
                $producto = Producto::with(['stockPrincipal', 'variantes.stock'])->findOrFail($item['producto_id']);

                // Determinar precio
                $precioUnitario = 0;
                $precioOriginal = 0;
                $precioEditado = false;
                $infoVariante = null;

                // Si se proporciona precio manual, usarlo
                if (!empty($item['precio_manual'])) {
                    $precioUnitario = floatval($item['precio_manual']);
                    $precioEditado = true;

                    // Obtener precio original de la BD para auditoría
                    if (!empty($item['variante_id'])) {
                        $variante = $producto->variantes()->findOrFail($item['variante_id']);
                        $precioOriginal = $variante->getPrecioFinal($listaPrecioId) ?? 0;
                        $infoVariante = $variante->nombre_variante;
                    } else {
                        $precioOriginal = $producto->getPrecioPorLista($listaPrecioId) ?? 0;
                    }
                } else {
                    // Flujo normal: obtener precio de BD
                    if (!empty($item['variante_id'])) {
                        $variante = $producto->variantes()->findOrFail($item['variante_id']);
                        $precioUnitario = $variante->getPrecioFinal($listaPrecioId) ?? 0;
                        $infoVariante = $variante->nombre_variante;
                    } else {
                        $precioUnitario = $producto->getPrecioPorLista($listaPrecioId) ?? 0;
                    }
                    $precioOriginal = $precioUnitario;
                }

                $precioTotal = $precioUnitario * $item['cantidad'];
                $montoTotal += $precioTotal;

                // Crear item
                $itemCotizacion = ItemSolicitudCotizacion::create([
                    'solicitud_cotizacion_id' => $solicitud->id,
                    'producto_id' => $producto->id,
                    'variante_producto_id' => $item['variante_id'] ?? null,
                    'cantidad' => $item['cantidad'],
                    'precio_unitario' => $precioUnitario,
                    'precio_total' => $precioTotal,
                    'precio_editado_manualmente' => $precioEditado,
                    'precio_original' => $precioOriginal,
                    'referencia_producto' => $producto->referencia,
                    'nombre_producto' => $producto->nombre,
                    'marca_producto' => $producto->marca,
                    'info_variante' => $infoVariante,
                    'observacion' => $item['observacion'] ?? null
                ]);
            }

            // Actualizar monto total (incluye flete)
            $solicitud->update(['monto_total' => $montoTotal + $valorFlete]);

            // RESERVAR stock (incrementar cantidad_reservada) - NO descontar
            $reservaService = new ReservaStockService();
            $resultadoReserva = $reservaService->reservarParaCotizacionEnTransaccion($solicitud);

            if (!$resultadoReserva) {
                throw new \Exception("No se pudo reservar el stock completamente");
            }

            DB::commit();

            // Disparar evento para crear cuenta de cliente automáticamente
            // TEMPORALMENTE DESACTIVADO - Descomentar para reactivar creación automática de cuenta
            // event(new CotizacionCreada($solicitud));

            return response()->json([
                'success' => true,
                'mensaje' => 'Solicitud de cotización creada y aplicada exitosamente. Stock descontado.',
                'numero_solicitud' => $solicitud->numero_solicitud
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'mensaje' => 'Error al crear la solicitud: ' . $e->getMessage()
            ], 400);
        }
    }
}