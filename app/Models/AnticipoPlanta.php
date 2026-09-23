<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnticipoPlanta extends Model
{
    protected $table = 'anticipo_planta';

    public $timestamps = false;

    protected $fillable = [
        'id_planta',
        'id_empleado_registro',
        'codigo_comprobante',
        'saldo_inicial',
        'saldo_actual',
        'evidencias',
        'log_cambios',
        'estado',
        'created_at',
    ];

    protected $casts = [
        'id_planta' => 'integer',
        'id_empleado_registro' => 'integer',
        'saldo_inicial' => 'float',
        'saldo_actual' => 'float',
        'evidencias' => 'array',
        'log_cambios' => 'array',
        'created_at' => 'datetime',
    ];

    public function planta(): BelongsTo
    {
        return $this->belongsTo(PlantaDestino::class, 'id_planta');
    }
}
