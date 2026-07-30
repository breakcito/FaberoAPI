<?php

namespace App\Shared\Enums\ContabilidadCompra;

enum MedioPagoComprobante: string
{
    case Transferencia = 'Transferencia';
    case Deposito = 'Depósito';
    case Efectivo = 'Efectivo';
}
