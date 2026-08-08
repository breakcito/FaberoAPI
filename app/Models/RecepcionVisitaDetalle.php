<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Modelo que representa el detalle de recepción de visitas.
 */
class RecepcionVisitaDetalle extends Model
{
    protected $table = 'recepcion_visita_detalle';

    public $timestamps = false;

    protected $fillable = [
        'id_recepcion_visita',
        'id_visitante',
        'url_foto_documento',
        'fecha_hora_salida',
        'observacion_salida',
        'estado',
        'id_visita_vehiculo',
        'es_conductor',
        'evidencias_salida',
    ];
}
