<?php

namespace App\Models;

use App\Shared\Enums\ContabilidadCompra\MedioPagoComprobante;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PagoComprobanteCompra extends Model
{
    protected $table = 'pago_comprobante_compra';

    public $timestamps = false;

    protected $fillable = [
        'id_comprobante_compra',
        'id_cuenta_bancaria_empresa',
        'id_cuenta_bancaria_proveedor',
        'id_empleado_registro',
        'id_empleado_anulacion',
        'es_para_detraccion',
        'medio_pago',
        'monto_pagado',
        'fecha_hora_pago',
        'numero_operacion',
        'observacion',
        'evidencias',
        'fecha_hora_anulacion',
        'motivo_anulacion',
        'evidencias_anulacion',
        'es_anulado',
        'created_at',
    ];

    protected $casts = [
        'es_para_detraccion' => 'boolean',
        'es_anulado' => 'boolean',
        'monto_pagado' => 'float',
        'fecha_hora_pago' => 'datetime',
        'fecha_hora_anulacion' => 'datetime',
        'created_at' => 'datetime',
        'evidencias' => 'array',
        'evidencias_anulacion' => 'array',
        'medio_pago' => MedioPagoComprobante::class,
    ];

    public function comprobante(): BelongsTo
    {
        return $this->belongsTo(ComprobanteCompra::class, 'id_comprobante_compra');
    }

    public function cuentaBancariaEmpresa(): BelongsTo
    {
        return $this->belongsTo(CuentaBancariaEmpresa::class, 'id_cuenta_bancaria_empresa');
    }

    public function cuentaBancariaProveedor(): BelongsTo
    {
        return $this->belongsTo(CuentaBancariaProveedor::class, 'id_cuenta_bancaria_proveedor');
    }

    public function empleadoRegistro(): BelongsTo
    {
        return $this->belongsTo(Empleado::class, 'id_empleado_registro');
    }

    public function empleadoAnulacion(): BelongsTo
    {
        return $this->belongsTo(Empleado::class, 'id_empleado_anulacion');
    }
}
