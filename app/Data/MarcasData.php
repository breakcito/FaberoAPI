<?php

namespace App\Data;

use App\Models\Marca;
use App\Models\PersonalExterno;
use App\Shared\Enums\_Generic\EstadoBase;
use Illuminate\Support\Facades\DB;

class MarcasData
{

    /**
     * Crear una marca
     */
    public static function crear_marca(
        ?string $nombre = null,
    ) {
        return Marca::insertGetId([
            'nombre' => $nombre,
            'estado' => EstadoBase::Activo->value,
        ]);
    }

    /**
     * Verificar si existe una marca con el mismo nombre
     */
    public static function existe_marca(string $nombre): bool
    {
        return Marca::where('nombre', $nombre)->exists();
    }

    /**
     * Listar marcas
     */
    public static function get_marcas(
        ?int $id_marca = null,
        ?EstadoBase $estado = EstadoBase::Activo
    ) {
        $sql = '
        SELECT
            mr.id AS id_marca,
            mr.nombre
        FROM
            marca mr
        WHERE 1=1
        ';

        $params = [];

        if ($id_marca) {
            $sql .= ' AND mr.id = :id_marca';
            $params['id_marca'] = $id_marca;
            return DB::selectOne($sql, $params);
        }

        if ($estado != null) {
            $sql .= ' AND mr.estado = :estado';
            $params['estado'] = $estado->value;
        }

        return DB::select($sql, $params);
    }
}
