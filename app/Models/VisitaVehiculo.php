<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Modelo que representa un vehículo acompañante en una visita programada.
 * Es un snapshot: NO tiene FK a la tabla `vehiculo`.
 */
class VisitaVehiculo extends Model
{
    protected $table = 'visita_vehiculo';

    public $timestamps = false;

    protected $fillable = [
        'id_recepcion_visita',
        'placa',
        'cantidad_personas',
        'url_foto',
    ];
}
