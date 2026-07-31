<?php

namespace App\Models;

use App\Shared\Enums\ValorizacionCompra\EstadoTransaccionAnticipo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransaccionAnticipoProveedor extends Model
{
    protected $table = 'transaccion_anticipo_proveedor';

    public $timestamps = false;

    protected $fillable = [
        'id_anticipo_proveedor',
        'id_valorizacion_compra',
        'monto_retirado',
        'saldo_actual',
        'log_cambios',
        'created_at',
        'estado',
    ];

    protected $casts = [
        'id_anticipo_proveedor' => 'integer',
        'id_valorizacion_compra' => 'integer',
        'monto_retirado' => 'float',
        'saldo_actual' => 'float',
        'log_cambios' => 'array',
        'created_at' => 'datetime',
        'estado' => EstadoTransaccionAnticipo::class,
    ];

    public function anticipo(): BelongsTo
    {
        return $this->belongsTo(AnticipoProveedor::class, 'id_anticipo_proveedor');
    }

    public function valorizacion(): BelongsTo
    {
        return $this->belongsTo(ValorizacionCompra::class, 'id_valorizacion_compra');
    }
}
