<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// tabla que permite enlazar un usuario con un acceso por cada vista
// logrando hacer algo como que un usuario tenga el acceso de realizar o no
// una accion por vista
class AccesoUsuario extends Model
{
    protected $table = 'acceso_usuario';

    public $timestamps = false;

    protected $fillable = [
        'id_usuario',
        'id_acceso',
    ];
}
