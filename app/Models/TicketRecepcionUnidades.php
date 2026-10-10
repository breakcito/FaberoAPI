<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Modelo que representa un ticket de ingreso de vehículos con carga para recepción de unidades.
 */
class TicketRecepcionUnidades extends Model
{
    protected $table = 'ticket_recepcion_unidades';

    public $timestamps = false;

    protected $fillable = [
        'correlativo',
        'numero_correlativo',
        'created_at',
    ];
}
