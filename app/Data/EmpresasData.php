<?php

namespace App\Data;

use App\Shared\Enums\_Generic\EstadoBase;
use Illuminate\Support\Facades\DB;

class EmpresasData
{
    /**
     * Obtener listado simple de empresas
     */
    public static function get_empresas(
        ?int $id_empresa = null,
        ?EstadoBase $estado = EstadoBase::Activo,
    ): array {
        $sql = '
        SELECT
            emp.id AS id_empresa,
            emp.ruc,
            emp.razon_social,
            emp.path_logo,
            emp.id_departamento,
            d.nombre AS departamento_nombre,
            emp.id_provincia,
            p.nombre AS provincia_nombre,
            emp.id_distrito,
            di.nombre AS distrito_nombre,
            emp.domicilio_fiscal,
            (
                SELECT
                    COUNT(*)
                FROM
                    cuenta_bancaria_empresa cb
                WHERE
                    cb.id_empresa = emp.id AND
                    cb.estado = "Activo"
            ) as cantidad_cuentas_bancarias
        FROM
            empresa emp
        LEFT JOIN departamento d ON d.id = emp.id_departamento
        LEFT JOIN provincia p ON p.id = emp.id_provincia
        LEFT JOIN distrito di ON di.id = emp.id_distrito
        WHERE 1=1
        ';

        $params = [];

        if ($id_empresa !== null) {
            $sql .= ' AND emp.id = :id_empresa';
            $params['id_empresa'] = $id_empresa;

            $row = DB::selectOne($sql, $params);

            return $row ? [$row] : [];
        }

        // Nota: la tabla `empresa` actualmente NO tiene columna `estado`
        // (legacy anterior a la regla del README sobre borrado lógico).
        // El parámetro $estado del Service queda como no-op hasta que se
        // agregue la columna vía ALTER TABLE y se popule en Empresa::insertGetId().
        // Si en el futuro se agrega, reactivar este bloque aquí.

        $sql .= ' ORDER BY razon_social ASC';

        return DB::select($sql, $params);
    }
}
