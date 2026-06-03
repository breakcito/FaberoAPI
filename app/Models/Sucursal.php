<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Sucursal extends Model
{
    protected $table = 'sucursal';

    public $timestamps = false;

    protected $fillable = [
        'id_departamento',
        'id_provincia',
        'id_distrito',
        //
        'nombre',
        'direccion',
        'telefono',
        //
        'estado' // EstadoBase
    ];
}
