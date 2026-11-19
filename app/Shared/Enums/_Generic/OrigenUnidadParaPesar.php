<?php

namespace App\Shared\Enums\_Generic;

/**
 * Origen del cambio que deja una unidad disponible para el pesaje.
 *
 * Cada caso corresponde a un proceso distinto (ingreso directo de una
 * unidad, confirmación de una programación de recepción y confirmación de
 * una programación de despacho), por lo que no se recicla ningún enum
 * existente.
 */
enum OrigenUnidadParaPesar: string
{
    case Recepcion = 'recepcion';
    case ProgramacionRecepcion = 'programacion_recepcion';
    case ProgramacionDespacho = 'programacion_despacho';
}
