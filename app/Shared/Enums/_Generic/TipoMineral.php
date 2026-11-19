<?php

namespace App\Shared\Enums\_Generic;

/**
 * Tipo de mineral del lote.
 *
 * Los valores coinciden con los que ya existen en `lote_mineral.tipo_mineral`
 * (datos cargados historically desde el formulario de pesaje).
 */
enum TipoMineral: string
{
    case Mixto = 'Mixto';
    case Oxido = 'Óxido';
    case Sulfuro = 'Sulfuro';
}
