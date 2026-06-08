<?php

namespace App\Modules\Marcas\Data;

use App\Models\Marca;
use App\Shared\Enums\_Generic\EstadoBase;
use Illuminate\Support\Facades\DB;

class MarcasData
{
    public static function get_marcas(?int $id = null)
    {
        $sql = '
        SELECT
            m.id,
            m.nombre,
            m.estado
        FROM
            marca m
        WHERE 1 = 1
        ';

        $params = [];
        if ($id !== null) {
            $sql .= ' AND m.id = :id';
            $params['id'] = $id;

            return (array) DB::selectOne($sql, $params);
        }

        $sql .= ' ORDER BY m.nombre ASC;';

        return DB::select($sql, $params);
    }

    public static function get_marca_by_id(int $id): array
    {
        return self::get_marcas(id: $id);
    }

    public static function crear_marca(string $nombre): int
    {
        return Marca::insertGetId([
            'nombre' => $nombre,
            'estado' => EstadoBase::Activo->value,
        ]);
    }

    public static function editar_marca(int $id, string $nombre): bool
    {
        return Marca::where('id', $id)->update([
            'nombre' => $nombre,
        ]) >= 0;
    }

    public static function cambiar_estado_marca(int $id, string $estado): bool
    {
        return Marca::where('id', $id)->update([
            'estado' => $estado,
        ]) >= 0;
    }
}
