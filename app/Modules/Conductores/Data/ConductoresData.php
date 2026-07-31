<?php

namespace App\Modules\Conductores\Data;

use App\Models\Conductor;
use Illuminate\Support\Facades\DB;

class ConductoresData
{
    public static function get_conductores(?int $id = null)
    {
        $sql = '
        SELECT
            c.id as id_conductor,
            c.dni,
            c.nombre,
            c.apellido,
            c.numero_licencia,
            c.estado
        FROM
            conductor c
        WHERE 1 = 1
        ';

        $params = [];
        if ($id !== null) {
            $sql .= ' AND c.id = :id';
            $params['id'] = $id;

            return (array) DB::selectOne($sql, $params);
        }

        $sql .= ' ORDER BY c.apellido ASC, c.nombre ASC;';

        return DB::select($sql, $params);
    }

    public static function get_conductor_by_id(int $id): array
    {
        return self::get_conductores(id: $id);
    }

    public static function editar_conductor(
        int $id,
        string $dni,
        string $nombre,
        string $apellido,
        string $numeroLicencia
    ): bool {
        return Conductor::where('id', $id)->update([
            'dni' => $dni,
            'nombre' => $nombre,
            'apellido' => $apellido,
            'numero_licencia' => $numeroLicencia,
        ]) >= 0;
    }

    public static function cambiar_estado_conductor(int $id, string $estado): bool
    {
        return Conductor::where('id', $id)->update([
            'estado' => $estado,
        ]) >= 0;
    }
}
