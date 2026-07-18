<?php

namespace App\Data;

use Illuminate\Support\Facades\DB;

class EmpresasTransporteData
{
    public static function get_empresas_transporte(?int $id = null)
    {
        $sql = '
        SELECT
            et.id AS id_empresa_transporte,
            et.ruc,
            et.razon_social,
            et.estado
        FROM
            empresa_transporte et
            
        WHERE 1 = 1
        ';

        $params = [];
        if ($id !== null) {
            $sql .= ' AND et.id = :id';
            $params['id'] = $id;

            return (array) DB::selectOne($sql, $params);
        }

        $sql .= ' ORDER BY et.razon_social ASC;';

        return DB::select($sql, $params);
    }
}
