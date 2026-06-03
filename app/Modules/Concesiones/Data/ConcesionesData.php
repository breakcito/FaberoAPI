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
            cn.nombre,
            cn.codigo_concesion,
            cn.codigo_reinfo,
            cn.ubigeo,
            cn.tipo_mineral,
            cn.estado,
            (
                SELECT 
                    COUNT(*) 
                FROM contrato_concesion cc 
                WHERE 
                    cc.id_concesion = cn.id AND 
                    cc.estado = "Activo"
            ) as contratos_activos
        FROM
            concesion cn
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
        string $nombre,
        string $codigo_concesion,
        ?string $codigo_reinfo,
        ?string $ubigeo,
        string $tipo_mineral
    ): int {
        return Concesion::insertGetId([
            'nombre' => $nombre,
            'codigo_concesion' => $codigo_concesion,
            'codigo_reinfo' => $codigo_reinfo,
            'ubigeo' => $ubigeo,
            'tipo_mineral' => $tipo_mineral,
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
}
