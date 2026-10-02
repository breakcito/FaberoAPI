<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DetalleComprobanteVenta extends Model
{
    protected $table = 'detalle_comprobante_venta';

    public $timestamps = false;

    protected $fillable = [
        'id_comprobante_venta',
        'id_valorizacion_venta_detalle',
    ];

    protected $casts = [
        'id_comprobante_venta' => 'integer',
        'id_valorizacion_venta_detalle' => 'integer',
    ];

    public function comprobante(): BelongsTo
    {
        return $this->belongsTo(ComprobanteVenta::class, 'id_comprobante_venta');
    }

    public function valorizacionVentaDetalle(): BelongsTo
    {
        return $this->belongsTo(ValorizacionVentaDetalle::class, 'id_valorizacion_venta_detalle');
    }
}
