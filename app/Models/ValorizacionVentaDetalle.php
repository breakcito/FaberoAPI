<?php

namespace App\Models;

use App\Shared\Enums\_Generic\ElementoQuimicoValorizacion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ValorizacionVentaDetalle extends Model
{
    protected $table = 'valorizacion_venta_detalle';

    public $timestamps = false;

    protected $fillable = [
        'id_valorizacion_venta',
        'id_despacho_detalle',
        'id_condicion_comercial',
        'id_valor_elemento_quimico',
        'elemento_quimico',
        'inter',
        'des_inter',
        'recuperacion',
        'maquila',
        'consumo',
        'factor',
        'precio_por_tonelada',
        'subtotal',
        'tiene_comprobante',
        'log_cambios',
    ];

    protected $casts = [
        'id_valorizacion_venta' => 'integer',
        'id_despacho_detalle' => 'integer',
        'id_condicion_comercial' => 'integer',
        'id_valor_elemento_quimico' => 'integer',
        'inter' => 'float',
        'des_inter' => 'float',
        'recuperacion' => 'float',
        'maquila' => 'float',
        'consumo' => 'float',
        'factor' => 'float',
        'precio_por_tonelada' => 'float',
        'subtotal' => 'float',
        'tiene_comprobante' => 'boolean',
        'log_cambios' => 'array',
        'elemento_quimico' => ElementoQuimicoValorizacion::class,
    ];

    public function valorizacion(): BelongsTo
    {
        return $this->belongsTo(ValorizacionVenta::class, 'id_valorizacion_venta');
    }

    public function despachoDetalle(): BelongsTo
    {
        return $this->belongsTo(DespachoDetalle::class, 'id_despacho_detalle');
    }

    public function condicionComercial(): BelongsTo
    {
        return $this->belongsTo(CondicionComercialPlanta::class, 'id_condicion_comercial');
    }
}
