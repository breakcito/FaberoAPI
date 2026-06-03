<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Submenu extends Model
{
    protected $table = 'submenu';
    public $timestamps = false;
    protected $fillable = [
        'id_menu',
        'nombre',
        'path',
        'numero_orden', // va de 10 en 10, y ayuda a order los registro segun su flujo/importancia
        'estado',
    ];
}
