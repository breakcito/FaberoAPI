<?php

namespace App\Shared\Enums\ValorizacionCompra;

enum TipoPagoValorizacionCompra: string
{
    case Transferencia = 'transferencia';
    case Anticipo = 'anticipo';
    case Mixto = 'mixto';
}
