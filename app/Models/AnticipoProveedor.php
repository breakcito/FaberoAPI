<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AnticipoProveedor extends Model
{
    protected $table = 'anticipo_proveedor';

    public $timestamps = false;

    protected $fillable = [
        'id_proveedor_minero',
        'id_empleado_registro',
        'serie_factura',
        'numero_factura',
        'saldo_inicial',
        'saldo_actual',
        'evidencias',
        'log_cambios',
        'estado',
        'created_at',
    ];

    protected $casts = [
        'evidencias' => 'array',
        'log_cambios' => 'array',
        'saldo_inicial' => 'float',
        'saldo_actual' => 'float',
    ];
}
