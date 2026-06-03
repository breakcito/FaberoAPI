<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// esta tabla registrara todos los accesos por vista
// como por ejemplo, tener un acceso para poder ver todos los 
// almacenes en la vista de Lotes, en vez de solo visualizar aquellos donde
// el empleado es responsable
class Acceso extends Model
{
    protected $table = 'acceso';

    public $timestamps = false;

    protected $fillable = [
        'id_modulo',
        'nombre',
        'descripcion',
    ];
}
