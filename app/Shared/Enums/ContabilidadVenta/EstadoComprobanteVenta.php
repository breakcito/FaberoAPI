<?php

namespace App\Shared\Enums\ContabilidadVenta;

enum EstadoComprobanteVenta: string
{
    case EnEspera = 'En Espera';
    case EnProceso = 'En Proceso';
    case Pagado = 'Pagado';
    case Anulado = 'Anulado';
}
