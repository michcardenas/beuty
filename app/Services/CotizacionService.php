<?php

namespace App\Services;

use App\Events\CotizacionCreada;
use App\Models\Cliente;
use App\Models\EnlaceAcceso;
use App\Models\ItemSolicitudCotizacion;
use App\Models\Producto;
use App\Models\SolicitudCotizacion;
use App\Models\HistorialEstadoSolicitud;
use App\Models\LogSolicitudCotizacion;
use App\Models\VarianteProducto;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Exception;

/**
 * Servicio para gestionar cotizaciones.
 *
 * Maneja la creación, actualización, cambio de estado y eliminación
 * de cotizaciones, integrando el sistema de reservas de stock.
 */
class CotizacionService
{
    protected ReservaStockService $reservaService;

    public function __construct(ReservaStockService $reservaService)
    {
        $this->reservaService = $reservaService;
    }

    /**
     * Crear una nueva cotización con reserva de stock
     *
     * @param array $datos Datos de la cotización
     * @param Cliente $cliente
     * @param EnlaceAcceso|null $enlace
     * @param int|null $usuarioId Usuario que crea la cotización
     * @return array ['exito' => bool, 'solicitud' => SolicitudCotizacion|null, 'errores' => array]
     */
    public function crear(
        array $datos,
        Cliente $cliente,
        ?EnlaceAcceso $enlace = null,
        ?int $usuarioId = null
    ): array {
        $resultado = [
            'exito' => true,
            'solicitud' => null,
            'errores' => [],
            'advertencias' => [],
        ];

        // Verificar disponibilidad de stock primero
        $verificacion = $this->reservaService->verificarDisponibilidad($datos['items']);

        if (!$verificacion['disponible']) {
            // Hay items sin stock suficiente
            foreach ($verificacion['errores'] as $error) {
                $resultado['advertencias'][] = $error['mensaje'];
            }
        }

        DB::beginTransaction();

        try {
            // Crear la solicitud de cotización
            $solicitud = SolicitudCotizacion::create([
                'numero_solicitud' => $this->generarNumeroSolicitud(),
                'cliente_id' => $cliente->id,
                'enlace_acceso_id' => $enlace?->id,
                'created_by' => $usuarioId,
                'estado' => SolicitudCotizacion::ESTADO_PENDIENTE,
                'monto_total' => 0,
                'valor_flete' => $datos['valor_flete'] ?? 0,
                'descuento_total' => $datos['descuento_total'] ?? 0,
                'notas_cliente' => $datos['notas_cliente'] ?? null,
                'observaciones_vendedor' => $datos['observaciones_vendedor'] ?? null,
            ]);

            // Crear los items de la cotización
            $montoTotal = 0;
            foreach ($datos['items'] as $itemData) {
                $item = $this->crearItem($solicitud, $itemData, $cliente);
                $montoTotal += $item->precio_total;
            }

            // Agregar flete y restar descuento
            $montoTotal += ($datos['valor_flete'] ?? 0);
            $montoTotal -= ($datos['descuento_total'] ?? 0);

            $solicitud->update(['monto_total' => max(0, $montoTotal)]);

            // Intentar reservar stock
            $reservaResultado = $this->reservaService->reservarParaCotizacion($solicitud);

            if (!$reservaResultado['exito']) {
                // BLOQUEO: no creamos la cotización si algún producto no se puede reservar completo
                // desde bodega. Así se evita que quede sin apartar y otras cotizaciones tomen esas
                // unidades (sobreventa). Revertimos y avisamos claro qué falta.
                DB::rollBack();
                $resultado['exito'] = false;
                foreach ($reservaResultado['errores'] as $error) {
                    $resultado['errores'][] = $error['mensaje'];
                }
                if (empty($resultado['errores'])) {
                    $resultado['errores'][] = 'No se pudo reservar el stock de uno o más productos desde la bodega.';
                }
                return $resultado;
            }

            $resultado['solicitud'] = $solicitud->fresh(['items', 'cliente', 'reservas']);

            DB::commit();

            // Disparar evento para crear cuenta de cliente automáticamente
            event(new CotizacionCreada($solicitud));

        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Error al crear cotización: " . $e->getMessage());
            $resultado['exito'] = false;
            $resultado['errores'][] = 'Error al crear la cotización: ' . $e->getMessage();
        }

        return $resultado;
    }

