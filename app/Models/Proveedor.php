<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Proveedor extends Model
{
    protected $table = 'proveedor';

    public $timestamps = false;

    protected $fillable = [
        'tipo_entidad', // Natural / Juridico
        'dni',
        'ruc',
        'razon_social',
        'direccion',
        'telefono',
        'correo',
        'estado', // Estado Basico
    ];
}
