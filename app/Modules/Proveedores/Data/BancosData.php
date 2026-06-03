<?php

namespace App\Modules\Proveedores\Data;

use App\Models\Banco;
use App\Shared\Enums\_Generic\EstadoBase;
use Illuminate\Support\Facades\DB;

class BancosData
{
    public static function get_bancos(?int $id_banco = null): array
    {
        $sql = '
        SELECT
            bc.id AS id_banco,
            bc.nombre,
            bc.abreviatura,
            bc.es_nacional
        FROM
            banco bc
        WHERE 1 = 1
        ';

        $params = [];
        if ($id_banco !== null) {
            $sql .= ' AND bc.id = :id_banco';
            $params['id_banco'] = $id_banco;
            return (array) DB::selectOne($sql, $params);
        }

        $sql .= ' ORDER BY bc.nombre ASC, bc.abreviatura ASC;';
        return DB::select($sql, $params);
    }

    public static function get_banco_by_id(int $id_banco): array
    {
        return self::get_bancos(id_banco: $id_banco);
    }

    public static function crear_banco(string $nombre, string $abreviatura): int
    {
        return Banco::insertGetId([
            'nombre' => $nombre,
            'abreviatura' => $abreviatura,
            'estado' => EstadoBase::Activo->value
        ]);
    }
}