    /**
     * Actualizar una cotización existente
     *
     * @param SolicitudCotizacion $solicitud
     * @param array $datos
     * @param int $usuarioId
     * @return array
     */
    public function actualizar(SolicitudCotizacion $solicitud, array $datos, int $usuarioId): array
    {
        $resultado = [
            'exito' => true,
            'solicitud' => null,
            'errores' => [],
            'advertencias' => [],
        ];

        // Ni facturación la edita: lo cotizado ya quedó ante la DIAN.
        if ($solicitud->tieneFacturaElectronica()) {
            $resultado['exito'] = false;
            $resultado['errores'][] = 'La cotización ya tiene factura electrónica y no se puede editar';
            return $resultado;
        }

        $user = auth()->user();
        $esVendedorPuro = $user
            && $user->hasRole('vendedor')
            && !$user->hasAnyRole(['admin', 'auxiliar_administrativo', 'facturacion', 'inventarios']);

        if ($esVendedorPuro) {
            if (!$solicitud->puedeEditarVendedor()) {
                $resultado['exito'] = false;
                $resultado['errores'][] = 'La cotización no puede ser editada en su estado actual';
                return $resultado;
            }

            // Defensa en profundidad: ignorar cualquier precio_manual o cambio en descuento/flete
            if (!empty($datos['items']) && is_array($datos['items'])) {
                foreach ($datos['items'] as $i => $item) {
                    unset($datos['items'][$i]['precio_manual']);
                }
            }
            $datos['descuento_total'] = $solicitud->descuento_total;
            $datos['valor_flete'] = $solicitud->valor_flete;
        } elseif (!$user->hasAnyRole(['facturacion', 'auxiliar_administrativo']) && !$solicitud->esEditable()) {
            $resultado['exito'] = false;
            $resultado['errores'][] = 'La cotización no puede ser editada en su estado actual';
            return $resultado;
        }

        // Snapshot del estado anterior para el log
        $estadoAnterior = [
            'cliente_id' => $solicitud->cliente_id,
            'cliente_nombre' => $solicitud->cliente->nombre_contacto ?? '',
            'valor_flete' => $solicitud->valor_flete,
            'descuento_total' => $solicitud->descuento_total,
            'porcentaje_iva' => $solicitud->porcentaje_iva,
            'notas_cliente' => $solicitud->notas_cliente,
            'observaciones_vendedor' => $solicitud->observaciones_vendedor,
            'forma_pago_factura' => $solicitud->forma_pago_factura,
            'fecha_vencimiento' => $solicitud->fecha_vencimiento ? (string) $solicitud->fecha_vencimiento : null,
            'monto_total' => $solicitud->monto_total,
        ];

        $itemsAnteriores = $solicitud->items->map(function ($item) {
            return [
                'producto_id' => $item->producto_id,
                'variante_producto_id' => $item->variante_producto_id,
                'cantidad' => $item->cantidad,
                'precio_unitario' => (float) $item->precio_unitario,
                'referencia_producto' => $item->referencia_producto,
                'nombre_producto' => $item->nombre_producto,
                'info_variante' => $item->info_variante,
                'observacion' => $item->observacion,
            ];
        })->toArray();

        DB::beginTransaction();

        try {
            // Liberar reservas actuales
            if ($solicitud->tiene_reserva_stock) {
                $this->reservaService->liberarReservasCotizacion(
                    $solicitud,
                    'Edición de cotización',
                    $usuarioId
                );
            }

            // Manejar cambio de cliente
            if (isset($datos['cliente_id']) && $datos['cliente_id'] != $solicitud->cliente_id) {
                $cliente = Cliente::with('listaPrecio')->findOrFail($datos['cliente_id']);
                $solicitud->cliente_id = $datos['cliente_id'];
            } else {
                $cliente = $solicitud->cliente;
            }

            // Eliminar items actuales
            $solicitud->items()->delete();

            // Crear nuevos items
            $montoTotal = 0;

            foreach ($datos['items'] as $itemData) {
                $item = $this->crearItem($solicitud, $itemData, $cliente);
                $montoTotal += $item->precio_total;
            }

            // Agregar flete y restar descuento
            $subtotalProductos = $montoTotal; // Guardamos el subtotal de productos para calcular IVA
            $montoTotal += ($datos['valor_flete'] ?? $solicitud->valor_flete ?? 0);
            $montoTotal -= ($datos['descuento_total'] ?? $solicitud->descuento_total ?? 0);

            // Calcular IVA si aplica
            $porcentajeIva = isset($datos['porcentaje_iva']) && $datos['porcentaje_iva'] !== '' && $datos['porcentaje_iva'] !== null
                ? (float) $datos['porcentaje_iva']
                : null;
            $valorIva = null;

            if ($porcentajeIva && $porcentajeIva > 0) {
                // El IVA se calcula sobre el subtotal de productos (sin flete ni descuento)
                $valorIva = $subtotalProductos * ($porcentajeIva / 100);
            }

            // Calcular fecha de vencimiento a partir de días
            $fechaVencimiento = $solicitud->fecha_vencimiento;
            if (isset($datos['dias_vencimiento']) && $datos['dias_vencimiento'] !== null) {
                $fechaVencimiento = $datos['dias_vencimiento'] > 0
                    ? now()->addDays((int) $datos['dias_vencimiento'])->toDateString()
                    : null;
            }

            // Actualizar solicitud
            $solicitud->update([
                'cliente_id' => $solicitud->cliente_id,
                'monto_total' => max(0, $montoTotal),
                'valor_flete' => $datos['valor_flete'] ?? $solicitud->valor_flete,
                'descuento_total' => $datos['descuento_total'] ?? $solicitud->descuento_total,
                'porcentaje_iva' => $porcentajeIva,
                'valor_iva' => $valorIva,
                'notas_cliente' => $datos['notas_cliente'] ?? $solicitud->notas_cliente,
                'observaciones_vendedor' => $datos['observaciones_vendedor'] ?? $solicitud->observaciones_vendedor,
                'forma_pago_factura' => $datos['forma_pago_factura'] ?? $solicitud->forma_pago_factura,
                'fecha_vencimiento' => $fechaVencimiento,
                'editada_en' => now(),
                'editada_por' => $usuarioId,
            ]);

            // Recargar la relación de ítems con los recién creados. Sin esto, $solicitud->items
            // conserva en caché los ítems VIEJOS (ya borrados arriba) y reservarParaCotizacion
            // intentaría insertar reservas con un item_solicitud_id inexistente -> falla el FK y
            // la cotización quedaba sin reservas en cada edición.
            $solicitud->load('items');

            // Crear nuevas reservas
            $reservaResultado = $this->reservaService->reservarParaCotizacion($solicitud);

            if (!$reservaResultado['exito']) {
                // BLOQUEO: si algún producto no se puede reservar completo desde bodega, no
                // guardamos la edición (dejaría líneas sin apartar y otras cotizaciones tomarían
                // esas unidades -> sobreventa). Revertimos y avisamos claro qué falta.
                DB::rollBack();
                $resultado['exito'] = false;
                foreach ($reservaResultado['errores'] as $error) {
                    $resultado['errores'][] = $error['mensaje'];
                }
                if (empty($resultado['errores'])) {
                    $resultado['errores'][] = 'No se pudo reservar el stock de uno o más productos desde la bodega.';
                }
                return $resultado;
            }

            // Registrar log de cambios
            $this->registrarLogEdicion($solicitud, $estadoAnterior, $itemsAnteriores, $cliente);

            $resultado['solicitud'] = $solicitud->fresh(['items', 'cliente', 'reservas']);

            DB::commit();

        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Error al actualizar cotización {$solicitud->numero_solicitud}: " . $e->getMessage());
            $resultado['exito'] = false;
            $resultado['errores'][] = 'Error al actualizar la cotización: ' . $e->getMessage();
        }

        return $resultado;
    }

