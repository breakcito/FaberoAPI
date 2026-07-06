<?php

namespace App\Data;

use App\Models\Concesion;
use App\Shared\Enums\_Generic\EstadoBase;
use Illuminate\Support\Facades\DB;

class ConcesionesData
{
    /**
     * @return array<int, object>
     */
    public static function get_concesiones(?int $id = null): array
    {
        $sql = '
        SELECT
            c.id AS id_concesion,
            c.id_departamento,
            dep.nombre AS departamento,
            c.id_provincia,
            prov.nombre AS provincia,
            c.id_distrito,
            dist.nombre AS distrito,
            c.nombre,
            c.codigo_reinfo,
            c.estado,
            (
                SELECT
                    COUNT(*)
                FROM
                    concesion_proveedor cp
                WHERE
                    cp.id_concesion = c.id
            ) AS contratos_activos
        FROM
            concesion c
        LEFT JOIN departamento dep ON dep.id = c.id_departamento
        LEFT JOIN provincia prov ON prov.id = c.id_provincia
        LEFT JOIN distrito dist ON dist.id = c.id_distrito
        WHERE 1 = 1
        ';

        $params = [];
        if ($id !== null) {
            $sql .= ' AND c.id = :id';
            $params['id'] = $id;

            $row = DB::selectOne($sql, $params);

            return $row ? [(array) $row] : [];
        }

        $sql .= ' ORDER BY c.nombre ASC;';

        return DB::select($sql, $params);
    }

    /**
     * Obtener concesiones asociadas a un proveedor específico a través de concesion_proveedor.
     *
     * @return array<int, object>
     */
    public static function get_concesiones_by_proveedor(int $idProveedor): array
    {
        $sql = '
        SELECT
            c.id AS id_concesion,
            c.id_departamento,
            dep.nombre AS departamento,
            c.id_provincia,
            prov.nombre AS provincia,
            c.id_distrito,
            dist.nombre AS distrito,
            c.nombre,
            c.codigo_reinfo,
            c.estado,
            cp.id AS id_concesion_proveedor
        FROM
            concesion_proveedor cp
        INNER JOIN concesion c ON c.id = cp.id_concesion
        LEFT JOIN departamento dep ON dep.id = c.id_departamento
        LEFT JOIN provincia prov ON prov.id = c.id_provincia
        LEFT JOIN distrito dist ON dist.id = c.id_distrito
        WHERE
            cp.id_proveedor = :id_proveedor
        ORDER BY c.nombre ASC;
        ';

        return DB::select($sql, ['id_proveedor' => $idProveedor]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get_concesion_by_id(int $id): ?array
    {
        $rows = self::get_concesiones(id: $id);

        return $rows[0] ?? null;
    }

    public static function crear_concesion(
        int $idDepartamento,
        int $idProvincia,
        int $idDistrito,
        string $nombre,
        ?string $codigoReinfo
    ): int {
        return Concesion::insertGetId([
            'id_departamento' => $idDepartamento,
            'id_provincia' => $idProvincia,
            'id_distrito' => $idDistrito,
            'nombre' => $nombre,
            'codigo_reinfo' => $codigoReinfo,
            'estado' => EstadoBase::Activo->value,
        ]);
    }

    public static function editar_concesion(
        int $id,
        int $idDepartamento,
        int $idProvincia,
        int $idDistrito,
        string $nombre,
        ?string $codigoReinfo
    ): bool {
        return Concesion::where('id', $id)->update([
            'id_departamento' => $idDepartamento,
            'id_provincia' => $idProvincia,
            'id_distrito' => $idDistrito,
            'nombre' => $nombre,
            'codigo_reinfo' => $codigoReinfo,
        ]) >= 0;
    }

    public static function cambiar_estado_concesion(int $id, string $estado): bool
    {
        return Concesion::where('id', $id)->update([
            'estado' => $estado,
        ]) >= 0;
    }
}
