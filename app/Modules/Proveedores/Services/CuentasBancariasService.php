<?php

namespace App\Modules\Proveedores\Services;

use App\Models\CuentaBancariaProveedor;
use App\Shared\Responses\ApiResponse;
use App\Modules\Proveedores\Data\CuentasBancariasData;

class CuentasBancariasService
{
    public static function get_cuentas_bancarias(int $idProveedor): array
    {
        $data = CuentasBancariasData::get_cuentas_bancarias($idProveedor);
        return ApiResponse::success($data, "Cuentas bancarias obtenidas correctamente");
    }

    public static function crear_cuenta_bancaria(
        int $idProveedor,
        int $idBanco,
        string $moneda,
        string $numeroCuenta,
        ?string $cci,
        int $esParaDetraccion
    ): array {
        $existe = CuentasBancariasData::existe_cuenta_bancaria($idProveedor, $idBanco, $numeroCuenta);

        if ($existe) {
            return ApiResponse::error("Esta cuenta bancaria ya está registrada para este proveedor");
        }

        $id = CuentasBancariasData::crear_cuenta_bancaria(
            $idProveedor,
            $idBanco,
            $moneda,
            $numeroCuenta,
            $cci,
            $esParaDetraccion
        );
        $nuevaCuenta = CuentasBancariasData::get_cuenta_bancaria_by_id($id);
        return ApiResponse::success($nuevaCuenta, "Cuenta bancaria registrada correctamente");
    }
}