    /**
     * Registrar log detallado de los cambios realizados en una edición
     */
    private function registrarLogEdicion(
        SolicitudCotizacion $solicitud,
        array $estadoAnterior,
        array $itemsAnteriores,
        Cliente $clienteActual
    ): void {
        $cambios = [];

        // Comparar cambio de cliente
        if ($estadoAnterior['cliente_id'] != $solicitud->cliente_id) {
            $cambios[] = [
                'campo' => 'Cliente',
                'anterior' => $estadoAnterior['cliente_nombre'],
                'nuevo' => $clienteActual->nombre_contacto,
            ];
        }

        // Comparar campos escalares
        $camposComparar = [
            'valor_flete' => 'Flete',
            'descuento_total' => 'Descuento',
            'porcentaje_iva' => 'IVA %',
            'notas_cliente' => 'Notas del cliente',
            'observaciones_vendedor' => 'Observaciones vendedor',
            'forma_pago_factura' => 'Forma de pago',
        ];

        foreach ($camposComparar as $campo => $label) {
            $valorAnterior = $estadoAnterior[$campo];
            $valorNuevo = $solicitud->{$campo};

            // Normalizar para comparación
            if (is_numeric($valorAnterior) || is_numeric($valorNuevo)) {
                $valorAnterior = (float) ($valorAnterior ?? 0);
                $valorNuevo = (float) ($valorNuevo ?? 0);
            }

            if ($valorAnterior != $valorNuevo) {
                $cambios[] = [
                    'campo' => $label,
                    'anterior' => $estadoAnterior[$campo] ?? '-',
                    'nuevo' => $solicitud->{$campo} ?? '-',
                ];
            }
        }

        // Comparar monto total
        if ((float) $estadoAnterior['monto_total'] != (float) $solicitud->monto_total) {
            $cambios[] = [
                'campo' => 'Monto total',
                'anterior' => number_format((float) $estadoAnterior['monto_total'], 2),
                'nuevo' => number_format((float) $solicitud->monto_total, 2),
            ];
        }

        // Comparar items
        $itemsNuevos = $solicitud->items()->get()->map(function ($item) {
            return [
                'producto_id' => $item->producto_id,
                'variante_producto_id' => $item->variante_producto_id,
                'cantidad' => $item->cantidad,
                'precio_unitario' => (float) $item->precio_unitario,
                'referencia_producto' => $item->referencia_producto,
                'nombre_producto' => $item->nombre_producto,
                'info_variante' => $item->info_variante,
                'observacion' => $item->observacion,
            ];
        })->toArray();

        // Indexar por clave compuesta producto_id-variante_id
        $indexAnterior = [];
        foreach ($itemsAnteriores as $item) {
            $key = $item['producto_id'] . '-' . ($item['variante_producto_id'] ?? 'null');
            $indexAnterior[$key] = $item;
        }

        $indexNuevo = [];
        foreach ($itemsNuevos as $item) {
            $key = $item['producto_id'] . '-' . ($item['variante_producto_id'] ?? 'null');
            $indexNuevo[$key] = $item;
        }

        $itemsAgregados = [];
        $itemsEliminados = [];
        $itemsModificados = [];

        // Items eliminados
        foreach ($indexAnterior as $key => $item) {
            if (!isset($indexNuevo[$key])) {
                $itemsEliminados[] = [
                    'referencia' => $item['referencia_producto'],
                    'producto' => $item['nombre_producto'],
                    'variante' => $item['info_variante'] ?: '-',
                    'cantidad' => $item['cantidad'],
                    'precio' => $item['precio_unitario'],
                ];
            }
        }

        // Items agregados y modificados
        foreach ($indexNuevo as $key => $item) {
            if (!isset($indexAnterior[$key])) {
                $itemsAgregados[] = [
                    'referencia' => $item['referencia_producto'],
                    'producto' => $item['nombre_producto'],
                    'variante' => $item['info_variante'] ?: '-',
                    'cantidad' => $item['cantidad'],
                    'precio' => $item['precio_unitario'],
                ];
            } else {
                // Comparar cambios en el item
                $anterior = $indexAnterior[$key];
                $cambiosItem = [];

                if ($anterior['cantidad'] != $item['cantidad']) {
                    $cambiosItem[] = [
                        'campo' => 'Cantidad',
                        'anterior' => $anterior['cantidad'],
                        'nuevo' => $item['cantidad'],
                    ];
                }

                if ($anterior['precio_unitario'] != $item['precio_unitario']) {
                    $cambiosItem[] = [
                        'campo' => 'Precio unitario',
                        'anterior' => number_format($anterior['precio_unitario'], 2),
                        'nuevo' => number_format($item['precio_unitario'], 2),
                    ];
                }

                if (($anterior['observacion'] ?? '') != ($item['observacion'] ?? '')) {
                    $cambiosItem[] = [
                        'campo' => 'Observación',
                        'anterior' => $anterior['observacion'] ?? '-',
                        'nuevo' => $item['observacion'] ?? '-',
                    ];
                }

                if (!empty($cambiosItem)) {
                    $itemsModificados[] = [
                        'referencia' => $item['referencia_producto'],
                        'producto' => $item['nombre_producto'],
                        'variante' => $item['info_variante'] ?: '-',
                        'cambios' => $cambiosItem,
                    ];
                }
            }
        }

        // Solo registrar si hubo cambios
        $hayCambios = !empty($cambios) || !empty($itemsAgregados) || !empty($itemsEliminados) || !empty($itemsModificados);

        if ($hayCambios) {
            $detalle = [
                'cambios' => $cambios,
                'items_agregados' => $itemsAgregados,
                'items_eliminados' => $itemsEliminados,
                'items_modificados' => $itemsModificados,
            ];

            // Determinar acción principal
            $accion = LogSolicitudCotizacion::ACCION_EDICION;
            if ($estadoAnterior['cliente_id'] != $solicitud->cliente_id) {
                $accion = LogSolicitudCotizacion::ACCION_CAMBIO_CLIENTE;
            }

            LogSolicitudCotizacion::registrar($solicitud->id, $accion, $detalle);
        }
    }

