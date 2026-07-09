<?php

namespace App\Data;

use Illuminate\Support\Facades\DB;

class VehiculosData
{
    /**
     * Obtener listado global de vehículos simplificado, filtrable por serie, número de placa y/o ID.
     */
    public static function get_vehiculos(?string $seriePlaca = null, ?string $numeroPlaca = null, ?int $id = null, ?bool $esCarreta = null)
    {
        $sql = '
        SELECT
            v.id AS id_vehiculo,
            et.id AS id_empresa_transporte,
            tv.id AS id_tipo_vehiculo,
            et.razon_social,
            tv.nombre AS tipo_vehiculo_nombre,
            tv.es_carreta,
            v.serie_placa,
            v.numero_placa,
            v.estado,
            (
                SELECT ru2.id_conductor
                FROM recepcion_unidad ru2
                WHERE ru2.id_vehiculo = v.id
                ORDER BY ru2.id DESC
                LIMIT 1
            ) AS last_id_conductor
        FROM
            vehiculo v
        LEFT JOIN empresa_transporte et ON et.id = v.id_empresa_transporte
        LEFT JOIN tipo_vehiculo tv ON tv.id = v.id_tipo_vehiculo
        WHERE 1 = 1
          AND (v.serie_placa IS NULL OR v.serie_placa <> \'FICT\')
        ';

        $params = [];

        if ($id !== null) {
            $sql .= ' AND v.id = :id';
            $params['id'] = $id;
        }

        if ($seriePlaca !== null && $seriePlaca !== '') {
            $sql .= ' AND v.serie_placa = :serie_placa';
            $params['serie_placa'] = $seriePlaca;
        }

        if ($numeroPlaca !== null && $numeroPlaca !== '') {
            $sql .= ' AND v.numero_placa = :numero_placa';
            $params['numero_placa'] = $numeroPlaca;
        }

        if ($esCarreta === true) {
            $sql .= ' AND tv.es_carreta = 1';
        } elseif ($esCarreta === false) {
            $sql .= ' AND (tv.es_carreta = 0 OR tv.es_carreta IS NULL)';
        }

        $sql .= ' ORDER BY v.numero_placa ASC;';

        return DB::select($sql, $params);
    }

    /**
     * Crear un nuevo vehículo de forma simplificada
     */
    public static function crear_vehiculo_simplificado(
        ?string $seriePlaca,
        string $numeroPlaca,
        int $idEmpresaTransporte,
        int $idTipoVehiculo
    ): int {
        return DB::table('vehiculo')->insertGetId([
            'id_marca' => null,
            'id_empresa_transporte' => $idEmpresaTransporte,
            'id_tipo_vehiculo' => $idTipoVehiculo,
            'serie_placa' => $seriePlaca,
            'numero_placa' => $numeroPlaca,
            'numero_constancia_mtc' => null,
            'capacidad' => 0.0,
            'tara' => 0.0,
            'largo' => null,
            'ancho' => null,
            'alto' => null,
            'estado' => 'Activo',
        ]);
    }

    /**
     * Editar un vehículo de forma simplificada (transportista y tipo de vehículo)
     */
    public static function editar_vehiculo_simplificado(
        int $id,
        int $idEmpresaTransporte,
        int $idTipoVehiculo
    ): bool {
        return DB::table('vehiculo')->where('id', $id)->update([
            'id_empresa_transporte' => $idEmpresaTransporte,
            'id_tipo_vehiculo' => $idTipoVehiculo,
        ]) >= 0;
    }
}
