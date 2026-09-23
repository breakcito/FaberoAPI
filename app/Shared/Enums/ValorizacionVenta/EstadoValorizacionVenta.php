<?php

namespace App\Shared\Enums\ValorizacionVenta;

enum EstadoValorizacionVenta: string
{
    case Pendiente = 'Pendiente';
    case Aprobado = 'Aprobado';
    case Anulado = 'Anulado';
}
