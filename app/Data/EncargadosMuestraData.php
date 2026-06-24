<?php

namespace App\Data;

use Illuminate\Support\Facades\DB;

class EncargadosMuestraData
{
    /**
     * Obtener listado simplificado de encargados de muestra activos (solo id y nombre completo)
     */
    public static function get_encargados_muestra(): array
    {
        $sql = '
        SELECT
            em.id AS id_encargado_muestra,
            TRIM(CONCAT_WS(" ", NULLIF(TRIM(em.nombre), ""), NULLIF(TRIM(em.apellido), ""))) AS nombre
        FROM
            encargado_muestra em
        WHERE
            em.estado = \'Activo\'
        ORDER BY
            nombre ASC;
        ';

        return DB::select($sql);
    }
}
