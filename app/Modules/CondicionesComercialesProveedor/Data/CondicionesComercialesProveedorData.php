<?php

namespace App\Modules\CondicionesComercialesProveedor\Data;

use Illuminate\Support\Facades\DB;

class CondicionesComercialesProveedorData
{
    /**
     * Obtener las condiciones comerciales registradas para un proveedor.
     */
    public static function get_condiciones_por_proveedor(int $idProveedor, ?string $estado = null): array
    {
        $query = DB::table('condicion_comercial_proveedor as ccp')
            ->where('ccp.id_proveedor_minero', $idProveedor);

        if ($estado !== null && $estado !== '' && $estado !== 'Todos') {
            $query->where('ccp.estado', $estado);
        }

        $results = $query->select([
            'ccp.id',
            'ccp.id_proveedor_minero',
            'ccp.ley_auoz_inicio',
            'ccp.ley_auoz_fin',
            'ccp.maquila',
            'ccp.recuperacion',
            'ccp.consumo',
            'ccp.riesgo_comercial',
            'ccp.estado',
            'ccp.created_at',
        ])
        ->orderBy('ccp.id', 'DESC')
        ->get()
        ->toArray();

        foreach ($results as $row) {
            $row->id = (int) $row->id;
            $row->id_proveedor_minero = (int) $row->id_proveedor_minero;
            $row->ley_auoz_inicio = (float) $row->ley_auoz_inicio;
            $row->ley_auoz_fin = (float) $row->ley_auoz_fin;
            $row->maquila = (float) $row->maquila;
            $row->recuperacion = (float) $row->recuperacion;
            $row->consumo = (float) $row->consumo;
            $row->riesgo_comercial = (float) $row->riesgo_comercial;
        }

        return $results;
    }

    /**
     * Obtener una condición comercial específica por su ID.
     */
    public static function get_condicion_por_id(int $id): ?object
    {
        $row = DB::table('condicion_comercial_proveedor')
            ->where('id', $id)
            ->first();

        if (! $row) {
            return null;
        }

        $row->id = (int) $row->id;
        $row->id_proveedor_minero = (int) $row->id_proveedor_minero;
        $row->ley_auoz_inicio = (float) $row->ley_auoz_inicio;
        $row->ley_auoz_fin = (float) $row->ley_auoz_fin;
        $row->maquila = (float) $row->maquila;
        $row->recuperacion = (float) $row->recuperacion;
        $row->consumo = (float) $row->consumo;
        $row->riesgo_comercial = (float) $row->riesgo_comercial;

        return $row;
    }
}
