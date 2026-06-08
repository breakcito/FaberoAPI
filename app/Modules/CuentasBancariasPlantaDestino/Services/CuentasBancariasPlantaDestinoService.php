<?php

namespace App\Modules\CuentasBancariasPlantaDestino\Services;

use App\Modules\CuentasBancariasPlantaDestino\Data\CuentasBancariasPlantaDestinoData;
use App\Shared\Responses\ApiResponse;
use Illuminate\Support\Facades\DB;

class CuentasBancariasPlantaDestinoService
{
    public static function get_cuentas_bancarias(int $idPlanta): array
    {
        $data = CuentasBancariasPlantaDestinoData::get_cuentas_bancarias($idPlanta);

        return ApiResponse::success($data, 'Cuentas bancarias obtenidas correctamente');
    }

    public static function crear_cuenta_bancaria(
        int $idPlanta,
        int $idBanco,
        string $moneda,
        string $numeroCuenta,
        ?string $cci,
        int $esParaDetraccion
    ): array {
        if (CuentasBancariasPlantaDestinoData::existe_cuenta_bancaria($idPlanta, $idBanco, $numeroCuenta)) {
            return ApiResponse::error('Esta cuenta bancaria ya está registrada para esta planta de destino');
        }

        $id = CuentasBancariasPlantaDestinoData::crear_cuenta_bancaria(
            $idPlanta,
            $idBanco,
            $moneda,
            $numeroCuenta,
            $cci,
            $esParaDetraccion
        );
        $nuevaCuenta = CuentasBancariasPlantaDestinoData::get_cuenta_bancaria_by_id($id);

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
        $cuentaActual = CuentasBancariasPlantaDestinoData::get_cuenta_bancaria_by_id($id);
        if (empty($cuentaActual)) {
            return ApiResponse::error('Cuenta bancaria no encontrada');
        }

        $sql = 'SELECT id_planta_destino FROM cuenta_bancaria_planta_destino WHERE id = ?';
        $row = DB::selectOne($sql, [$id]);
        $idPlanta = $row->id_planta_destino;

        if (CuentasBancariasPlantaDestinoData::existe_cuenta_bancaria($idPlanta, $idBanco, $numeroCuenta, $id)) {
            return ApiResponse::error('Esta cuenta bancaria ya está registrada para esta planta de destino');
        }

        CuentasBancariasPlantaDestinoData::editar_cuenta_bancaria(
            $id,
            $idBanco,
            $moneda,
            $numeroCuenta,
            $cci,
            $esParaDetraccion
        );
        $updated = CuentasBancariasPlantaDestinoData::get_cuenta_bancaria_by_id($id);

        return ApiResponse::success($updated, 'Cuenta bancaria editada correctamente');
    }

    public static function cambiar_estado_cuenta_bancaria(int $id, string $estado): array
    {
        CuentasBancariasPlantaDestinoData::cambiar_estado_cuenta_bancaria($id, $estado);
        $updated = CuentasBancariasPlantaDestinoData::get_cuenta_bancaria_by_id($id);

        return ApiResponse::success($updated, 'Estado de la cuenta bancaria cambiado correctamente');
    }
}
