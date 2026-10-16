<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FacturaSiigo extends Model
{
    use HasFactory;

    protected $table = 'facturas_siigo';

    protected $fillable = [
        'venta_pdv_id',
        'solicitud_cotizacion_id',
        'tipo_documento',
        'siigo_document_type_id',
        'siigo_invoice_id',
        'numero_factura',
        'cufe',
        'fecha_emision',
        'subtotal',
        'iva',
        'total',
        'estado_dian',
        'estado_envio_email',
        'email_destino',
        'siigo_request',
        'siigo_response',
        'errores',
        'intentos',
        'ultimo_intento_en',
        'nota_credito_de',
        'cliente_id',
        'usuario_id',
    ];

    protected $casts = [
        'fecha_emision' => 'date',
        'subtotal' => 'decimal:2',
        'iva' => 'decimal:2',
        'total' => 'decimal:2',
        'siigo_request' => 'array',
        'siigo_response' => 'array',
        'intentos' => 'integer',
        'ultimo_intento_en' => 'datetime',
    ];

    // Relationships

    public function ventaPdv()
    {
        return $this->belongsTo(VentaPdv::class);
    }

    public function solicitudCotizacion()
    {
        return $this->belongsTo(SolicitudCotizacion::class);
    }

    public function esDeCotizacion(): bool
    {
        return !is_null($this->solicitud_cotizacion_id);
    }

    public function cliente()
    {
        return $this->belongsTo(Cliente::class);
    }

    public function usuario()
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function facturaOriginal()
    {
        return $this->belongsTo(self::class, 'nota_credito_de');
    }

    public function notasCredito()
    {
        return $this->hasMany(self::class, 'nota_credito_de');
    }

    // Scopes

    public function scopePendientes($query)
    {
        return $query->where('estado_dian', 'pendiente');
    }

    public function scopeAprobadas($query)
    {
        return $query->where('estado_dian', 'aprobada');
    }

    public function scopeRechazadas($query)
    {
        return $query->where('estado_dian', 'rechazada');
    }

    public function scopeConError($query)
    {
        return $query->where('estado_dian', 'error');
    }

    public function scopeReintentables($query)
    {
        $maxReintentos = (int) ConfiguracionPdv::obtener('siigo_max_reintentos', 3);
        return $query->whereIn('estado_dian', ['pendiente', 'error'])
            ->where('intentos', '<', $maxReintentos);
    }

    // Methods

    public function estaAprobada(): bool
    {
        return $this->estado_dian === 'aprobada';
    }

    public function puedeReintentar(): bool
    {
        $maxReintentos = (int) ConfiguracionPdv::obtener('siigo_max_reintentos', 3);
        return in_array($this->estado_dian, ['pendiente', 'error', 'rechazada'])
            && $this->intentos < $maxReintentos;
    }

    public function marcarAprobada(string $cufe, ?string $numeroFactura = null): void
    {
        $this->update([
            'estado_dian' => 'aprobada',
            'cufe' => $cufe,
            'numero_factura' => $numeroFactura ?? $this->numero_factura,
            'errores' => null,
        ]);
    }

    public function marcarRechazada(?string $errores = null): void
    {
        $this->update([
            'estado_dian' => 'rechazada',
            'errores' => $errores,
        ]);
    }

    public function marcarError(string $error): void
    {
        $this->update([
            'estado_dian' => 'error',
            'errores' => $error,
        ]);
    }

    public function incrementarIntento(): void
    {
        $this->update([
            'intentos' => $this->intentos + 1,
            'ultimo_intento_en' => now(),
        ]);
    }

    // Accessors

    public function getEstadoBadgeAttribute(): string
    {
        return match ($this->estado_dian) {
            'aprobada' => '<span class="badge bg-success">Aprobada</span>',
            'pendiente' => '<span class="badge bg-warning text-dark">Pendiente</span>',
            'rechazada' => '<span class="badge bg-danger">Rechazada</span>',
            'error' => '<span class="badge bg-danger">Error</span>',
            default => '<span class="badge bg-secondary">Desconocido</span>',
        };
    }
}
