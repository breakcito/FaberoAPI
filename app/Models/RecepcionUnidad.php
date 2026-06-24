<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Modelo que representa la recepción de unidades.
 */
class RecepcionUnidad extends Model
{
    protected $table = 'recepcion_unidad';

    public $timestamps = false;

    protected $fillable = [
        'id_empleado_registro',
        'id_vehiculo',
        'id_empresa_transporte',
        'id_tipo_vehiculo',
        'id_conductor',
        'tipo_ingreso',
        'tipo_carga',
        'segunda_placa',
        'fecha_hora_ingreso',
        'evidencias',
        'observacion',
        'estado',
        'estado_salida',
        'fecha_hora_salida',
        'observacion_salida',
        'id_surcusal',
        'fecha_hora_inicio_pesaje',
        'fecha_hora_final_pesaje',
        'validacion_datos',
        'estado_pesaje',
    ];

    protected $casts = [
        'evidencias' => 'array',
        'validacion_datos' => 'array',
    ];
}
