<?php

namespace App\Modules\Proveedores\Services;

use App\Modules\CuentasBancariasProveedor\Data\CuentasBancariasProveedorData;
use App\Modules\Proveedores\Data\ProveedoresData;
use App\Shared\Responses\ApiResponse;
use Illuminate\Support\Facades\DB;

class ProveedoresService
{
    public static function get_proveedores(): array
    {
        $data = ProveedoresData::get_proveedores();

        return ApiResponse::success($data, 'Proveedores obtenidos correctamente');
    }

    /**
     * @param  array  $cuentas  Listado de cuentas bancarias (opcional)
     *                          - id_banco (int)
     *                          - moneda (string)
     *                          - numero_cuenta (string)
     *                          - cci (string|null)
     *                          - es_para_detraccion (int)
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
                CuentasBancariasProveedorData::crear_cuenta_bancaria(
                    $id,
                    $cta['id_banco'],
                    $cta['moneda'],
                    $cta['numero_cuenta'],
                    $cta['cci'] ?? null,
                    (int) ($cta['es_para_detraccion'] ?? 0)
                );
            }

            $new_proveedor = ProveedoresData::get_proveedor_by_id($id);

            return ApiResponse::success($new_proveedor, 'Proveedor registrado correctamente');
        });
    }

    public static function editar_proveedor(
        int $id,
        string $tipoEntidad,
        ?string $dni,
        ?string $ruc,
        string $razonSocial,
        ?string $direccion,
        ?string $telefono,
        ?string $correo
    ): array {
        ProveedoresData::editar_proveedor($id, $tipoEntidad, $dni, $ruc, $razonSocial, $direccion, $telefono, $correo);
        $updated = ProveedoresData::get_proveedor_by_id($id);

        return ApiResponse::success($updated, 'Proveedor editado correctamente');
    }

    public static function cambiar_estado_proveedor(int $id, string $estado): array
    {
        ProveedoresData::cambiar_estado_proveedor($id, $estado);
        $updated = ProveedoresData::get_proveedor_by_id($id);

        return ApiResponse::success($updated, 'Estado del proveedor cambiado correctamente');
    }

    public static function eliminar_proveedor(int $id): array
    {
        ProveedoresData::eliminar_proveedor($id);

        return ApiResponse::success(null, 'Proveedor eliminado correctamente');
    }

    public static function get_concesiones(int $id_proveedor): array
    {
        $data = ProveedoresData::get_concesiones($id_proveedor);

        return ApiResponse::success($data, 'Concesiones del proveedor obtenidas correctamente');
    }

    public static function asociar_concesion(int $id_proveedor, int $id_concesion): array
    {
        if (ProveedoresData::existe_concesion_proveedor($id_proveedor, $id_concesion)) {
            return ApiResponse::error('Esta concesión ya está asociada a este proveedor');
        }
        ProveedoresData::asociar_concesion($id_proveedor, $id_concesion);

        return ApiResponse::success(null, 'Concesión asociada correctamente');
    }

    public static function desasociar_concesion(int $id_proveedor, int $id_concesion): array
    {
        ProveedoresData::desasociar_concesion($id_proveedor, $id_concesion);

        return ApiResponse::success(null, 'Concesión desasociada correctamente');
    }
}
