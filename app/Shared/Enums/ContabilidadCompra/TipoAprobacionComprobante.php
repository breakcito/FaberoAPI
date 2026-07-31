<?php

namespace App\Shared\Enums\ContabilidadCompra;

enum TipoAprobacionComprobante: string
{
    case Contabilidad = 'Contabilidad';
    case Comercial = 'Comercial';
    case Documentaria = 'Documentaria';
}
