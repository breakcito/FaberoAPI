<?php

namespace App\Modules\Proveedores\Data;

use App\Models\CuentaBancariaProveedor;
use App\Shared\Enums\_Generic\EstadoBase;
use Illuminate\Support\Facades\DB;

class CuentasBancariasData
{
    public static function get_cuentas_bancarias(
        ?int $id_proveedor = null,
        ?int $id_cuenta_bancaria = null
    ): array {
        $sql = '
        SELECT
            cn.id AS id_cuenta_bancaria,
            bc.nombre as banco,
            bc.abreviatura as banco_abv,
            cn.moneda,
            cn.numero_cuenta,
            cn.cci,
            cn.es_para_detraccion,
            cn.estado
        FROM
            cuenta_bancaria_proveedor cn
        INNER JOIN banco bc ON bc.id = cn.id_banco
        ';

        $params = [];
        if ($id_proveedor !== null) {
            $sql .= ' AND cn.id_proveedor = :id_proveedor';
            $params['id_proveedor'] = $id_proveedor;
        }

        if ($id_cuenta_bancaria !== null) {
            $sql .= ' AND cn.id = :id_cuenta_bancaria';
            $params['id_cuenta_bancaria'] = $id_cuenta_bancaria;
            return (array) DB::selectOne($sql, $params);
        }

        $sql .= ' ORDER BY cn.es_para_detraccion DESC, cn.moneda, cn.numero_cuenta;';
        return DB::select($sql, $params);
    }

    public static function get_cuenta_bancaria_by_id(int $id_cuenta_bancaria): array
    {
        return self::get_cuentas_bancarias(id_cuenta_bancaria: $id_cuenta_bancaria);
    }

    public static function crear_cuenta_bancaria(
        int $id_proveedor,
        int $id_banco,
        string $moneda,
        string $numeroCuenta,
        ?string $cci,
        int $esParaDetraccion
    ): int {
        return CuentaBancariaProveedor::insertGetId([
            'id_proveedor' => $id_proveedor,
            'id_banco' => $id_banco,
            'moneda' => $moneda,
            'numero_cuenta' => $numeroCuenta,
            'cci' => $cci,
            'es_para_detraccion' => $esParaDetraccion,
            'estado' => EstadoBase::Activo->value
        ]);
    }

    public static function existe_cuenta_bancaria(int $id_proveedor, int $id_banco, string $numero_cuenta): bool
    {
        return CuentaBancariaProveedor::where('id_proveedor', $id_proveedor)
            ->where('id_banco', $id_banco)
            ->where('numero_cuenta', $numero_cuenta)
            ->exists();
    }
}
