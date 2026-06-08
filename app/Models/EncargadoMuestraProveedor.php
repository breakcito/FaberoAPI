<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EncargadoMuestraProveedor extends Model
{
    protected $table = 'encargado_muestra_proveedor';

    public $timestamps = false;

    protected $fillable = [
        'id_proveedor',
        'id_encargado_muestra',
    ];
}
