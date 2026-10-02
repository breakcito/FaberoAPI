<?php

namespace App\Models;

use App\Shared\Enums\ValorizacionCompra\EstadoTransaccionAnticipo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransaccionAnticipoPlanta extends Model
{
    protected $table = 'transaccion_anticipo_planta';

    public $timestamps = false;

    protected $fillable = [
        'id_anticipo_planta',
        'id_comprobante_venta',
        'saldo_actual',
        'monto_retirado',
        'log_cambios',
        'created_at',
        'estado',
    ];

    protected $casts = [
        'id_anticipo_planta' => 'integer',
        'id_comprobante_venta' => 'integer',
        'saldo_actual' => 'float',
        'monto_retirado' => 'float',
        'log_cambios' => 'array',
        'created_at' => 'datetime',
        'estado' => EstadoTransaccionAnticipo::class,
    ];

    public function anticipo(): BelongsTo
    {
        return $this->belongsTo(AnticipoPlanta::class, 'id_anticipo_planta');
    }

    public function comprobante(): BelongsTo
    {
        return $this->belongsTo(ComprobanteVenta::class, 'id_comprobante_venta');
    }
}
