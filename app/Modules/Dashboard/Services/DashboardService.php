<?php

namespace App\Modules\Dashboard\Services;

use App\Modules\Dashboard\Data\DashboardData;
use App\Shared\Responses\ApiResponse;

class DashboardService
{
    /**
     * Obtener el paquete de indicadores y métricas operativas de la planta.
     *
     * @param  int|null  $idSucursal  Sucursal a filtrar; null o 0 = todas
     * @param  string|null  $fechaInicio  YYYY-MM-DD; por defecto, 29 días antes del fin
     * @param  string|null  $fechaFin  YYYY-MM-DD; por defecto, hoy
     */
    public static function get_indicadores(
        ?int $idSucursal = null,
        ?string $fechaInicio = null,
        ?string $fechaFin = null
    ): array {
        $fin = self::normalizar_fecha($fechaFin) ?? date('Y-m-d');
        $inicio = self::normalizar_fecha($fechaInicio) ?? date('Y-m-d', strtotime($fin.' -29 days'));

        if ($inicio > $fin) {
            return ApiResponse::error('La fecha de inicio no puede ser posterior a la fecha de fin.');
        }

        $sucursal = ($idSucursal !== null && $idSucursal > 0) ? $idSucursal : null;

        $data = DashboardData::get_dashboard($sucursal, $inicio.' 00:00:00', $fin.' 23:59:59');

        $data['meta'] = [
            'fecha_inicio' => $inicio,
            'fecha_fin' => $fin,
            'id_sucursal' => $sucursal,
            'monedas' => [
                'valorizacion' => 'USD',
                'financiero' => 'PEN',
            ],
        ];

        return ApiResponse::success($data, 'Indicadores obtenidos correctamente.');
    }

    /**
     * Normalizar una fecha a formato YYYY-MM-DD.
     */
    private static function normalizar_fecha(?string $fecha): ?string
    {
        if ($fecha === null || trim($fecha) === '') {
            return null;
        }
        $timestamp = strtotime($fecha);

        return $timestamp === false ? null : date('Y-m-d', $timestamp);
    }
}
