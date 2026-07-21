<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CondicionComercialProveedor extends Model
{
    protected $table = 'condicion_comercial_proveedor';

    public $timestamps = false;

    protected $fillable = [
        'id_proveedor_minero',
        'ley_auoz_inicio',
        'ley_auoz_fin',
        'maquila',
        'recuperacion',
        'consumo',
        'riesgo_comercial',
        'estado',
        'created_at',
    ];
}
