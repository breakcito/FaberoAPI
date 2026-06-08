<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConcesionProveedor extends Model
{
    protected $table = 'concesion_proveedor';

    public $timestamps = false;

    protected $fillable = [
        'id_proveedor',
        'id_concesion',
    ];
}
