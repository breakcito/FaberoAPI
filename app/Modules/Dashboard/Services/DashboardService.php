<?php

namespace App\Modules\Dashboard\Services;

use App\Modules\Dashboard\Data\DashboardData;
use App\Shared\Responses\ApiResponse;
use Carbon\CarbonImmutable;

/**
 * Orquestación del dashboard de indicadores.
 *
 * La ventana de análisis son los últimos 12 meses cerrados (el mes en curso
 * todavía no está completo) más los 12 meses equivalentes del año anterior,
 * que sirven de base para el comparativo interanual de los KPIs.
 *
 * MONEDAS:
 *  - `monto_*_usd` y `*_usd_t` están en dólares.
 *  - La detracción se registra en PEN (`detraccion_pen`); se normaliza a USD
 *    con el tipo de cambio vigente (`valor_compra`) para poder presentarla en
 *    la misma barra que el neto. El TC aplicado viaja en la respuesta.
 */
class DashboardService
{
    /**
     * Paquete completo del dashboard: evolución mensual + fotografía actual.
     */
    public static function get_resumen(?int $idSucursal = null): array
    {
        $mesFin = CarbonImmutable::now()->startOfMonth()->subMonth();
        $mesInicio = $mesFin->subMonths(11);

        $meses = [];
        for ($i = 0; $i < 12; $i++) {
            $meses[] = $mesInicio->addMonths($i);
        }

        // La consulta cubre las dos ventanas: la actual y la del año anterior.
        // Los límites llevan hora porque las columnas comparadas son DATETIME:
        // un `BETWEEN` con fecha sola dejaría fuera el último día.
        $fechaInicio = $meses[0]->subYear()->startOfMonth()->format('Y-m-d H:i:s');
        $fechaFin = $mesFin->endOfMonth()->endOfDay()->format('Y-m-d H:i:s');

        $serie = DashboardData::get_serie_mensual($fechaInicio, $fechaFin, $idSucursal);
        $snapshot = DashboardData::get_snapshot();

        $mesesActual = [];
        $mesesAnterior = [];
        foreach ($meses as $mes) {
            $mesesActual[] = self::fila($mes, $serie[$mes->format('Y-m')] ?? []);
            $mesesAnterior[] = self::fila($mes->subYear(), $serie[$mes->subYear()->format('Y-m')] ?? []);
        }

        return ApiResponse::success([
            'periodo' => [
                'mes_actual' => $mesFin->format('Y-m'),
                'meses' => $mesesActual,
                'meses_anio_anterior' => $mesesAnterior,
            ],
            'financiero' => self::financiero($snapshot['por_pagar'] ?? [], $snapshot['por_cobrar'] ?? [], $snapshot['tipo_cambio'] ?? []),
            'cancha' => [
                'stock' => $snapshot['stock'] ?? [],
                'dias' => $snapshot['dias'] ?? [],
            ],
            'sin_comprobante' => $snapshot['sin_comprobante'] ?? [],
        ]);
    }

    /**
     * Normalizar un mes de la serie al contrato de respuesta.
     *
     * @param  array<string,mixed>  $datos
     * @return array<string,mixed>
     */
    private static function fila(CarbonImmutable $mes, array $datos): array
    {
        $costos = $datos['costos'] ?? [];
        $leyes = $datos['leyes'] ?? [];

        return [
            'anio' => (int) $mes->format('Y'),
            'mes' => (int) $mes->format('n'),
            'tn_receptadas' => (float) ($datos['tn_receptadas'] ?? 0),
            'tn_despachadas' => (float) ($datos['tn_despachadas'] ?? 0),
            'tn_compradas' => (float) ($datos['tn_compradas'] ?? 0),
            'tn_vendidas' => (float) ($datos['tn_vendidas'] ?? 0),
            'monto_compra_usd' => (float) ($datos['monto_compra_usd'] ?? 0),
            'monto_venta_usd' => (float) ($datos['monto_venta_usd'] ?? 0),
            'costo_compra_usd_t' => (float) ($datos['costo_compra_usd_t'] ?? 0),
            'costo_venta_usd_t' => (float) ($datos['costo_venta_usd_t'] ?? 0),
            'costos' => [
                'compra' => [
                    'Oro' => (float) ($costos['compra']['Oro'] ?? 0),
                    'Plata' => (float) ($costos['compra']['Plata'] ?? 0),
                ],
                'venta' => [
                    'Oro' => (float) ($costos['venta']['Oro'] ?? 0),
                    'Plata' => (float) ($costos['venta']['Plata'] ?? 0),
                ],
            ],
            'leyes' => [
                'compra' => [
                    'Oro' => (float) ($leyes['compra']['Oro'] ?? 0),
                    'Plata' => (float) ($leyes['compra']['Plata'] ?? 0),
                ],
                'venta_final' => [
                    'Oro' => (float) ($leyes['venta_final']['Oro'] ?? 0),
                    'Plata' => (float) ($leyes['venta_final']['Plata'] ?? 0),
                ],
                'venta_cliente' => [
                    'Oro' => (float) ($leyes['venta_cliente']['Oro'] ?? 0),
                    'Plata' => (float) ($leyes['venta_cliente']['Plata'] ?? 0),
                ],
            ],
        ];
    }

    /**
     * Cuentas por pagar y por cobrar, normalizadas a USD.
     *
     * @param  array<string,mixed>  $porPagar
     * @param  array<string,mixed>  $porCobrar
     * @param  array<string,mixed>  $tipoCambio
     * @return array<string,mixed>
     */
    private static function financiero(array $porPagar, array $porCobrar, array $tipoCambio): array
    {
        $tcCompra = (float) ($tipoCambio['valor_compra'] ?? 0);
        $detraccionUsd = $tcCompra > 0
            ? (float) ($porPagar['detraccion_pen'] ?? 0) / $tcCompra
            : 0.0;

        $netoPagar = (float) ($porPagar['neto_usd'] ?? 0);
        $netoCobrar = (float) ($porCobrar['neto_usd'] ?? 0);
        $detraccionCobrarUsd = $tcCompra > 0
            ? (float) ($porCobrar['detraccion_pen'] ?? 0) / $tcCompra
            : 0.0;

        return [
            'por_pagar' => [
                'neto_usd' => $netoPagar,
                'detraccion_usd' => $detraccionUsd,
                'total_usd' => $netoPagar + $detraccionUsd,
                'mayor_deudor' => $porPagar['mayor_deudor'] ?? null,
            ],
            'por_cobrar' => [
                'neto_usd' => $netoCobrar,
                'detraccion_usd' => $detraccionCobrarUsd,
                'total_usd' => $netoCobrar + $detraccionCobrarUsd,
                'mayor_acreedor' => $porCobrar['mayor_acreedor'] ?? null,
            ],
            'tipo_cambio' => $tipoCambio !== [] ? $tipoCambio : null,
        ];
    }
}
