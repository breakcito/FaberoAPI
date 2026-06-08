<?php

namespace App\Modules\Concesiones\Data;

use App\Models\Concesion;
use App\Shared\Enums\_Generic\EstadoBase;
use Illuminate\Support\Facades\DB;

class ConcesionesData
{
    /**
     * Listar concesiones con conteo de contratos activos, filtrado por usuario si se provee
     */
    public static function get_concesiones(?int $id_usuario = null, ?int $id_concesion = null): array|object
    {
        $sql = '
        SELECT DISTINCT
            cn.id AS id_concesion,
            cn.id_departamento,
            dep.nombre AS departamento,
            cn.id_provincia,
            prov.nombre AS provincia,
            cn.id_distrito,
            dist.nombre AS distrito,
            cn.nombre,
            cn.codigo_reinfo,
            cn.estado,
            0 AS contratos_activos
        FROM
            concesion cn
        LEFT JOIN departamento dep ON dep.id = cn.id_departamento
        LEFT JOIN provincia prov ON prov.id = cn.id_provincia
        LEFT JOIN distrito dist ON dist.id = cn.id_distrito
        ';

        $params = [];

        $sql .= ' WHERE 1 = 1 ';

        if ($id_concesion) {
            $sql .= ' AND cn.id = :id_concesion';
            $params['id_concesion'] = $id_concesion;

            return DB::selectOne($sql, $params) ?? (object) [];
        }

        $sql .= ' ORDER BY cn.nombre ASC';

        return DB::select($sql, $params);
    }

    /**
     * Obtener una concesion por id
     */
    public static function get_concesion_by_id(int $id_concesion): array|object
    {
        return self::get_concesiones(id_concesion: $id_concesion);
    }

    /**
     * Crear una nueva concesión con parámetros explícitos
     */
    public static function crear_concesion(
        int $id_departamento,
        int $id_provincia,
        int $id_distrito,
        string $nombre,
        ?string $codigo_reinfo
    ): int {
        return Concesion::insertGetId([
            'id_departamento' => $id_departamento,
            'id_provincia' => $id_provincia,
            'id_distrito' => $id_distrito,
            'nombre' => $nombre,
            'codigo_reinfo' => $codigo_reinfo,
            'estado' => EstadoBase::Activo->value,
        ]);
    }

    /**
     * Verificar si el nombre de la concesión ya existe
     */
    public static function existe_nombre(string $nombre): bool
    {
        return Concesion::where('nombre', $nombre)->exists();
    }

    public static function editar_concesion(
        int $id,
        int $id_departamento,
        int $id_provincia,
        int $id_distrito,
        string $nombre,
        ?string $codigo_reinfo
    ): bool {
        return Concesion::where('id', $id)->update([
            'id_departamento' => $id_departamento,
            'id_provincia' => $id_provincia,
            'id_distrito' => $id_distrito,
            'nombre' => $nombre,
            'codigo_reinfo' => $codigo_reinfo,
        ]) >= 0;
    }

    public static function cambiar_estado_concesion(int $id, string $estado): bool
    {
        return Concesion::where('id', $id)->update(['estado' => $estado]) >= 0;
    }
}
