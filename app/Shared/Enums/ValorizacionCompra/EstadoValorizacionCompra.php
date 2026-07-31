<?php

namespace App\Shared\Enums\ValorizacionCompra;

enum EstadoValorizacionCompra: string
{
    case Pendiente = 'Pendiente';
    case Aprobado = 'Aprobado';
    case Anulado = 'Anulado';
}
