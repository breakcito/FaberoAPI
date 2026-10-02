<?php

namespace App\Models;

use App\Shared\Enums\ContabilidadVenta\EstadoComprobanteVenta;
use App\Shared\Enums\ContabilidadVenta\TipoPagoComprobanteVenta;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ComprobanteVenta extends Model
{
    protected $table = 'comprobante_venta';

    public $timestamps = false;

    protected $fillable = [
        'id_empresa',
        'id_planta_destino',
        'id_tipo_cambio',
        'id_empleado_registro',
        'id_empleado_anulacion',
        'tipo_pago',
        'codigo_comprobante',
        'fecha_emision',
        'evidencias',
        'tipo_cambio_venta',
        'percentaje_igv',
        'porcentaje_detraccion',
        'total_dolares_antes_descuento',
        'total_soles_antes_descuento',
        'descuento',
        'total_dolares',
        'total_soles',
        'monto_igv_soles',
        'monto_pagado_anticipos',
        'monto_detraccion',
        'monto_detraccion_soles',
        'monto_neto',
        'avance_pago_neto',
        'avance_pago_detraccion',
        'created_at',
        'estado',
    ];

    protected $casts = [
        'id_empresa' => 'integer',
        'id_planta_destino' => 'integer',
        'id_tipo_cambio' => 'integer',
        'id_empleado_registro' => 'integer',
        'id_empleado_anulacion' => 'integer',
        'fecha_emision' => 'date:Y-m-d',
        'created_at' => 'datetime',
        'evidencias' => 'array',
        'tipo_cambio_venta' => 'float',
        'percentaje_igv' => 'float',
        'porcentaje_detraccion' => 'float',
        'total_dolares_antes_descuento' => 'float',
        'total_soles_antes_descuento' => 'float',
        'descuento' => 'float',
        'total_dolares' => 'float',
        'total_soles' => 'float',
        'monto_igv_soles' => 'float',
        'monto_pagado_anticipos' => 'float',
        'monto_detraccion' => 'float',
        'monto_detraccion_soles' => 'float',
        'monto_neto' => 'float',
        'avance_pago_neto' => 'float',
        'avance_pago_detraccion' => 'float',
        'tipo_pago' => TipoPagoComprobanteVenta::class,
        'estado' => EstadoComprobanteVenta::class,
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'id_empresa', 'id_empresa');
    }

    public function plantaDestino(): BelongsTo
    {
        return $this->belongsTo(PlantaDestino::class, 'id_planta_destino');
    }

    public function tipoCambio(): BelongsTo
    {
        return $this->belongsTo(TipoCambio::class, 'id_tipo_cambio');
    }

    public function empleadoRegistro(): BelongsTo
    {
        return $this->belongsTo(Empleado::class, 'id_empleado_registro');
    }

    public function empleadoAnulacion(): BelongsTo
    {
        return $this->belongsTo(Empleado::class, 'id_empleado_anulacion');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(DetalleComprobanteVenta::class, 'id_comprobante_venta');
    }

    public function pagos(): HasMany
    {
        return $this->hasMany(PagoComprobanteVenta::class, 'id_comprobante_venta');
    }

    public function transaccionesAnticipo(): HasMany
    {
        return $this->hasMany(TransaccionAnticipoPlanta::class, 'id_comprobante_venta');
    }
}
