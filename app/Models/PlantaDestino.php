<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlantaDestino extends Model
{
    protected $table = 'planta_destino';

    public $timestamps = false;

    protected $fillable = [
        'ruc',
        'razon_social',
        'direccion',
        'telefono',
        'correo',
        'estado',
    ];
}
