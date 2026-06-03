<?php

namespace App\Data;

use App\Shared\Enums\_Generic\EstadoBase;
use Illuminate\Support\Facades\DB;

class EmpleadosData
{
    /**
     * Obtener listado simple de empleados
     */
    public static function get_empleados(
        ?int $id_empleado = null,
        ?EstadoBase $estado = EstadoBase::Activo,
    ): array {
        $sql = '
        SELECT
            emp.id AS id_empleado,
            CONCAT(emp.nombre, " ", emp.apellido) AS nombre_completo,
            emp.dni,
            emp.ruc,
            emp.path_foto
        FROM
            empleado emp
        WHERE
            emp.estado = :estado
        ';

        $params = [];
        $params['estado'] = $estado->value;

        if ($id_empleado !== null) {
            $sql .= ' AND emp.id = :id_empleado';
            $params['id_empleado'] = $id_empleado;
        }

        $sql .= ' ORDER BY nombre_completo ASC';

        return DB::select($sql, $params);
    }
}
