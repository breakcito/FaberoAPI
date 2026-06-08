<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Concesion extends Model
{
    protected $table = 'concesion';

    public $timestamps = false;

    protected $fillable = [
        'id_departamento',
        'id_provincia',
        'id_distrito',
        'nombre',
        'codigo_reinfo',
        'estado',
    ];
}