    /**
     * Cambiar el estado de una cotización
     *
     * @param SolicitudCotizacion $solicitud
     * @param string $nuevoEstado
     * @param array $datos Datos adicionales (observaciones, motivo, procesar_stock)
     * @param int $usuarioId
     * @return array
     */
    public function cambiarEstado(
        SolicitudCotizacion $solicitud,
        string $nuevoEstado,
        array $datos,
        int $usuarioId
    ): array {
        $resultado = [
            'exito' => true,
            'solicitud' => null,
            'errores' => [],
        ];

        if ($solicitud->estado !== SolicitudCotizacion::ESTADO_PENDIENTE) {
            $resultado['exito'] = false;
            $resultado['errores'][] = 'Solo se pueden cambiar cotizaciones pendientes';
            return $resultado;
        }

        DB::beginTransaction();

        try {
            switch ($nuevoEstado) {
                case SolicitudCotizacion::ESTADO_APLICADA:
                    $resultado = $this->aplicarCotizacion($solicitud, $datos, $usuarioId);
                    break;

                case SolicitudCotizacion::ESTADO_RECHAZADA:
                    $resultado = $this->rechazarCotizacion($solicitud, $datos, $usuarioId);
                    break;

                default:
                    $resultado['exito'] = false;
                    $resultado['errores'][] = 'Estado no válido: ' . $nuevoEstado;
            }

            if ($resultado['exito']) {
                // Registrar en historial de estados
                HistorialEstadoSolicitud::create([
                    'solicitud_cotizacion_id' => $solicitud->id,
                    'tipo_cambio' => HistorialEstadoSolicitud::TIPO_ESTADO,
                    'estado_anterior' => SolicitudCotizacion::ESTADO_PENDIENTE,
                    'estado_nuevo' => $nuevoEstado,
                    'observaciones' => $datos['observaciones_admin'] ?? $datos['motivo_rechazo'] ?? null,
                    'user_id' => $usuarioId,
                ]);

                DB::commit();
            } else {
                DB::rollBack();
            }

        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Error al cambiar estado de cotización {$solicitud->numero_solicitud}: " . $e->getMessage());
            $resultado['exito'] = false;
            $resultado['errores'][] = 'Error al cambiar el estado: ' . $e->getMessage();
        }

        return $resultado;
    }

