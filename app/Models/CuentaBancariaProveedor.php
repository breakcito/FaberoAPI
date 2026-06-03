<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CuentaBancariaProveedor extends Model
{
    protected $table = 'cuenta_bancaria_proveedor';

    public $timestamps = false;

    protected $fillable = [
        'id_proveedor',
        'id_banco',
        'moneda', // Soles / Dolares
        'numero_cuenta',
        'cci',
        'es_para_detraccion', // Disponible solo para el banco de la nacion
        'estado', // Estado Basico
    ];
}
