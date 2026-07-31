<?php

namespace App\Shared\Enums\_Generic;

enum EstadoAnticipoProveedor: string
{
    case ConSaldo = 'Con Saldo';
    case SinSaldo = 'Sin Saldo';
    case Anulado = 'Anulado';
}