    /**
     * Aplicar (aprobar) una cotización
     */
    private function aplicarCotizacion(SolicitudCotizacion $solicitud, array $datos, int $usuarioId): array
    {
        $resultado = [
            'exito' => true,
            'solicitud' => null,
            'errores' => [],
        ];

        // Verificar observaciones obligatorias del vendedor
        if (empty($datos['observaciones_vendedor'])) {
            $resultado['exito'] = false;
            $resultado['errores'][] = 'Las observaciones del vendedor son obligatorias al aprobar';
            return $resultado;
        }

        // Procesar stock si está indicado
        $procesarStock = $datos['procesar_stock'] ?? true;

        if ($procesarStock) {
            $reservaResultado = $this->reservaService->aplicarReservas($solicitud);

            if (!$reservaResultado['exito']) {
                $resultado['errores'] = array_merge($resultado['errores'], $reservaResultado['errores']);
                // No detenemos, solo advertimos
            }
        } else {
            // Si no se procesa stock, liberar las reservas
            $this->reservaService->liberarReservasCotizacion(
                $solicitud,
                'Aprobación sin procesamiento de stock',
                $usuarioId
            );
        }

        // Marcar como aplicada
        $solicitud->update([
            'estado' => SolicitudCotizacion::ESTADO_APLICADA,
            'aplicada_en' => now(),
            'aplicada_por' => $usuarioId,
            'observaciones_admin' => $datos['observaciones_admin'] ?? null,
            'observaciones_vendedor' => $datos['observaciones_vendedor'],
        ]);

        $resultado['solicitud'] = $solicitud->fresh();

        return $resultado;
    }

