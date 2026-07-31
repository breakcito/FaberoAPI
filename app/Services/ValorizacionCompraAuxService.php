<?php

namespace App\Services;

use App\Data\ValorizacionCompraAuxData;
use App\Shared\Responses\ApiResponse;

class ValorizacionCompraAuxService
{
    /**
     * Obtener listado de proveedores que poseen lotes comercializables con guías y no valorizados
     */
    public static function get_proveedores_con_lotes(): array
    {
        return ValorizacionCompraAuxData::get_proveedores_con_lotes();
    }

    /**
     * Obtener concesiones asociadas a un proveedor
     */
    public static function get_concesiones_proveedor(int $idProveedor): array
    {
        return ValorizacionCompraAuxData::get_concesiones_proveedor($idProveedor);
    }

    /**
     * Obtener cuentas bancarias (ordinarias y detracción) de un proveedor
     */
    public static function get_cuentas_bancarias_proveedor(int $idProveedor): array
    {
        return ValorizacionCompraAuxData::get_cuentas_bancarias_proveedor($idProveedor);
    }

    /**
     * Obtener anticipos con saldo disponible de un proveedor
     */
    public static function get_anticipos_proveedor(int $idProveedor): array
    {
        return ValorizacionCompraAuxData::get_anticipos_proveedor($idProveedor);
    }

    /**
     * Obtener lotes disponibles con sus análisis y condiciones comerciales por ley
     */
    public static function get_lotes_disponibles_valorizacion(int $idProveedor, ?int $idValorizacionEdicion = null): array
    {
        return ValorizacionCompraAuxData::get_lotes_disponibles_valorizacion($idProveedor, $idValorizacionEdicion);
    }

    /**
     * Obtener valorizaciones aprobadas de un proveedor (las que aún no tienen comprobante)
     * para usarlas en el formulario de registro de comprobantes.
     */
    public static function get_valorizaciones_aprobadas_por_proveedor(int $idProveedor): array
    {
        $data = ValorizacionCompraAuxData::get_valorizaciones_aprobadas_por_proveedor($idProveedor);

        return ApiResponse::success($data, 'Valorizaciones aprobadas obtenidas correctamente.');
    }
}
