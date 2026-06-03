<?php

namespace App\Modules\Sucursales\Data;

use App\Models\Sucursal;
use App\Shared\Enums\_Generic\EstadoBase;
use Illuminate\Support\Facades\DB;

class SucursalesData
{
    public static function get_sucursales(?int $id_sucursal = null)
    {
        $sql = '
        SELECT
            sc.id AS id_sucursal,
            sc.nombre,
            sc.direccion,
            
            -- Datos su ubicacion
            sc.id_departamento,
            dep.nombre as departamento,
            - 
            sc.id_provincia,
            prv.nombre as provincia,
            - 
            sc.id_distrito,
            dist.nombre as distrito,
            - 
            sc.direccion,
            
            sc.telefono,
            sc.estado
        FROM
            sucursal sc
        LEFT JOIN departamento dep on dep.id = sc.id_departamento
        LEFT JOIN provincia prv on prv.id = sc.id_provincia
        LEFT JOIN distrito dist on dist.id = sc.id_distrito
        WHERE 1=1
        ';

        $params = [];
        if ($id_sucursal) {
            $sql .= ' AND sc.id = :id_sucursal';
            $params['id_sucursal'] = $id_sucursal;
            return DB::selectOne($sql, $params);
        }

        $sql .= ' ORDER BY sc.nombre ASC;';
        return DB::select($sql, $params);
    }


    public static function crear_sucursal(
        string $nombre,
        //
        ?int $id_departamento = null,
        ?int $id_provincia = null,
        ?int $id_distrito = null,
        ?string $direccion = null,
        ?string $telefono = null
    ): int {
        return Sucursal::insertGetId([
            'id_departamento' => $id_departamento,
            'id_provincia' => $id_provincia,
            'id_distrito' => $id_distrito,
            //
            'nombre' => $nombre,
            'direccion' => $direccion,
            'telefono' => $telefono,
            'estado' => EstadoBase::Activo->value
        ]);
    }

    public static function existe_sucursal(string $nombre)
    {
        return Sucursal::where('nombre', $nombre)->exists();
    }
}
