<?php

namespace App\Shared\Enums\_Generic;

/**
 * Periodos para fechas
 */
enum Periodo: string
{
    case Diario = 'diario';
    case Semanal = 'semanal';
    case Mensual = 'mensual';
    case Anual = 'anual';
    case Ninguno = 'ninguno';
}
