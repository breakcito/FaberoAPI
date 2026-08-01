<?php

namespace App\Shared\Responses\_Generic;

class RES_CambiosLog
{
    /**
     * Crear una entrada de log de cambios con la estructura normalizada para auditoría ERP.
     *
     * @param  int  $idEmpleado
     * @param  string|null  $motivo
     * @param  array<int, array{campo_bd: string|null, campo: string|null, valor_anterior: mixed, valor_nuevo: mixed}>  $cambios
     * @return array{id_empleado: int, motivo: string|null, update_at: string, cambios: array<int, array{campo_bd: string|null, campo: string|null, valor_anterior: mixed, valor_nuevo: mixed}>}
     */
    public static function crear(int $idEmpleado, ?string $motivo, array $cambios): array
    {
        return [
            'id_empleado' => $idEmpleado,
            'motivo' => $motivo,
            'update_at' => now()->format('Y-m-d H:i:s'),
            'cambios' => $cambios,
        ];
    }
}
