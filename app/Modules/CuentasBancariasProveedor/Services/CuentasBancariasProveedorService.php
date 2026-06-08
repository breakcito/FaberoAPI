<?php

namespace App\Modules\CuentasBancariasProveedor\Services;

use App\Modules\CuentasBancariasProveedor\Data\CuentasBancariasProveedorData;
use App\Shared\Responses\ApiResponse;

class CuentasBancariasProveedorService
{
    public static function get_cuentas_bancarias(int $idProveedor): array
    {
        $data = CuentasBancariasProveedorData::get_cuentas_bancarias($idProveedor);

        return ApiResponse::success($data, 'Cuentas bancarias obtenidas correctamente');
    }

    public static function crear_cuenta_bancaria(
        int $idProveedor,
        int $idBanco,
        string $moneda,
        string $numeroCuenta,
        ?string $cci,
        int $esParaDetraccion
    ): array {
        $existe = CuentasBancariasProveedorData::existe_cuenta_bancaria($idProveedor, $idBanco, $numeroCuenta);

        if ($existe) {
            return ApiResponse::error('Esta cuenta bancaria ya está registrada para este proveedor');
        }

        $id = CuentasBancariasProveedorData::crear_cuenta_bancaria(
            $idProveedor,
            $idBanco,
            $moneda,
            $numeroCuenta,
            $cci,
            $esParaDetraccion
        );
        $nuevaCuenta = CuentasBancariasProveedorData::get_cuenta_bancaria_by_id($id);

        return ApiResponse::success($nuevaCuenta, 'Cuenta bancaria registrada correctamente');
    }

    public static function editar_cuenta_bancaria(
        int $id,
        int $idBanco,
        string $moneda,
        string $numeroCuenta,
        ?string $cci,
        int $esParaDetraccion
    ): array {
        CuentasBancariasProveedorData::editar_cuenta_bancaria(
            $id,
            $idBanco,
            $moneda,
            $numeroCuenta,
            $cci,
            $esParaDetraccion
        );
        $updated = CuentasBancariasProveedorData::get_cuenta_bancaria_by_id($id);

        return ApiResponse::success($updated, 'Cuenta bancaria editada correctamente');
    }

    public static function eliminar_cuenta_bancaria(int $id): array
    {
        CuentasBancariasProveedorData::eliminar_cuenta_bancaria($id);

        return ApiResponse::success(null, 'Cuenta bancaria eliminada correctamente');
    }

    public static function cambiar_estado_cuenta_bancaria(int $id, string $estado): array
    {
        CuentasBancariasProveedorData::cambiar_estado_cuenta_bancaria($id, $estado);
        $updated = CuentasBancariasProveedorData::get_cuenta_bancaria_by_id($id);

        return ApiResponse::success($updated, 'Estado de la cuenta bancaria cambiado correctamente');
    }
}
