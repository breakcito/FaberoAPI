<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DistribucionDetalle extends Model
{
    protected $table = 'distribucion_detalle';

    public $timestamps = false;

    protected $fillable = [
        'id_distribucion',
        'id_despacho_detalle',
        'numero_particion',
        'peso_tomado',
        'peso_tara',
        'fecha_hora_peso_tara',
        'peso_bruto',
        'fecha_hora_peso_bruto',
        'peso_neto',
        'id_ticket_balanza',
        'peso_neto_cliente',
        'codigo_cliente',
        'ley_oro_cliente',
        'ley_plata_cliente',
        'ley_humedad_cliente',
        'esta_valorizado_oro',
        'esta_valorizado_plata',
    ];

    protected $casts = [
        'id_distribucion' => 'integer',
        'id_despacho_detalle' => 'integer',
        'numero_particion' => 'integer',
        'peso_tomado' => 'float',
        'peso_tara' => 'float',
        'peso_bruto' => 'float',
        'peso_neto' => 'float',
        'peso_neto_cliente' => 'float',
        'codigo_cliente' => 'string',
        'ley_oro_cliente' => 'float',
        'ley_plata_cliente' => 'float',
        'ley_humedad_cliente' => 'float',
        'esta_valorizado_oro' => 'boolean',
        'esta_valorizado_plata' => 'boolean',
    ];
}
