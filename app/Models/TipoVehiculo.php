<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Modelo que representa los tipos de vehículo.
 */
class TipoVehiculo extends Model
{
    protected $table = 'tipo_vehiculo';

    public $timestamps = false;

    protected $fillable = [
        'nombre',
        'tiene_carreta',
        'es_carreta',
        'estado',
    ];

    protected $casts = [
        'tiene_carreta' => 'boolean',
        'es_carreta' => 'boolean',
    ];
}
