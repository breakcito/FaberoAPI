<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Modelo que representa los vehículos/unidades de transporte.
 */
class Vehiculo extends Model
{
    protected $table = 'vehiculo';

    public $timestamps = false;

    protected $fillable = [
        'id_marca',
        'id_empresa_transporte',
        'id_tipo_vehiculo',
        'serie_placa',
        'numero_placa',
        'numero_constancia_mtc',
        'capacidad',
        'tara',
        'largo',
        'ancho',
        'alto',
        'estado',
    ];
}
