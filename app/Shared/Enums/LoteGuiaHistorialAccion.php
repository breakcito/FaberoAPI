<?php

namespace App\Shared\Enums;

enum LoteGuiaHistorialAccion: string
{
    case LoteCreado = 'LOTE_CREADO';
    case LoteEditado = 'LOTE_EDITADO';
    case LoteEliminado = 'LOTE_ELIMINADO';
}