    /**
     * Rechazar una cotización
     */
    private function rechazarCotizacion(SolicitudCotizacion $solicitud, array $datos, int $usuarioId): array
    {
        $resultado = [
            'exito' => true,
            'solicitud' => null,
            'errores' => [],
        ];

        if ($solicitud->tieneFacturaElectronica()) {
            $resultado['exito'] = false;
            $resultado['errores'][] = 'La cotización ya tiene factura electrónica: para anularla se hace una nota crédito en SIIGO';
            return $resultado;
        }

        if (empty($datos['motivo_rechazo'])) {
            $resultado['exito'] = false;
            $resultado['errores'][] = 'El motivo de rechazo es obligatorio';
            return $resultado;
        }

        // Liberar reservas
        if ($solicitud->tiene_reserva_stock) {
            $this->reservaService->liberarReservasCotizacion(
                $solicitud,
                'Cotización rechazada: ' . $datos['motivo_rechazo'],
                $usuarioId
            );
        }

        // Marcar como rechazada
        $solicitud->update([
            'estado' => SolicitudCotizacion::ESTADO_RECHAZADA,
            'rechazada_en' => now(),
            'rechazada_por' => $usuarioId,
            'motivo_rechazo' => $datos['motivo_rechazo'],
        ]);

        $resultado['solicitud'] = $solicitud->fresh();

        return $resultado;
    }

