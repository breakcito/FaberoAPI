<?php

namespace App\Data;

use Illuminate\Support\Facades\DB;

class MotivoIngresoData
{
    public static function get_motivos_ingreso(): array
    {
        $sql = '
        SELECT
            mi.id AS id_motivo_ingreso,
            mi.nombre
        FROM
            motivo_ingreso mi
        ORDER BY mi.nombre ASC;
        ';

        return DB::select($sql);
    }
}
