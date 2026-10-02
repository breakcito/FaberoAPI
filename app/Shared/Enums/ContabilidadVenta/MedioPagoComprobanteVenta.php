<?php

namespace App\Shared\Enums\ContabilidadVenta;

enum MedioPagoComprobanteVenta: string
{
    case Transferencia = 'Transferencia';
    case Deposito = 'Depósito';
    case Efectivo = 'Efectivo';
}
