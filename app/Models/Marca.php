<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Modelo que representa las marcas de productos/activos, 
 */
class Marca extends Model
{
    protected $table = 'marca';

    public $timestamps = false;

    protected $fillable = [
        'nombre',
        'estado', // Estado Base
    ];
}
