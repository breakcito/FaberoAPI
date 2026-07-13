<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GrupoAnalisis extends Model
{
    protected $table = 'grupo_analisis';

    public $timestamps = false;

    protected $fillable = [
        'nombre',
        'orden',
        'indicar_origen',
        'estado',
    ];
}
