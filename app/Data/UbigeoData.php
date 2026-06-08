<?php

namespace App\Data;

use Illuminate\Support\Facades\DB;

class UbigeoData
{
    /**
     * Obtener listado de todos los departamentos
     */
    public static function get_departamentos(): array
    {
        $sql = 'SELECT id, nombre, codigo FROM departamento ORDER BY nombre ASC';

        return DB::select($sql);
    }

    /**
     * Obtener provincias asociadas a un departamento
     */
    public static function get_provincias(int $id_departamento): array
    {
        $sql = 'SELECT id, id_departamento, nombre, codigo 
                FROM provincia 
                WHERE id_departamento = :id_departamento 
                ORDER BY nombre ASC';

        return DB::select($sql, ['id_departamento' => $id_departamento]);
    }

    /**
     * Obtener distritos asociados a una provincia
     */
    public static function get_distritos(int $id_provincia): array
    {
        $sql = 'SELECT id, id_provincia, nombre, codigo 
                FROM distrito 
                WHERE id_provincia = :id_provincia 
                ORDER BY nombre ASC';

        return DB::select($sql, ['id_provincia' => $id_provincia]);
    }
}
