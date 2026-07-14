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
        int|array|null $id_empleado = null,
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

        $params = [
            'estado' => $estado->value,
        ];

        if ($id_empleado !== null) {
            if (is_array($id_empleado)) {
                // Eliminar duplicados
                $ids = array_values(array_unique($id_empleado));

                if (!empty($ids)) {
                    $placeholders = [];

                    foreach ($ids as $i => $id) {
                        $key = "id_empleado_$i";
                        $placeholders[] = ":$key";
                        $params[$key] = (int) $id;
                    }

                    $sql .= ' AND emp.id IN (' . implode(',', $placeholders) . ')';
                }

            } else {
                $sql .= ' AND emp.id = :id_empleado';
                $params['id_empleado'] = $id_empleado;
            }
        }

        $sql .= ' ORDER BY nombre_completo ASC';

        return DB::select($sql, $params);
    }
}