<?php

namespace App\Shared\Enums\_Generic;

enum EstadoPesaje: string
{
    case SinPesar = 'Sin Pesar';
    case EnProceso = 'En Proceso';
    case Pesado = 'Pesado';
}