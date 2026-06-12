<?php

namespace App\Data;

use App\Models\Visitante;
use Illuminate\Support\Facades\DB;

class VisitanteData
{
    public static function buscar_por_dni(string $dni): ?array
    {
        $sql = '
        SELECT
            v.id AS id_visitante,
            v.nombre,
            v.apellido,
            v.dni,
            v.telefono
        FROM
            visitante v
        WHERE
            v.dni = :dni
        LIMIT 1;
        ';

        $res = DB::selectOne($sql, ['dni' => $dni]);

        return $res ? (array) $res : null;
    }

    public static function crear_visitante(array $data): int
    {
        return Visitante::insertGetId([
            'nombre' => $data['nombre'],
            'apellido' => $data['apellido'],
            'dni' => $data['dni'],
            'telefono' => $data['telefono'] ?? null,
        ]);
    }

    public static function get_visitante_by_id(int $id): ?array
    {
        $sql = '
        SELECT
            v.id AS id_visitante,
            v.nombre,
            v.apellido,
            v.dni,
            v.telefono
        FROM
            visitante v
        WHERE
            v.id = :id
        LIMIT 1;
        ';

        $res = DB::selectOne($sql, ['id' => $id]);

        return $res ? (array) $res : null;
    }
}
