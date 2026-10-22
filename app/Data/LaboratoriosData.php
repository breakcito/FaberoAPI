<?php

namespace App\Data;

use App\Models\Laboratorio;
use Illuminate\Support\Facades\DB;

class LaboratoriosData
{
    /**
     * Crear un laboratorio
     */
    public static function crear_laboratorio(
        int $id_empleado_registro,
        string $nombre,
        ?string $ruc = null
    ): int {
        return (int) Laboratorio::insertGetId([
            'id_empleado_registro' => $id_empleado_registro,
            'nombre' => $nombre,
            'ruc' => $ruc,
            'created_at' => now(),
        ]);
    }

    /**
     * Verificar si existe un laboratorio con el mismo nombre
     */
    public static function existe_laboratorio(string $nombre): bool
    {
        return Laboratorio::where('nombre', $nombre)->exists();
    }

    /**
     * Listar laboratorios
     */
    public static function get_laboratorios(?int $id = null): array
    {
        $sql = '
            SELECT
                l.id,
                l.id_empleado_registro,
                l.nombre,
                l.ruc,
                l.created_at
            FROM laboratorio l
            WHERE 1=1
        ';

        $params = [];

        if ($id !== null) {
            $sql .= ' AND l.id = :id';
            $params['id'] = $id;

            $row = DB::selectOne($sql, $params);
            return $row ? [$row] : [];
        }

        $sql .= ' ORDER BY l.nombre ASC';

        return DB::select($sql, $params);
    }
}
