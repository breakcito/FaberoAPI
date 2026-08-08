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
        'id_empleado_recepcion',
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
        'id_sucursal',
        'fecha_hora_inicio_pesaje',
        'fecha_hora_final_pesaje',
        'validacion_datos',
        'estado_pesaje',
        'id_proveedor_minero',
        'id_empleado_autoriza',
        'es_programacion',
        'fecha_estimada_llegada',
        'serie_guia_remitente',
        'numero_guia_remitente',
        'serie_guia_transportista',
        'numero_guia_transportista',
    ];

    protected $casts = [
        'evidencias' => 'array',
        'validacion_datos' => 'array',
    ];
}
