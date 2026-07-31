<?php

namespace App\Services;

use App\Data\TipoCambioData;
use App\Shared\Enums\_Generic\EstadoBase;
use App\Shared\Responses\ApiResponse;

class TipoCambioService
{
    /**
     * Listar tipos de cambio aplicando filtros opcionales.
     */
    public static function get_tipos_cambio(?string $fecha = null, ?EstadoBase $estado = null): array
    {
        $data = TipoCambioData::get_tipos_cambio($fecha, $estado);

        return ApiResponse::success($data, 'Tipos de cambio obtenidos correctamente.');
    }

    /**
     * Obtener un tipo de cambio por la fecha exacta (uso típico del formulario de comprobantes).
     */
    public static function get_tipo_cambio_por_fecha(string $fecha): array
    {
        $data = TipoCambioData::get_tipo_cambio_por_fecha($fecha);
        if (! $data) {
            return ApiResponse::error("No existe un tipo de cambio registrado para la fecha {$fecha}.");
        }

        return ApiResponse::success($data, 'Tipo de cambio obtenido correctamente.');
    }

    /**
     * Registrar un nuevo tipo de cambio para una fecha (regla: un solo registro activo por día).
     */
    public static function crear_tipo_cambio(
        int $idEmpleadoRegistro,
        float $valorCompra,
        float $valorVenta,
        string $fecha
    ): array {
        if (TipoCambioData::existe_tipo_cambio_en_fecha($fecha)) {
            return ApiResponse::error("Ya existe un tipo de cambio registrado para la fecha {$fecha}.");
        }

        $id = TipoCambioData::crear_tipo_cambio($idEmpleadoRegistro, $valorCompra, $valorVenta, $fecha);
        $nuevo = TipoCambioData::get_tipo_cambio_by_id($id);

        return ApiResponse::success($nuevo, 'Tipo de cambio registrado correctamente.');
    }

    /**
     * Cambiar estado (Activo/Inactivo) de un tipo de cambio.
     */
    public static function cambiar_estado_tipo_cambio(int $id, string $estado): array
    {
        TipoCambioData::cambiar_estado_tipo_cambio($id, $estado);

        return ApiResponse::success(null, 'Estado del tipo de cambio actualizado correctamente.');
    }
}
