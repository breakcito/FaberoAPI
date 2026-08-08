<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Modelo que representa el motivo de ingreso.
 */
class MotivoIngreso extends Model
{
    protected $table = 'motivo_ingreso';

    public $timestamps = false;

    protected $fillable = [
        'nombre',
        'es_recepcion_unidad',
    ];
}
