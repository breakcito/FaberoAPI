<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Modelo que representa los conductores de las unidades.
 */
class Conductor extends Model
{
    protected $table = 'conductor';

    public $timestamps = false;

    protected $fillable = [
        'dni',
        'nombre',
        'apellido',
        'numero_licencia',
        'estado',
    ];
}
