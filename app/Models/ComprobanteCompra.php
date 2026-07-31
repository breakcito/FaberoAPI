<?php

namespace App\Models;

use App\Shared\Enums\ContabilidadCompra\EstadoComprobanteCompra;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ComprobanteCompra extends Model
{
    protected $table = 'comprobante_compra';

    public $timestamps = false;

    protected $fillable = [
        'id_valorizacion_compra',
        'id_tipo_cambio',
        'id_empleado_registro',
        'serie',
        'numero',
        'fecha_emision',
        'evidencias',
        'tipo_cambio_venta',
        'porcentaje_igv',
        'porcentaje_detraccion',
        'total_dolares',
        'total_soles',
        'monto_igv_soles',
        'monto_pagado_anticipos',
        'monto_detraccion',
        'monto_detraccion_soles',
        'monto_neto',
        'avance_pago_neto',
        'avance_pago_detraccion',
        'aprobaciones',
        'created_at',
        'estado',
    ];

    protected $casts = [
        'fecha_emision' => 'date:Y-m-d',
        'created_at' => 'datetime',
        'evidencias' => 'array',
        'aprobaciones' => 'array',
        'tipo_cambio_venta' => 'float',
        'porcentaje_igv' => 'float',
        'porcentaje_detraccion' => 'float',
        'total_dolares' => 'float',
        'total_soles' => 'float',
        'monto_igv_soles' => 'float',
        'monto_pagado_anticipos' => 'float',
        'monto_detraccion' => 'float',
        'monto_detraccion_soles' => 'float',
        'monto_neto' => 'float',
        'avance_pago_neto' => 'float',
        'avance_pago_detraccion' => 'float',
        'estado' => EstadoComprobanteCompra::class,
    ];

    public function valorizacion(): BelongsTo
    {
        return $this->belongsTo(ValorizacionCompra::class, 'id_valorizacion_compra');
    }

    public function tipoCambio(): BelongsTo
    {
        return $this->belongsTo(TipoCambio::class, 'id_tipo_cambio');
    }

    public function empleadoRegistro(): BelongsTo
    {
        return $this->belongsTo(Empleado::class, 'id_empleado_registro');
    }

    public function pagos(): HasMany
    {
        return $this->hasMany(PagoComprobanteCompra::class, 'id_comprobante_compra');
    }
}
