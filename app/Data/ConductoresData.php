<?php

namespace App\Data;

use App\Models\Conductor;
use App\Shared\Enums\_Generic\EstadoBase;
use Illuminate\Support\Facades\DB;

class ConductoresData
{
    public static function get_conductores(?int $id_conductor = null)
    {
        $sql = '
        SELECT
            c.id as id_conductor,
            c.dni,
            c.numero_licencia,
            TRIM(CONCAT_WS(" ", NULLIF(TRIM(c.nombre), ""), NULLIF(TRIM(c.apellido), ""))) AS nombre_completo
        FROM
            conductor c
        WHERE 1 = 1
        ';

        $params = [];
        if ($id_conductor !== null) {
            $sql .= ' AND c.id = :id_conductor';
            $params['id_conductor'] = $id_conductor;

            return (array) DB::selectOne($sql, $params);
        }

        $sql .= ' AND c.estado = \'Activo\'';

        $sql .= ' ORDER BY nombre_completo ASC;';

        return DB::select($sql, $params);
    }

    public static function crear_conductor(
        string $dni,
        string $nombre,
        string $apellido,
        string $numeroLicencia,
    ): int {
        return Conductor::insertGetId([
            'dni' => $dni,
            'nombre' => $nombre,
            'apellido' => $apellido,
            'numero_licencia' => $numeroLicencia,
            'estado' => EstadoBase::Activo->value,
        ]);
    }

    /**
     * Obtiene información dinámica de uno o varios conductores.
     * Permite especificar las columnas exactas a consultar mediante un array.
     *
     * @param  array  $columnas  Array de strings con los nombres de las columnas a recuperar.
     * @return array|null Retorna un array con los resultados o null si no se encuentra el registro.
     */
    public static function get_conductores_by_id(int|array $id_conductor, array $columnas): ?array
    {
        $esArray = is_array($id_conductor);
        $ids = $esArray ? $id_conductor : [$id_conductor];
        // Forzamos la inclusión del ID con su alias
        if (! in_array('id as id_conductor', $columnas)) {
            $columnas[] = 'id as id_conductor';
        }
        $query = Conductor::whereIn('id', $ids)->get($columnas);
        if ($esArray) {
            return $query->toArray();
        }

        return $query->first()?->toArray();
    }

    // Metodo para validar si ya existe un conductor por dni, nombre + apellido, o numero de licencia
    public static function ya_existe(
        string $dni,
        string $nombre,
        string $apellido,
        string $numeroLicencia
    ): bool {
        return Conductor::where('dni', $dni)
            ->orWhere(function ($query) use ($nombre, $apellido) {
                $query->where('nombre', $nombre)->where('apellido', $apellido);
            })
            ->orWhere('numero_licencia', $numeroLicencia)
            ->exists();
    }
}
