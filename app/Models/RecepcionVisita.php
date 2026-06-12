<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Modelo que representa la recepción de visitas.
 */
class RecepcionVisita extends Model
{
    protected $table = 'recepcion_visita';

    public $timestamps = false;

    protected $fillable = [
        'id_empleado_registro',
        'id_empleado_contacto',
        'id_motivo_ingreso',
        'fecha_hora_ingreso',
        'fecha_hora_salida',
        'observacion',
        'observacion_salida',
        'con_vehiculo',
        'serie_placa',
        'numero_placa',
        'estado',
    ];

    protected $casts = [
        'con_vehiculo' => 'boolean',
    ];
}
