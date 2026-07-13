<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Analito extends Model
{
    protected $table = 'analito';

    public $timestamps = false;

    protected $fillable = [
        'nombre',
        'es_desplegable',
        'estado',
    ];
}
