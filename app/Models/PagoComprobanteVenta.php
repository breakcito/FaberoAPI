<?php

namespace App\Models;

use App\Shared\Enums\ContabilidadVenta\MedioPagoComprobanteVenta;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PagoComprobanteVenta extends Model
{
    protected $table = 'pago_comprobante_venta';

    public $timestamps = false;

    protected $fillable = [
        'id_comprobante_venta',
        'id_cuenta_bancaria_planta',
        'id_cuenta_bancaria_empresa',
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
        'id_comprobante_venta' => 'integer',
        'id_cuenta_bancaria_planta' => 'integer',
        'id_cuenta_bancaria_empresa' => 'integer',
        'id_empleado_registro' => 'integer',
        'id_empleado_anulacion' => 'integer',
        'es_para_detraccion' => 'boolean',
        'es_anulado' => 'boolean',
        'monto_pagado' => 'float',
        'fecha_hora_pago' => 'datetime',
        'fecha_hora_anulacion' => 'datetime',
        'created_at' => 'datetime',
        'evidencias' => 'array',
        'evidencias_anulacion' => 'array',
        'medio_pago' => MedioPagoComprobanteVenta::class,
    ];

    public function comprobante(): BelongsTo
    {
        return $this->belongsTo(ComprobanteVenta::class, 'id_comprobante_venta');
    }

    public function cuentaBancariaPlanta(): BelongsTo
    {
        return $this->belongsTo(CuentaBancariaPlantaDestino::class, 'id_cuenta_bancaria_planta');
    }

    public function cuentaBancariaEmpresa(): BelongsTo
    {
        return $this->belongsTo(CuentaBancariaEmpresa::class, 'id_cuenta_bancaria_empresa');
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
