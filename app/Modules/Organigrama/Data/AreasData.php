<?php

namespace App\Modules\Organigrama\Data;

use App\Models\Area;
use App\Shared\Enums\_Generic\EstadoBase;
use Illuminate\Support\Facades\DB;

class AreasData
{
    /**
     * Listar o obtener un área
     */
    public static function get_areas(?int $id_area = null): array|object
    {
        $sql = '
        SELECT
            a.id AS id_area,
            a.nombre,
            a.estado,
            (SELECT COUNT(*) FROM cargo c WHERE c.id_area = a.id AND c.estado = :estado_cargo) AS cantidad_cargos,
            (SELECT GROUP_CONCAT(c.nombre SEPARATOR ", ") FROM cargo c WHERE c.id_area = a.id AND c.estado = :estado_cargo2) AS nombres_cargos
        FROM
            area a
        WHERE
            1 = 1
        ';

        $params = [];
        $params['estado_cargo'] = EstadoBase::Activo->value;
        $params['estado_cargo2'] = EstadoBase::Activo->value;

        if ($id_area !== null) {
            $sql .= ' AND a.id = :id_area';
            $params['id_area'] = $id_area;

            return DB::selectOne($sql, $params) ?? (object) [];
        }

        $sql .= ' AND a.estado = :estado ORDER BY a.nombre ASC';
        $params['estado'] = EstadoBase::Activo->value;

        return DB::select($sql, $params);
    }

    /**
     * Obtener área por ID
     */
    public static function get_area_by_id(int $id_area): array|object
    {
        return self::get_areas(id_area: $id_area);
    }

    /**
     * Crear área
     */
    public static function crear_area(string $nombre): int
    {
        return Area::insertGetId([
            'nombre' => $nombre,
            'estado' => EstadoBase::Activo->value,
        ]);
    }

    /**
     * Verificar duplicado
     */
    public static function verificar_nombre_duplicado(string $nombre): bool
    {
        return Area::where('nombre', $nombre)->exists();
    }
}
