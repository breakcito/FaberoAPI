<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlantaProveedor extends Model
{
    protected $table = 'planta_proveedor';

    public $timestamps = false;

    protected $fillable = [
        'id_proveedor',
        'id_planta',
    ];
}
