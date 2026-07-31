<?php

namespace App\Models;

use App\Shared\Enums\_Generic\EstadoBase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TipoCambio extends Model
{
    protected $table = 'tipo_cambio';

    public $timestamps = false;

    protected $fillable = [
        'id_empleado_registro',
        'valor_compra',
        'valor_venta',
        'fecha',
        'created_at',
        'estado',
    ];

    protected $casts = [
        'valor_compra' => 'float',
        'valor_venta' => 'float',
        'fecha' => 'date:Y-m-d',
        'created_at' => 'datetime',
        'estado' => EstadoBase::class,
    ];

    public function empleadoRegistro(): BelongsTo
    {
        return $this->belongsTo(Empleado::class, 'id_empleado_registro');
    }
}
