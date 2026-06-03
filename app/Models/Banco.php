<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Banco extends Model
{
    protected $table = 'banco';

    public $timestamps = false;

    protected $fillable = [
        'nombre',
        'abreviatura',
        'es_nacional', // no lo registra ni cambia el usuario, se gestiona a nivel interno
        'estado', // Estado Basico
    ];
}
