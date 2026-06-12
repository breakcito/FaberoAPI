<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Modelo que representa a un visitante.
 */
class Visitante extends Model
{
    protected $table = 'visitante';

    public $timestamps = false;

    protected $fillable = [
        'nombre',
        'apellido',
        'dni',
        'telefono',
    ];
}
