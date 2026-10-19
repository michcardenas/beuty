<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Cliente extends Model
{
    use HasFactory;

    protected $table = 'clientes';

    protected $fillable = [
        'numero_identificacion',
        'tipo_cliente',
        'razon_social',
        'nit',
        'representante_legal',
        'nombre_contacto',
        'email',
        'emails_adicionales',
        'telefono',
        'direccion',
        'ciudad_id',
        'pais_id',
        'vendedor_id',
        'user_id',
        'lista_precio_id',
        'valor_flete',
        'aplica_flete',
        'observaciones',
        'activo',
        'tipo_documento',
        'siigo_id',
    ];

    protected $casts = [
        'activo' => 'boolean',
        'aplica_flete' => 'boolean',
        'valor_flete' => 'decimal:2',
        'emails_adicionales' => 'array',
    ];

    // =========================================
    // Constantes
    // =========================================
    const TIPO_NATURAL = 'natural';
    const TIPO_JURIDICA = 'juridica';

    public static function tiposCliente(): array
    {
        return [
            self::TIPO_NATURAL => 'Persona Natural',
            self::TIPO_JURIDICA => 'Persona Jurídica',
        ];
    }

    // =========================================
    // Accessors
    // =========================================
    public function getEsPersonaJuridicaAttribute(): bool
    {
        return $this->tipo_cliente === self::TIPO_JURIDICA;
    }

    public function getNombreCompletoAttribute(): string
    {
        if ($this->es_persona_juridica) {
            return $this->razon_social ?? $this->nombre_contacto;
        }
        return $this->nombre_contacto;
    }

    /**
     * Identificación con la que se factura en SIIGO. A una persona jurídica se
     * le factura por el NIT (sin dígito de verificación): en varias empresas el
     * campo numero_identificacion tiene la cédula del representante legal, y
     * con ella la factura salía a nombre de la persona natural.
     */
    public function getIdentificacionFacturacionAttribute(): ?string
    {
        $nit = preg_replace('/\D/', '', explode('-', (string) $this->nit)[0]);
        if ($this->es_persona_juridica && $nit !== '') {
            return $nit;
        }

        return ($this->numero_identificacion ?: $this->nit) ?: null;
    }

    public function getEmailsAdicionalesArrayAttribute(): array
    {
        return is_array($this->emails_adicionales) ? $this->emails_adicionales : [];
    }
    public function pais()
    {
        return $this->belongsTo(Pais::class);
    }

    public function ciudad()
    {
        return $this->belongsTo(Ciudad::class);
    }
    public function vendedor()
    {
        return $this->belongsTo(User::class, 'vendedor_id');
    }

    /**
     * Cuenta de usuario del cliente (para acceso al portal)
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Verificar si el cliente tiene cuenta de usuario
     */
    public function tieneCuentaUsuario(): bool
    {
        return !is_null($this->user_id);
    }

    public function listaPrecio()
    {
        return $this->belongsTo(ListaPrecio::class, 'lista_precio_id');
    }

    public function enlacesAcceso()
    {
        return $this->hasMany(EnlaceAcceso::class, 'cliente_id');
    }

    public function enlacesAccesoActivos()
    {
        return $this->hasMany(EnlaceAcceso::class, 'cliente_id')
            ->where('activo', true)
            ->where('expira_en', '>', now());
    }

    public function solicitudesCotizacion()
    {
        return $this->hasMany(SolicitudCotizacion::class, 'cliente_id');
    }

    public function sucursales()
    {
        return $this->hasMany(Sucursal::class, 'cliente_id');
    }

    public function sucursalesActivas()
    {
        return $this->hasMany(Sucursal::class, 'cliente_id')->where('activo', true);
    }

    public function sucursalPrincipal()
    {
        return $this->hasOne(Sucursal::class, 'cliente_id')->where('es_principal', true);
    }

    public function documentos()
    {
        return $this->hasMany(DocumentoCliente::class, 'cliente_id');
    }

    public function garantias()
    {
        return $this->hasMany(Garantia::class, 'cliente_id');
    }

    public function garantiasPendientes()
    {
        return $this->hasMany(Garantia::class, 'cliente_id')
            ->where('estado', Garantia::ESTADO_PENDIENTE);
    }

    public function scopeActivos($query)
    {
        return $query->where('activo', true);
    }

    public function scopePorVendedor($query, $vendedorId)
    {
        return $query->where('vendedor_id', $vendedorId);
    }
}