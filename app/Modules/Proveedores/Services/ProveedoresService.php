<?php

namespace App\Modules\Proveedores\Services;

use App\Shared\Responses\ApiResponse;
use App\Modules\Proveedores\Data\CuentasBancariasData;
use App\Modules\Proveedores\Data\ProveedoresData;
use Illuminate\Support\Facades\DB;

class ProveedoresService
{

    public static function get_proveedores(): array
    {
        $data = ProveedoresData::get_proveedores();
        return ApiResponse::success($data, "Proveedores obtenidos correctamente");
    }

    /**
     * @param array $cuentas Listado de cuentas bancarias (opcional)
     * - id_banco (int)
     * - moneda (string)
     * - numero_cuenta (string)
     * - cci (string|null)
     * - es_para_detraccion (int)
     */
    public static function crear_proveedor(
        string $tipoEntidad,
        ?string $dni,
        ?string $ruc,
        string $razonSocial,
        ?string $direccion,
        ?string $telefono,
        ?string $correo,
        array $cuentas = []
    ): array {
        return DB::transaction(function () use ($tipoEntidad, $dni, $ruc, $razonSocial, $direccion, $telefono, $correo, $cuentas) {
            $id = ProveedoresData::crear_proveedor(
                $tipoEntidad,
                $dni,
                $ruc,
                $razonSocial,
                $direccion,
                $telefono,
                $correo
            );

            foreach ($cuentas as $cta) {
                CuentasBancariasData::crear_cuenta_bancaria(
                    $id,
                    $cta['id_banco'],
                    $cta['moneda'],
                    $cta['numero_cuenta'],
                    $cta['cci'] ?? null,
                    (int) ($cta['es_para_detraccion'] ?? 0)
                );
            }

            $new_proveedor = ProveedoresData::get_proveedor_by_id($id);
            return ApiResponse::success($new_proveedor, "Proveedor registrado correctamente");
        });
    }
}
