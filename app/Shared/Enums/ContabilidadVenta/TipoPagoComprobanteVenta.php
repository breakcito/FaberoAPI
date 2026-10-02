<?php

namespace App\Shared\Enums\ContabilidadVenta;

enum TipoPagoComprobanteVenta: string
{
    case Transferencia = 'Transferencia';
    case Anticipo = 'Anticipo';
    case Mixto = 'Mixto';
}
