<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Empleado extends Model
{
    protected $table = 'empleado';

    public $timestamps = false;

    protected $fillable = [
        'id_cargo',
        'id_empresa',
        'id_mina',
        'nombre',
        'apellido',
        'dni',
        'ruc',
        'carnet_extranjeria',
        'pasaporte',
        'fecha_nacimiento',
        'path_foto',
        'es_contratista',
        'estado',
    ];

    protected $casts = [
        'es_contratista' => 'boolean',
    ];
}
