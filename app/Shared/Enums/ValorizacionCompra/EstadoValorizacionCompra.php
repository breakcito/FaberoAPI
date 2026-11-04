<?php

namespace App\Shared\Enums\ValorizacionCompra;

enum EstadoValorizacionCompra: string
{
    case Propuesta = 'Propuesta';
    case Aprobado = 'Aprobado';
    case Desestimada = 'Desestimada';
    case Anulado = 'Anulado';
}
