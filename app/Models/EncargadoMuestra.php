<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EncargadoMuestra extends Model
{
    protected $table = 'encargado_muestra';

    public $timestamps = false;

    protected $fillable = [
        'dni',
        'ruc',
        'nombre',
        'apellido',
        'estado',
    ];
}
