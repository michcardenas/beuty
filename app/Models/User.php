<?php

namespace App\Models;

 use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles; 
use App\Models\Lead;
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    /** Roles que envían cotizaciones a la DIAN (factura electrónica por SIIGO). */
    public const ROLES_FACTURAN_COTIZACIONES = ['admin', 'auxiliar_administrativo'];

    /**
     * Puede enviar cotizaciones a la DIAN: va por rol (admin y auxiliar
     * administrativo), no por persona.
     */
    public function puedeFacturarCotizaciones(): bool
    {
        return $this->activo && $this->hasAnyRole(self::ROLES_FACTURAN_COTIZACIONES);
    }

    protected $fillable = [
        'name',
        'email',
        'password',
        'telefono',
        'activo',
        'ultimo_login',
        'pin_pdv',
        'ubicacion_id'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'last_synced_at' => 'datetime',
        'email_verified_at' => 'datetime',
               'activo' => 'boolean',
        'ultimo_login' => 'datetime',
    ];

        public function clientes()
    {
        return $this->hasMany(Cliente::class, 'vendedor_id');
    }

    public function ubicacion()
    {
        return $this->belongsTo(Ubicacion::class, 'ubicacion_id');
    }

    public function enlacesCreados()
    {
        return $this->hasMany(EnlaceAcceso::class, 'creado_por');
    }

    public function solicitudesAplicadas()
    {
        return $this->hasMany(SolicitudCotizacion::class, 'aplicada_por');
    }

    public function actualizacionesPrecios()
    {
        return $this->hasMany(ActualizacionPrecio::class, 'usuario_id');
    }

    public function esAdmin()
    {
        return $this->tipo_usuario === 'admin';
    }

    public function esVendedor()
    {
        return $this->tipo_usuario === 'vendedor';
    }

    public function registrarLogin()
    {
        $this->update(['ultimo_login' => now()]);
    }

    public function scopeActivos($query)
    {
        return $query->where('activo', true);
    }

    public function scopeVendedores($query)
    {
        return $query->where('tipo_usuario', 'vendedor');
    }

    public function scopeAdministradores($query)
    {
        return $query->where('tipo_usuario', 'admin');
    }
}
