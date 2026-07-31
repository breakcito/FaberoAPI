<?php

namespace App\Models;

use App\Shared\Enums\ValorizacionCompra\EstadoValorizacionCompra;
use App\Shared\Enums\ValorizacionCompra\TipoPagoValorizacionCompra;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ValorizacionCompra extends Model
{
    protected $table = 'valorizacion_compra';

    public $timestamps = false;

    protected $fillable = [
        'id_proveedor_minero',
        'id_concesion',
        'id_cuenta_bancaria',
        'id_cuenta_detraccion',
        'id_empleado_registro',
        'id_empleado_aprobacion',
        'numero_correlativo',
        'tipo_pago',
        'evidencias',
        'log_cambios',
        'fecha_hora_aprobacion',
        'created_at',
        'estado',
    ];

    protected $casts = [
        'id_proveedor_minero' => 'integer',
        'id_concesion' => 'integer',
        'id_cuenta_bancaria' => 'integer',
        'id_cuenta_detraccion' => 'integer',
        'id_empleado_registro' => 'integer',
        'id_empleado_aprobacion' => 'integer',
        'evidencias' => 'array',
        'log_cambios' => 'array',
        'fecha_hora_aprobacion' => 'datetime',
        'created_at' => 'datetime',
        'tipo_pago' => TipoPagoValorizacionCompra::class,
        'estado' => EstadoValorizacionCompra::class,
    ];

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class, 'id_proveedor_minero');
    }

    public function concesion(): BelongsTo
    {
        return $this->belongsTo(Concesion::class, 'id_concesion');
    }

    public function cuentaBancaria(): BelongsTo
    {
        return $this->belongsTo(CuentaBancariaProveedor::class, 'id_cuenta_bancaria');
    }

    public function cuentaDetraccion(): BelongsTo
    {
        return $this->belongsTo(CuentaBancariaProveedor::class, 'id_cuenta_detraccion');
    }

    public function empleadoRegistro(): BelongsTo
    {
        return $this->belongsTo(Empleado::class, 'id_empleado_registro');
    }

    public function empleadoAprobacion(): BelongsTo
    {
        return $this->belongsTo(Empleado::class, 'id_empleado_aprobacion');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(ValorizacionCompraDetalle::class, 'id_valorizacion_compra');
    }

    public function transaccionesAnticipo(): HasMany
    {
        return $this->hasMany(TransaccionAnticipoProveedor::class, 'id_valorizacion_compra');
    }
}
