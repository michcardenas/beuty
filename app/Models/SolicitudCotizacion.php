<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class SolicitudCotizacion extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'solicitudes_cotizacion';

    protected $fillable = [
        'numero_solicitud',
        'cliente_id',
        'sucursal_id',
        'enlace_acceso_id',
        'created_by',
        'estado',
        'monto_total',
        'valor_flete',
        'descuento_total',
        'notas_cliente',
        'observaciones_admin',
        'observaciones_vendedor',
        'motivo_rechazo',
        'aplicada_en',
        'aplicada_por',
        'rechazada_en',
        'rechazada_por',
        'tiene_reserva_stock',
        'reserva_expira_en',
        'reserva_liberada_en',
        'editada_en',
        'editada_por',
        // Campos de pago
        'estado_pago',
        'metodo_pago',
        'comprobante_pago',
        'monto_pagado',
        'monto_credito',
        'pagado_en',
        'verificado_por',
        'verificado_en',
        'notas_pago',
        // Campos de facturación
        'numero_factura',
        'facturada_en',
        'facturada_por',
        'archivo_factura',
        'porcentaje_iva',
        'valor_iva',
        'forma_pago_factura',
        'fecha_vencimiento',
        // Campos de envío
        'estado_envio',
        'numero_guia',
        'transportadora',
        'archivo_guia',
        'despachado_en',
        'despachado_por',
        'entregado_en',
        // Campos de descuento de stock
        'stock_descontado',
        'stock_descontado_en',
        'stock_descontado_por',
        // Marca de inventarios
        'marcada_inventario',
    ];

    protected $casts = [
        'monto_total' => 'decimal:2',
        'valor_flete' => 'decimal:2',
        'descuento_total' => 'decimal:2',
        'aplicada_en' => 'datetime',
        'rechazada_en' => 'datetime',
        'reserva_expira_en' => 'datetime',
        'reserva_liberada_en' => 'datetime',
        'editada_en' => 'datetime',
        'tiene_reserva_stock' => 'boolean',
        // Pago
        'monto_pagado' => 'decimal:2',
        'pagado_en' => 'datetime',
        'verificado_en' => 'datetime',
        // Factura
        'facturada_en' => 'datetime',
        'porcentaje_iva' => 'decimal:2',
        'valor_iva' => 'decimal:2',
        'fecha_vencimiento' => 'date',
        // Envío
        'despachado_en' => 'datetime',
        'entregado_en' => 'datetime',
        // Stock
        'stock_descontado' => 'boolean',
        'stock_descontado_en' => 'datetime',
        'marcada_inventario' => 'boolean',
    ];

    /**
     * Estados de la cotización
     */
    const ESTADO_PENDIENTE = 'pendiente';
    const ESTADO_APLICADA = 'aplicada';
    const ESTADO_RECHAZADA = 'rechazada';

    /**
     * Estados de pago
     */
    const PAGO_PENDIENTE = 'pendiente';
    const PAGO_PARCIAL = 'parcial';
    const PAGO_PAGADO = 'pagado';

    /**
     * Métodos de pago disponibles
     */
    const METODOS_PAGO = [
        'transferencia' => 'Transferencia bancaria',
        'efectivo' => 'Efectivo',
        'credito' => 'Crédito',
        'tarjeta' => 'Tarjeta',
        'cheque' => 'Cheque',
        'otro' => 'Otro',
    ];

    /**
     * Estados de envío
     */
    const ENVIO_PENDIENTE = 'pendiente';
    const ENVIO_PREPARANDO = 'preparando';
    const ENVIO_DESPACHADO = 'despachado';
    const ENVIO_EN_TRANSITO = 'en_transito';
    const ENVIO_ENTREGADO = 'entregado';

    /**
     * Obtener los estados de envío con sus etiquetas
     */
    public static function estadosEnvio(): array
    {
        return [
            self::ENVIO_PENDIENTE => 'Pendiente',
            self::ENVIO_PREPARANDO => 'Preparando',
            self::ENVIO_DESPACHADO => 'Despachado',
            self::ENVIO_EN_TRANSITO => 'En Tránsito',
            self::ENVIO_ENTREGADO => 'Entregado',
        ];
    }

    public function cliente()
    {
        return $this->belongsTo(Cliente::class, 'cliente_id');
    }

    public function enlaceAcceso()
    {
        return $this->belongsTo(EnlaceAcceso::class, 'enlace_acceso_id');
    }

    public function sucursal()
    {
        return $this->belongsTo(Sucursal::class, 'sucursal_id');
    }

    public function items()
    {
        return $this->hasMany(ItemSolicitudCotizacion::class, 'solicitud_cotizacion_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function aplicadaPor()
    {
        return $this->belongsTo(User::class, 'aplicada_por');
    }

    public function rechazadaPor()
    {
        return $this->belongsTo(User::class, 'rechazada_por');
    }

    /**
     * Usuario que editó la cotización
     */
    public function editadaPor()
    {
        return $this->belongsTo(User::class, 'editada_por');
    }

    /**
     * Usuario que verificó el pago
     */
    public function verificadoPor()
    {
        return $this->belongsTo(User::class, 'verificado_por');
    }

    /**
     * Pagos individuales registrados
     */
    public function pagos()
    {
        return $this->hasMany(PagoSolicitud::class, 'solicitud_cotizacion_id');
    }

    public function pagosAprobados()
    {
        return $this->hasMany(PagoSolicitud::class, 'solicitud_cotizacion_id')
                    ->where('estado', PagoSolicitud::ESTADO_APROBADO);
    }

    public function pagosPendientes()
    {
        return $this->hasMany(PagoSolicitud::class, 'solicitud_cotizacion_id')
                    ->where('estado', PagoSolicitud::ESTADO_PENDIENTE);
    }

    /**
     * Usuario que generó la factura
     */
    public function facturadaPor()
    {
        return $this->belongsTo(User::class, 'facturada_por');
    }

    /**
     * Usuario que despachó el pedido
     */
    public function despachadoPor()
    {
        return $this->belongsTo(User::class, 'despachado_por');
    }

    /**
     * Usuario que descontó el stock
     */
    public function stockDescontadoPor()
    {
        return $this->belongsTo(User::class, 'stock_descontado_por');
    }

    public function historialEstados()
    {
        return $this->hasMany(HistorialEstadoSolicitud::class, 'solicitud_cotizacion_id')
                    ->orderBy('created_at', 'desc');
    }

    public function logs()
    {
        return $this->hasMany(LogSolicitudCotizacion::class, 'solicitud_cotizacion_id')
                    ->orderBy('created_at', 'desc');
    }

    /**
     * Reservas de stock asociadas a esta cotización
     */
    public function reservas()
    {
        return $this->hasMany(ReservaStock::class, 'solicitud_cotizacion_id');
    }

    /**
     * Reservas activas de stock
     */
    public function reservasActivas()
    {
        return $this->reservas()->activas();
    }

    public function getTotalItemsAttribute()
    {
        return $this->items->sum('cantidad');
    }

    public function calcularMontoTotal()
    {
        $this->monto_total = $this->items->sum('precio_total');
        $this->save();
        return $this->monto_total;
    }

    public function marcarComoAplicada($usuarioId, $observaciones = null)
    {
        $this->update([
            'estado' => 'aplicada',
            'aplicada_en' => now(),
            'aplicada_por' => $usuarioId,
            'observaciones_admin' => $observaciones
        ]);
    }

    public function marcarComoRechazada($usuarioId, $motivoRechazo)
    {
        $this->update([
            'estado' => 'rechazada',
            'rechazada_en' => now(),
            'rechazada_por' => $usuarioId,
            'motivo_rechazo' => $motivoRechazo
        ]);
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($solicitud) {
            if (empty($solicitud->numero_solicitud)) {
                $solicitud->numero_solicitud = 'SC-' . now()->format('YmdHis') . '-' . strtoupper(Str::random(4));
            }
        });
    }

    public function scopePendientes($query)
    {
        return $query->where('estado', 'pendiente');
    }

    public function scopeAplicadas($query)
    {
        return $query->where('estado', 'aplicada');
    }

    public function scopeRechazadas($query)
    {
        return $query->where('estado', 'rechazada');
    }

    public function scopePorCliente($query, $clienteId)
    {
        return $query->where('cliente_id', $clienteId);
    }

    /**
     * Cotizaciones atribuidas a un vendedor (User).
     * Criterio idéntico al usado en SolicitudController::index:
     * las creó el vendedor (created_by) O pertenecen a un cliente suyo (cliente.vendedor_id).
     * Se envuelve en un where-closure para no romper la precedencia con otros filtros.
     */
    public function scopeDeVendedor($query, $vendedorId)
    {
        return $query->where(function ($q) use ($vendedorId) {
            $q->where('solicitudes_cotizacion.created_by', $vendedorId)
              ->orWhereHas('cliente', function ($c) use ($vendedorId) {
                  $c->where('vendedor_id', $vendedorId);
              });
        });
    }

    /**
     * Cotizaciones con reserva activa
     */
    public function scopeConReservaActiva($query)
    {
        return $query->where('tiene_reserva_stock', true)
                     ->where('reserva_expira_en', '>', now());
    }

    /**
     * Cotizaciones con reserva expirada
     */
    public function scopeConReservaExpirada($query)
    {
        return $query->where('tiene_reserva_stock', true)
                     ->where('reserva_expira_en', '<=', now())
                     ->whereNull('reserva_liberada_en');
    }

    // ==========================================
    // MÉTODOS DE RESERVA
    // ==========================================

    /**
     * Verifica si la cotización tiene reserva activa
     */
    public function tieneReservaActiva(): bool
    {
        return $this->tiene_reserva_stock
            && $this->reserva_expira_en
            && $this->reserva_expira_en > now()
            && is_null($this->reserva_liberada_en);
    }

    /**
     * Verifica si la reserva ha expirado
     */
    public function reservaExpirada(): bool
    {
        return $this->tiene_reserva_stock
            && $this->reserva_expira_en
            && $this->reserva_expira_en <= now()
            && is_null($this->reserva_liberada_en);
    }

    /**
     * Obtener tiempo restante de reserva
     */
    public function getTiempoRestanteReservaAttribute(): ?string
    {
        if (!$this->tieneReservaActiva()) {
            return null;
        }

        return $this->reserva_expira_en->diffForHumans(now(), [
            'syntax' => \Carbon\CarbonInterface::DIFF_RELATIVE_TO_NOW,
        ]);
    }

    /**
     * Renovar la reserva de stock
     */
    public function renovarReserva(int $horas = 24): bool
    {
        if (!$this->tiene_reserva_stock || $this->estado !== self::ESTADO_PENDIENTE) {
            return false;
        }

        $this->update([
            'reserva_expira_en' => now()->addHours($horas),
            'reserva_liberada_en' => null,
        ]);

        // Renovar también las reservas individuales
        $this->reservasActivas->each(function ($reserva) use ($horas) {
            $reserva->renovar($horas);
        });

        return true;
    }

    /**
     * Liberar todas las reservas de la cotización
     */
    public function liberarReservas(string $motivo, ?int $usuarioId = null): int
    {
        $liberadas = 0;

        $this->reservasActivas->each(function ($reserva) use ($motivo, $usuarioId, &$liberadas) {
            if ($reserva->liberar($motivo, $usuarioId)) {
                $liberadas++;
            }
        });

        if ($liberadas > 0) {
            $this->update([
                'tiene_reserva_stock' => false,
                'reserva_liberada_en' => now(),
            ]);
        }

        return $liberadas;
    }

    // ==========================================
    // MÉTODOS DE ESTADO Y EDICIÓN
    // ==========================================

    /**
     * Verifica si la cotización puede ser editada
     */
    public function esEditable(): bool
    {
        return $this->estado_pago !== self::PAGO_PAGADO;
    }

    /**
     * Determina si un vendedor puede editar la cotización según las reglas de pago.
     * - Si hay algún pago APROBADO: no puede editar.
     * - Si la cotización es a Crédito y existe cualquier pago registrado: no puede editar.
     * - Caso contrario: puede editar.
     */
    public function puedeEditarVendedor(): bool
    {
        if ($this->pagos()->where('estado', PagoSolicitud::ESTADO_APROBADO)->exists()) {
            return false;
        }

        // Cualquier forma de pago a Crédito (30/60/90) bloquea la edición de inmediato,
        // incluso si aún no hay pagos registrados o si antes hubo un pago parcial.
        if ($this->forma_pago_factura && str_contains($this->forma_pago_factura, 'Crédito')) {
            return false;
        }

        return true;
    }

    /**
     * Verifica si la cotización puede ser eliminada
     */
    public function esEliminable(): bool
    {
        return $this->estado === self::ESTADO_PENDIENTE;
    }

    /**
     * Marcar como editada
     */
    public function marcarComoEditada(int $usuarioId): void
    {
        $this->update([
            'editada_en' => now(),
            'editada_por' => $usuarioId,
        ]);
    }

    /**
     * Calcular monto total con flete y descuento
     */
    public function calcularMontoTotalConExtras(): float
    {
        $subtotal = $this->items->sum('precio_total');
        $flete = $this->valor_flete ?? 0;
        $descuento = $this->descuento_total ?? 0;

        return $subtotal + $flete - $descuento;
    }

    /**
     * Obtener el subtotal sin flete ni descuento
     */
    public function getSubtotalAttribute(): float
    {
        return $this->items->sum('precio_total');
    }

    /**
     * Obtener el monto total formateado
     */
    public function getMontoTotalFormateadoAttribute(): string
    {
        return '$' . number_format($this->monto_total, 0, ',', '.');
    }

    /**
     * Obtener color para badge según estado
     */
    public function getColorEstadoAttribute(): string
    {
        return match($this->estado) {
            self::ESTADO_PENDIENTE => $this->reservaExpirada() ? 'warning' : 'primary',
            self::ESTADO_APLICADA => 'success',
            self::ESTADO_RECHAZADA => 'danger',
            default => 'secondary',
        };
    }

    /**
     * Obtener icono según estado
     */
    public function getIconoEstadoAttribute(): string
    {
        return match($this->estado) {
            self::ESTADO_PENDIENTE => 'bi-clock',
            self::ESTADO_APLICADA => 'bi-check-circle',
            self::ESTADO_RECHAZADA => 'bi-x-circle',
            default => 'bi-question-circle',
        };
    }

    /**
     * Obtener badge HTML del estado de reserva
     */
    public function getBadgeReservaAttribute(): string
    {
        if (!$this->tiene_reserva_stock) {
            return '<span class="badge bg-secondary">Sin reserva</span>';
        }

        if ($this->reserva_liberada_en) {
            return '<span class="badge bg-info">Liberada</span>';
        }

        if ($this->reservaExpirada()) {
            return '<span class="badge bg-warning text-dark">Expirada</span>';
        }

        if ($this->tieneReservaActiva()) {
            return '<span class="badge bg-success">Activa</span>';
        }

        return '<span class="badge bg-secondary">-</span>';
    }

    // ==========================================
    // MÉTODOS DE PAGO Y FACTURACIÓN
    // ==========================================

    /**
     * Verificar si la cotización está pagada
     */
    public function estaPagada(): bool
    {
        return $this->estado_pago === self::PAGO_PAGADO;
    }

    /**
     * Verificar si tiene pago parcial
     */
    public function tienePagoParcial(): bool
    {
        return $this->estado_pago === self::PAGO_PARCIAL;
    }

    /**
     * Verificar si puede registrar pago
     */
    public function puedeRegistrarPago(): bool
    {
        if ($this->estado_pago !== self::PAGO_PAGADO) {
            return true;
        }

        // Permitir registrar pagos reales en cotizaciones a crédito
        if ($this->forma_pago_factura && str_contains($this->forma_pago_factura, 'Crédito')) {
            return true;
        }

        return false;
    }

    /**
     * Verificar si puede generar factura
     */
    public function puedeGenerarFactura(): bool
    {
        return $this->estado === self::ESTADO_APLICADA
            && $this->estado_pago === self::PAGO_PAGADO
            && is_null($this->numero_factura);
    }

    /**
     * Verificar si tiene factura generada
     */
    public function tieneFactura(): bool
    {
        return !is_null($this->numero_factura);
    }

    /**
     * Registrar pago (queda pendiente de aprobación)
     */
    public function registrarPago(
        float $monto,
        string $metodoPago,
        $comprobante = null,
        ?string $notas = null,
        ?int $registradoPor = null
    ): PagoSolicitud {
        return $this->pagos()->create([
            'monto' => $monto,
            'metodo_pago' => $metodoPago,
            'comprobante' => $comprobante,
            'notas' => $notas,
            'registrado_por' => $registradoPor,
            'estado' => PagoSolicitud::ESTADO_PENDIENTE,
        ]);
    }

    /**
     * Aprobar un pago pendiente y recalcular totales
     */
    public function aprobarPago(PagoSolicitud $pago, int $aprobadoPor): void
    {
        if (!$pago->estaPendiente()) {
            throw new \Exception('Este pago ya fue procesado');
        }

        \DB::transaction(function () use ($pago, $aprobadoPor) {
            $pago->update([
                'estado' => PagoSolicitud::ESTADO_APROBADO,
                'aprobado_por' => $aprobadoPor,
                'aprobado_en' => now(),
            ]);

            $this->recalcularPagos();
        });
    }

    /**
     * Rechazar un pago pendiente
     */
    public function rechazarPago(PagoSolicitud $pago, int $rechazadoPor): void
    {
        if (!$pago->estaPendiente()) {
            throw new \Exception('Este pago ya fue procesado');
        }

        $pago->update([
            'estado' => PagoSolicitud::ESTADO_RECHAZADO,
            'aprobado_por' => $rechazadoPor,
            'aprobado_en' => now(),
        ]);
    }

    /**
     * Recalcular totales de pago basado solo en pagos aprobados
     * Para crédito, excluye pagos con método 'credito' (placeholder antiguo)
     */
    public function recalcularPagos(): void
    {
        $query = $this->pagos()->where('estado', PagoSolicitud::ESTADO_APROBADO);

        // Excluir pagos placeholder de crédito para cotizaciones a crédito
        if ($this->forma_pago_factura && str_contains($this->forma_pago_factura, 'Crédito')) {
            $query = $query->where('metodo_pago', '!=', 'credito');
        }

        $totalAprobado = $query->sum('monto');

        $montoTotal = $this->monto_total_con_iva;
        $totalAprobado = min($totalAprobado, $montoTotal);

        $estadoPago = self::PAGO_PENDIENTE;
        if ($totalAprobado >= $montoTotal) {
            $estadoPago = self::PAGO_PAGADO;
        } elseif ($totalAprobado > 0) {
            $estadoPago = self::PAGO_PARCIAL;
        }

        $ultimoPago = $this->pagos()
            ->where('estado', PagoSolicitud::ESTADO_APROBADO)
            ->where('metodo_pago', '!=', 'credito')
            ->latest()
            ->first();

        $this->update([
            'estado_pago' => $estadoPago,
            'monto_pagado' => $totalAprobado,
            'metodo_pago' => $ultimoPago?->metodo_pago,
            'comprobante_pago' => $ultimoPago?->comprobante,
            'pagado_en' => $estadoPago === self::PAGO_PAGADO ? now() : $this->pagado_en,
            'verificado_por' => $ultimoPago?->aprobado_por,
            'verificado_en' => $ultimoPago ? now() : null,
        ]);
    }

    /**
     * Obtener color para badge de estado de pago
     */
    public function getColorEstadoPagoAttribute(): string
    {
        if ($this->forma_pago_factura && str_contains($this->forma_pago_factura, 'Mixto')) {
            return 'info';
        }

        if ($this->forma_pago_factura && str_contains($this->forma_pago_factura, 'Crédito')) {
            return 'pink';
        }

        return match($this->estado_pago) {
            self::PAGO_PENDIENTE => 'warning',
            self::PAGO_PARCIAL => 'info',
            self::PAGO_PAGADO => 'success',
            default => 'secondary',
        };
    }

    /**
     * Obtener etiqueta de estado de pago
     */
    public function getEtiquetaEstadoPagoAttribute(): string
    {
        if ($this->forma_pago_factura && str_contains($this->forma_pago_factura, 'Mixto')) {
            return 'Mixto';
        }

        if ($this->forma_pago_factura && str_contains($this->forma_pago_factura, 'Crédito')) {
            return 'Crédito';
        }

        return match($this->estado_pago) {
            self::PAGO_PENDIENTE => 'Pendiente',
            self::PAGO_PARCIAL => 'Parcial',
            self::PAGO_PAGADO => 'Pagado',
            default => '-',
        };
    }

    /**
     * Obtener monto total con IVA incluido
     */
    public function getMontoTotalConIvaAttribute(): float
    {
        return ($this->monto_total ?? 0) + ($this->valor_iva ?? 0);
    }

    /**
     * Obtener saldo pendiente (incluye IVA)
     * Para crédito, solo cuenta pagos reales (excluye pagos con método 'credito')
     */
    public function getSaldoPendienteAttribute(): float
    {
        if ($this->forma_pago_factura && str_contains($this->forma_pago_factura, 'Crédito')) {
            $pagosReales = $this->pagos()
                ->where('estado', PagoSolicitud::ESTADO_APROBADO)
                ->where('metodo_pago', '!=', 'credito')
                ->sum('monto');
            return max(0, $this->monto_total_con_iva - $pagosReales);
        }

        return max(0, $this->monto_total_con_iva - $this->monto_pagado);
    }

    /**
     * Scopes para filtros de pago
     */
    public function scopePagoPendiente($query)
    {
        return $query->where('estado_pago', self::PAGO_PENDIENTE);
    }

    public function scopePagoParcial($query)
    {
        return $query->where('estado_pago', self::PAGO_PARCIAL);
    }

    public function scopePagado($query)
    {
        return $query->where('estado_pago', self::PAGO_PAGADO);
    }

    public function scopeConFactura($query)
    {
        return $query->whereNotNull('numero_factura');
    }

    public function scopeSinFactura($query)
    {
        return $query->whereNull('numero_factura');
    }

    // ==========================================
    // MÉTODOS DE ENVÍO
    // ==========================================

    /**
     * Verificar si se puede descargar la guía de envío
     */
    public function puedeDescargarGuia(): bool
    {
        return in_array($this->estado_envio, [
            self::ENVIO_DESPACHADO,
            self::ENVIO_EN_TRANSITO,
            self::ENVIO_ENTREGADO
        ]) && !empty($this->archivo_guia);
    }

    /**
     * Verificar si se puede descargar la factura
     */
    public function puedeDescargarFactura(): bool
    {
        return $this->tieneFactura() && !empty($this->archivo_factura);
    }

    /**
     * Obtener color para badge de estado de envío
     */
    public function getColorEstadoEnvioAttribute(): string
    {
        return match($this->estado_envio) {
            self::ENVIO_PENDIENTE => 'secondary',
            self::ENVIO_PREPARANDO => 'info',
            self::ENVIO_DESPACHADO => 'primary',
            self::ENVIO_EN_TRANSITO => 'warning',
            self::ENVIO_ENTREGADO => 'success',
            default => 'secondary',
        };
    }

    /**
     * Obtener etiqueta de estado de envío
     */
    public function getEtiquetaEstadoEnvioAttribute(): string
    {
        return self::estadosEnvio()[$this->estado_envio] ?? '-';
    }

    /**
     * Obtener icono según estado de envío
     */
    public function getIconoEstadoEnvioAttribute(): string
    {
        return match($this->estado_envio) {
            self::ENVIO_PENDIENTE => 'bi-clock',
            self::ENVIO_PREPARANDO => 'bi-box-seam',
            self::ENVIO_DESPACHADO => 'bi-truck',
            self::ENVIO_EN_TRANSITO => 'bi-geo-alt',
            self::ENVIO_ENTREGADO => 'bi-check-circle',
            default => 'bi-question-circle',
        };
    }

    /**
     * Actualizar estado de envío
     */
    public function actualizarEstadoEnvio(
        string $nuevoEstado,
        ?string $numeroGuia = null,
        ?string $transportadora = null,
        ?string $archivoGuia = null,
        ?int $despachadoPor = null
    ): void {
        $datos = ['estado_envio' => $nuevoEstado];

        if ($numeroGuia !== null) {
            $datos['numero_guia'] = $numeroGuia;
        }

        if ($transportadora !== null) {
            $datos['transportadora'] = $transportadora;
        }

        if ($archivoGuia !== null) {
            $datos['archivo_guia'] = $archivoGuia;
        }

        // Registrar timestamps según estado
        if ($nuevoEstado === self::ENVIO_DESPACHADO) {
            $datos['despachado_en'] = now();
            $datos['despachado_por'] = $despachadoPor;
        } elseif ($nuevoEstado === self::ENVIO_ENTREGADO) {
            $datos['entregado_en'] = now();
        }

        $this->update($datos);
    }

    /**
     * Verificar si el pedido está despachado o en camino
     */
    public function estaDespachado(): bool
    {
        return in_array($this->estado_envio, [
            self::ENVIO_DESPACHADO,
            self::ENVIO_EN_TRANSITO,
            self::ENVIO_ENTREGADO
        ]);
    }

    /**
     * Verificar si el pedido fue entregado
     */
    public function estaEntregado(): bool
    {
        return $this->estado_envio === self::ENVIO_ENTREGADO;
    }

    /**
     * Scopes para filtros de envío
     */
    public function scopeEnvioPendiente($query)
    {
        return $query->where('estado_envio', self::ENVIO_PENDIENTE);
    }

    public function scopeEnvioPreparando($query)
    {
        return $query->where('estado_envio', self::ENVIO_PREPARANDO);
    }

    public function scopeDespachados($query)
    {
        return $query->whereIn('estado_envio', [
            self::ENVIO_DESPACHADO,
            self::ENVIO_EN_TRANSITO
        ]);
    }

    public function scopeEntregados($query)
    {
        return $query->where('estado_envio', self::ENVIO_ENTREGADO);
    }

    // ==========================================
    // SCOPE PARA PORTAL CLIENTE
    // ==========================================

    /**
     * Filtrar solicitudes del cliente autenticado
     */
    public function scopeDelClienteAutenticado($query)
    {
        $clienteIds = Cliente::where('user_id', auth()->id())->pluck('id');
        return $query->whereIn('cliente_id', $clienteIds);
    }
}