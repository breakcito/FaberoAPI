<?php

namespace App\Modules\CuentasBancariasPlantaDestino\Data;

use App\Models\CuentaBancariaPlantaDestino;
use App\Shared\Enums\_Generic\EstadoBase;
use Illuminate\Support\Facades\DB;

class CuentasBancariasPlantaDestinoData
{
    /**
     * Listar cuentas bancarias por planta o por ID de cuenta
     */
    public static function get_cuentas_bancarias(
        ?int $id_planta = null,
        ?int $id_cuenta_bancaria = null
    ): array {
        $sql = '
        SELECT
            cb.id AS id_cuenta_bancaria,
            bc.nombre as banco,
            bc.abreviatura as banco_abv,
            cb.id_banco,
            cb.moneda,
            cb.numero_cuenta,
            cb.cci,
            cb.es_para_detraccion,
            cb.estado
        FROM
            cuenta_bancaria_planta_destino cb
        INNER JOIN banco bc ON bc.id = cb.id_banco
        WHERE 1 = 1
        ';

        $params = [];
        if ($id_planta !== null) {
            $sql .= ' AND cb.id_planta_destino = :id_planta';
            $params['id_planta'] = $id_planta;
        }

        if ($id_cuenta_bancaria !== null) {
            $sql .= ' AND cb.id = :id_cuenta_bancaria';
            $params['id_cuenta_bancaria'] = $id_cuenta_bancaria;

            return (array) DB::selectOne($sql, $params);
        }

        $sql .= ' ORDER BY cb.es_para_detraccion DESC, cb.moneda, cb.numero_cuenta;';

        return DB::select($sql, $params);
    }

    public static function get_cuenta_bancaria_by_id(int $id_cuenta): array
    {
        return self::get_cuentas_bancarias(id_cuenta_bancaria: $id_cuenta);
    }

    public static function existe_cuenta_bancaria(int $id_planta, int $id_banco, string $numero_cuenta, ?int $excluirId = null): bool
    {
        $query = CuentaBancariaPlantaDestino::where('id_planta_destino', $id_planta)
            ->where('id_banco', $id_banco)
            ->where('numero_cuenta', $numero_cuenta);

        if ($excluirId !== null) {
            $query->where('id', '!=', $excluirId);
        }

        return $query->exists();
    }

    public static function crear_cuenta_bancaria(
        int $id_planta,
        int $id_banco,
        string $moneda,
        string $numero_cuenta,
        ?string $cci,
        int $es_para_detraccion
    ): int {
        return CuentaBancariaPlantaDestino::insertGetId([
            'id_planta_destino' => $id_planta,
            'id_banco' => $id_banco,
            'moneda' => $moneda,
            'numero_cuenta' => $numero_cuenta,
            'cci' => $cci,
            'es_para_detraccion' => $es_para_detraccion,
            'estado' => EstadoBase::Activo->value,
        ]);
    }

    public static function editar_cuenta_bancaria(
        int $id,
        int $id_banco,
        string $moneda,
        string $numero_cuenta,
        ?string $cci,
        int $es_para_detraccion
    ): bool {
        return CuentaBancariaPlantaDestino::where('id', $id)->update([
            'id_banco' => $id_banco,
            'moneda' => $moneda,
            'numero_cuenta' => $numero_cuenta,
            'cci' => $cci,
            'es_para_detraccion' => $es_para_detraccion,
        ]) >= 0;
    }

    public static function cambiar_estado_cuenta_bancaria(int $id, string $estado): bool
    {
        return CuentaBancariaPlantaDestino::where('id', $id)->update(['estado' => $estado]) >= 0;
    }
}
