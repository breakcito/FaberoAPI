<?php

namespace App\Data;

use Illuminate\Support\Facades\DB;

class ZonasOrigenData
{
    /**
     * Obtener listado de zonas de origen activas
     */
    public static function get_zonas_origen()
    {
        return DB::select('
            SELECT 
                id, 
                nombre, 
                created_at 
            FROM zona_origen 
            ORDER BY nombre ASC
        ');
    }
}
