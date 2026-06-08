<?php

namespace App\Modules\EncargadosMuestra\Data;

use App\Models\EncargadoMuestra;
use App\Shared\Enums\_Generic\EstadoBase;
use Illuminate\Support\Facades\DB;

class EncargadosMuestraData
{
    public static function get_encargados_muestra(?int $id = null)
    {
        $sql = '
        SELECT
            em.id AS id_encargado_muestra,
            em.dni,
            em.ruc,
            em.nombre,
            em.apellido,
            em.estado
        FROM
            encargado_muestra em
        WHERE 1 = 1
        ';

        $params = [];
        if ($id) {
            $sql .= ' AND em.id = :id';
            $params['id'] = $id;

            return DB::selectOne($sql, $params);
        }

        $sql .= ' ORDER BY em.apellido ASC, em.nombre ASC;';

        return DB::select($sql, $params);
    }

    public static function get_encargado_muestra_by_id(int $id)
    {
        return self::get_encargados_muestra(id: $id);
    }

    public static function crear_encargado_muestra(
        ?string $dni,
        ?string $ruc,
        string $nombre,
        string $apellido
    ): int {
        return EncargadoMuestra::insertGetId([
            'dni' => $dni,
            'ruc' => $ruc,
            'nombre' => $nombre,
            'apellido' => $apellido,
            'estado' => EstadoBase::Activo->value,
        ]);
    }

    public static function editar_encargado_muestra(
        int $id,
        ?string $dni,
        ?string $ruc,
        string $nombre,
        string $apellido
    ): bool {
        return EncargadoMuestra::where('id', $id)->update([
            'dni' => $dni,
            'ruc' => $ruc,
            'nombre' => $nombre,
            'apellido' => $apellido,
        ]) >= 0;
    }

    public static function cambiar_estado_encargado_muestra(int $id, string $estado): bool
    {
        return EncargadoMuestra::where('id', $id)->update(['estado' => $estado]) >= 0;
    }
}
