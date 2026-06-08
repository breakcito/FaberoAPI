<?php

namespace App\Modules\TiposVehiculo\Data;

use App\Models\TipoVehiculo;
use App\Shared\Enums\_Generic\EstadoBase;
use Illuminate\Support\Facades\DB;

class TiposVehiculoData
{
    public static function get_tipos_vehiculo(?int $id = null)
    {
        $sql = '
        SELECT
            tv.id,
            tv.nombre,
            tv.tiene_carreta,
            tv.es_carreta,
            tv.estado
        FROM
            tipo_vehiculo tv
        WHERE 1 = 1
        ';

        $params = [];
        if ($id !== null) {
            $sql .= ' AND tv.id = :id';
            $params['id'] = $id;

            return (array) DB::selectOne($sql, $params);
        }

        $sql .= ' ORDER BY tv.nombre ASC;';

        return DB::select($sql, $params);
    }

    public static function get_tipo_vehiculo_by_id(int $id): array
    {
        return self::get_tipos_vehiculo(id: $id);
    }

    public static function crear_tipo_vehiculo(string $nombre, bool $tieneCarreta, bool $esCarreta): int
    {
        return TipoVehiculo::insertGetId([
            'nombre' => $nombre,
            'tiene_carreta' => $tieneCarreta ? 1 : 0,
            'es_carreta' => $esCarreta ? 1 : 0,
            'estado' => EstadoBase::Activo->value,
        ]);
    }

    public static function editar_tipo_vehiculo(int $id, string $nombre, bool $tieneCarreta, bool $esCarreta): bool
    {
        return TipoVehiculo::where('id', $id)->update([
            'nombre' => $nombre,
            'tiene_carreta' => $tieneCarreta ? 1 : 0,
            'es_carreta' => $esCarreta ? 1 : 0,
        ]) >= 0;
    }

    public static function cambiar_estado_tipo_vehiculo(int $id, string $estado): bool
    {
        return TipoVehiculo::where('id', $id)->update([
            'estado' => $estado,
        ]) >= 0;
    }
}
