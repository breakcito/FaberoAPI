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
        'id_motivo_ingreso',
        'fecha_hora_ingreso',
        'observacion',
        'con_vehiculo',
        'id_empleado_autoriza',
        'id_recepcion_unidad',
        'fecha_hora_salida',
        'observacion_salida',
        'evidencias_ingreso',
        'evidencias_salida',
        'estado',
    ];

    protected $casts = [
        'con_vehiculo' => 'boolean',
    ];
}