    /**
     * Clonar una cotización existente
     *
     * @param SolicitudCotizacion $solicitudOriginal
     * @param int $usuarioId
     * @return array
     */
    public function clonar(SolicitudCotizacion $solicitudOriginal, int $usuarioId): array
    {
        $resultado = [
            'exito' => true,
            'solicitud' => null,
            'errores' => [],
        ];

        DB::beginTransaction();

        try {
            // Crear nueva solicitud directamente en estado APLICADA
            $nuevaSolicitud = SolicitudCotizacion::create([
                'numero_solicitud' => $this->generarNumeroSolicitud(),
                'cliente_id' => $solicitudOriginal->cliente_id,
                'enlace_acceso_id' => null, // Nueva cotización, sin enlace
                'created_by' => $usuarioId,
                'estado' => SolicitudCotizacion::ESTADO_APLICADA,
                'aplicada_en' => now(),
                'aplicada_por' => $usuarioId,
                'monto_total' => 0,
                'valor_flete' => $solicitudOriginal->valor_flete,
                'descuento_total' => $solicitudOriginal->descuento_total,
                'notas_cliente' => "Clonada de {$solicitudOriginal->numero_solicitud}",
            ]);

            // Clonar items (con precios actualizados si es posible)
            $montoTotal = 0;
            $cliente = $solicitudOriginal->cliente;

            foreach ($solicitudOriginal->items as $itemOriginal) {
                $producto = Producto::find($itemOriginal->producto_id);

                if (!$producto || !$producto->activo) {
                    // Producto ya no existe o está inactivo, omitir
                    $resultado['advertencias'][] = "Producto {$itemOriginal->nombre_producto} ya no está disponible";
                    continue;
                }

                // Obtener precio actual
                $precioActual = $this->obtenerPrecioProducto($producto, $itemOriginal->variante_producto_id, $cliente);

                $item = ItemSolicitudCotizacion::create([
                    'solicitud_cotizacion_id' => $nuevaSolicitud->id,
                    'producto_id' => $producto->id,
                    'variante_producto_id' => $itemOriginal->variante_producto_id,
                    'cantidad' => $itemOriginal->cantidad,
                    'precio_unitario' => $precioActual,
                    'precio_total' => $precioActual * $itemOriginal->cantidad,
                    'precio_original' => $precioActual,
                    'precio_editado_manualmente' => false,
                    'referencia_producto' => $producto->referencia,
                    'nombre_producto' => $producto->nombre,
                    'marca_producto' => $producto->marca,
                    'info_variante' => $itemOriginal->info_variante,
                ]);

                $montoTotal += $item->precio_total;
            }

            // Agregar flete y restar descuento
            $montoTotal += ($nuevaSolicitud->valor_flete ?? 0);
            $montoTotal -= ($nuevaSolicitud->descuento_total ?? 0);

            $nuevaSolicitud->update(['monto_total' => max(0, $montoTotal)]);

            // Registrar en historial el estado inicial APLICADA
            HistorialEstadoSolicitud::create([
                'solicitud_cotizacion_id' => $nuevaSolicitud->id,
                'tipo_cambio' => HistorialEstadoSolicitud::TIPO_ESTADO,
                'estado_anterior' => SolicitudCotizacion::ESTADO_PENDIENTE,
                'estado_nuevo' => SolicitudCotizacion::ESTADO_APLICADA,
                'observaciones' => "Clonada de {$solicitudOriginal->numero_solicitud} (aplicada automáticamente)",
                'user_id' => $usuarioId,
            ]);

            // No se crean reservas: la cotización clonada nace aplicada
            $resultado['solicitud'] = $nuevaSolicitud->fresh(['items', 'cliente']);

            DB::commit();

        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Error al clonar cotización {$solicitudOriginal->numero_solicitud}: " . $e->getMessage());
            $resultado['exito'] = false;
            $resultado['errores'][] = 'Error al clonar la cotización: ' . $e->getMessage();
        }

        return $resultado;
    }

