<?php

namespace App\Modules\CuentasBancariasEmpresa\Services;

use App\Modules\CuentasBancariasEmpresa\Data\CuentasBancariasEmpresaData;
use App\Shared\Responses\ApiResponse;

class CuentasBancariasEmpresaService
{
    public static function get_cuentas_bancarias(int $idEmpresa): array
    {
        $data = CuentasBancariasEmpresaData::get_cuentas_bancarias($idEmpresa);

        return ApiResponse::success($data, 'Cuentas bancarias obtenidas correctamente');
    }

    public static function crear_cuenta_bancaria(
        int $idEmpresa,
        int $idBanco,
        string $moneda,
        string $numeroCuenta,
        ?string $cci,
        int $esParaDetraccion
    ): array {
        if (CuentasBancariasEmpresaData::existe_cuenta_bancaria($idEmpresa, $idBanco, $numeroCuenta)) {
            return ApiResponse::error('Esta cuenta bancaria ya está registrada para esta empresa');
        }

        $id = CuentasBancariasEmpresaData::crear_cuenta_bancaria(
            $idEmpresa,
            $idBanco,
            $moneda,
            $numeroCuenta,
            $cci,
            $esParaDetraccion
        );
        $nuevaCuenta = CuentasBancariasEmpresaData::get_cuenta_bancaria_by_id($id);

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
        CuentasBancariasEmpresaData::editar_cuenta_bancaria(
            $id,
            $idBanco,
            $moneda,
            $numeroCuenta,
            $cci,
            $esParaDetraccion
        );
        $updated = CuentasBancariasEmpresaData::get_cuenta_bancaria_by_id($id);

        return ApiResponse::success($updated, 'Cuenta bancaria editada correctamente');
    }

    public static function eliminar_cuenta_bancaria(int $id): array
    {
        CuentasBancariasEmpresaData::eliminar_cuenta_bancaria($id);

        return ApiResponse::success(null, 'Cuenta bancaria eliminada correctamente');
    }

    public static function cambiar_estado_cuenta_bancaria(int $id, string $estado): array
    {
        CuentasBancariasEmpresaData::cambiar_estado_cuenta_bancaria($id, $estado);
        $updated = CuentasBancariasEmpresaData::get_cuenta_bancaria_by_id($id);

        return ApiResponse::success($updated, 'Estado de la cuenta bancaria cambiado correctamente');
    }
}
