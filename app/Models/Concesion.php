<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Concesion extends Model
{
    protected $table = 'concesion';

    public $timestamps = false;

    protected $fillable = [
        'nombre',
        'codigo_concesion',
        'codigo_reinfo',
        'ubigeo', // coordenadas
        'tipo_mineral', // TipoMineral
        'estado',
    ];
}
