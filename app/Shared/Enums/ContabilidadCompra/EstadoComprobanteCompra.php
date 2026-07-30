<?php

namespace App\Shared\Enums\ContabilidadCompra;

enum EstadoComprobanteCompra: string
{
    case EnEspera = 'En Espera';
    case EnProceso = 'En Proceso';
    case Pagado = 'Pagado';
    case Anulado = 'Anulado';
}
