<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CuentaBancariaPlantaDestino extends Model
{
    protected $table = 'cuenta_bancaria_planta_destino';

    public $timestamps = false;

    protected $fillable = [
        'id_planta_destino',
        'id_banco',
        'moneda',
        'numero_cuenta',
        'cci',
        'es_para_detraccion',
        'estado',
    ];
}
