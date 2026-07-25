<?php

namespace App\Shared\Enums\ValorizacionCompra;

enum EstadoTransaccionAnticipo: string
{
    case Pendiente = 'Pendiente';
    case Aprobado = 'Aprobado';
    case Anulado = 'Anulado';
}