    /**
     * Eliminar (soft delete) una cotización
     *
     * @param SolicitudCotizacion $solicitud
     * @param int $usuarioId
     * @return array
     */
    public function eliminar(SolicitudCotizacion $solicitud, int $usuarioId): array
    {
        $resultado = [
            'exito' => true,
            'errores' => [],
        ];

        if (!$solicitud->esEliminable()) {
            $resultado['exito'] = false;
            $resultado['errores'][] = 'La cotización no puede ser eliminada en su estado actual';
            return $resultado;
        }

        DB::beginTransaction();

        try {
            // Liberar reservas si las tiene
            if ($solicitud->tiene_reserva_stock) {
                $this->reservaService->liberarReservasCotizacion(
                    $solicitud,
                    'Cotización eliminada',
                    $usuarioId
                );
            }

            // Soft delete
            $solicitud->delete();

            DB::commit();

        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Error al eliminar cotización {$solicitud->numero_solicitud}: " . $e->getMessage());
            $resultado['exito'] = false;
            $resultado['errores'][] = 'Error al eliminar la cotización: ' . $e->getMessage();
        }

        return $resultado;
    }

    /**
     * Crear un item de cotización
     */
    private function crearItem(SolicitudCotizacion $solicitud, array $itemData, Cliente $cliente): ItemSolicitudCotizacion
    {
        $producto = Producto::with(['variantes'])->findOrFail($itemData['producto_id']);
        $variante = null;
        $infoVariante = null;

        if (!empty($itemData['variante_id'])) {
            $variante = VarianteProducto::find($itemData['variante_id']);
            if ($variante) {
                $infoVariante = trim(($variante->referencia_variante ?? '') . ' ' . ($variante->color ?? ''));
            }
        }

        // Determinar precio
        $precioLista = $this->obtenerPrecioProducto($producto, $variante?->id, $cliente);

        $precioUnitario = $itemData['precio_manual'] ?? $precioLista;
        $precioEditado = isset($itemData['precio_manual']) && $itemData['precio_manual'] != $precioLista;

        $cantidad = $itemData['cantidad'];

        return ItemSolicitudCotizacion::create([
            'solicitud_cotizacion_id' => $solicitud->id,
            'producto_id' => $producto->id,
            'variante_producto_id' => $variante?->id,
            'cantidad' => $cantidad,
            'precio_unitario' => $precioUnitario,
            'precio_total' => $precioUnitario * $cantidad,
            'precio_original' => $precioLista,
            'precio_editado_manualmente' => $precioEditado,
            'referencia_producto' => $producto->referencia,
            'nombre_producto' => $producto->nombre,
            'marca_producto' => $producto->marca,
            'info_variante' => $infoVariante,
            'observacion' => $itemData['observacion'] ?? null,
        ]);
    }

    /**
     * Obtener precio de un producto según la lista del cliente
     */
    private function obtenerPrecioProducto(Producto $producto, ?int $varianteId, Cliente $cliente): float
    {
        $listaPrecioId = $cliente->lista_precio_id;

        if ($varianteId) {
            // Buscar precio de variante
            $precioVariante = $producto->variantes()
                ->where('id', $varianteId)
                ->first()
                ?->precios()
                ->where('lista_precio_id', $listaPrecioId)
                ->first();

            if ($precioVariante && $precioVariante->precio > 0) {
                return $precioVariante->precio;
            }
        }

        // Buscar precio del producto
        $precioProducto = $producto->precios()
            ->where('lista_precio_id', $listaPrecioId)
            ->first();

        return $precioProducto?->precio ?? 0;
    }

    /**
     * Generar número único de solicitud
     */
    private function generarNumeroSolicitud(): string
    {
        return 'SC-' . now()->format('YmdHis') . '-' . strtoupper(Str::random(4));
    }
}
