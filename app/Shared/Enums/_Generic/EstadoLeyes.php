<?php

namespace App\Shared\Enums\_Generic;

enum EstadoLeyes: string
{
    case Pendiente = 'Pendiente';
    case EnProceso = 'En Proceso';
    case Confirmado = 'Confirmado';
}
