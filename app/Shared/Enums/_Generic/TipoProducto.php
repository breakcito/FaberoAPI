<?php

namespace App\Shared\Enums\_Generic;

/**
 * Tipo de producto del lote.
 *
 * Los valores coinciden con los que ya existen en `lote_mineral.tipo_producto`.
 */
enum TipoProducto: string
{
    case Aurifero = 'Aurífero';
    case Polimetalico = 'Polimetálico';
}
