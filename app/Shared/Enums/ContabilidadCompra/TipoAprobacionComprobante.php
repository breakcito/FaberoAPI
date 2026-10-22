<?php

namespace App\Shared\Enums\ContabilidadCompra;

enum TipoAprobacionComprobante: string
{
    case Comercial = 'Comercial';
    case Contabilidad = 'Contabilidad';
    case Gerencia = 'Gerencia';
    case Documentaria = 'Documentaria'; // Retrocompatibilidad
}
