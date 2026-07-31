<?php

namespace App\Modules\AnticiposProveedor\Data;

use Illuminate\Support\Facades\DB;

class AnticiposProveedorData
{
    /**
     * Obtener listado de anticipos de proveedor con filtros opcionales.
     *
     * @param  array{id_proveedor_minero?: int|null, estado?: string|null, fecha_inicio?: string|null, fecha_fin?: string|null}  $filters
     */
    public static function get_anticipos(array $filters = []): array
    {
        $sql = '
        SELECT
            ap.id,
            ap.id_proveedor_minero,
            p.razon_social AS proveedor_nombre,
            ap.id_empleado_registro,
            CONCAT(emp.nombre, " ", emp.apellido) AS empleado_registro_nombre,
            ap.serie_factura,
            ap.numero_factura,
            ap.saldo_inicial,
            ap.saldo_actual,
            ap.evidencias,
            ap.log_cambios,
            ap.estado,
            ap.created_at
        FROM
            anticipo_proveedor ap
        INNER JOIN proveedor p ON p.id = ap.id_proveedor_minero
        INNER JOIN empleado emp ON emp.id = ap.id_empleado_registro
        WHERE 1 = 1
        ';

        $params = [];

        if (! empty($filters['id_proveedor_minero'])) {
            $sql .= ' AND ap.id_proveedor_minero = :id_proveedor_minero';
            $params['id_proveedor_minero'] = (int) $filters['id_proveedor_minero'];
        }

        if (! empty($filters['estado']) && $filters['estado'] !== 'Todos') {
            $sql .= ' AND ap.estado = :estado';
            $params['estado'] = $filters['estado'];
        }

        if (! empty($filters['fecha_inicio'])) {
            $sql .= ' AND ap.created_at >= :fecha_inicio';
            $params['fecha_inicio'] = $filters['fecha_inicio'].' 00:00:00';
        }

        if (! empty($filters['fecha_fin'])) {
            $sql .= ' AND ap.created_at <= :fecha_fin';
            $params['fecha_fin'] = $filters['fecha_fin'].' 23:59:59';
        }

        $sql .= ' ORDER BY ap.id DESC;';

        $results = DB::select($sql, $params);

        foreach ($results as $item) {
            $item->id = (int) $item->id;
            $item->id_proveedor_minero = (int) $item->id_proveedor_minero;
            $item->id_empleado_registro = (int) $item->id_empleado_registro;
            $item->saldo_inicial = (float) $item->saldo_inicial;
            $item->saldo_actual = (float) $item->saldo_actual;
            $item->evidencias = isset($item->evidencias) ? json_decode($item->evidencias, true) ?? [] : [];
            $item->log_cambios = isset($item->log_cambios) ? json_decode($item->log_cambios, true) ?? [] : [];
        }

        return $results;
    }

    /**
     * Obtener un anticipo específico por su ID.
     */
    public static function get_anticipo_by_id(int $id): ?object
    {
        $sql = '
        SELECT
            ap.id,
            ap.id_proveedor_minero,
            p.razon_social AS proveedor_nombre,
            ap.id_empleado_registro,
            CONCAT(emp.nombre, " ", emp.apellido) AS empleado_registro_nombre,
            ap.serie_factura,
            ap.numero_factura,
            ap.saldo_inicial,
            ap.saldo_actual,
            ap.evidencias,
            ap.log_cambios,
            ap.estado,
            ap.created_at
        FROM
            anticipo_proveedor ap
        INNER JOIN proveedor p ON p.id = ap.id_proveedor_minero
        INNER JOIN empleado emp ON emp.id = ap.id_empleado_registro
        WHERE ap.id = :id
        LIMIT 1;
        ';

        $item = DB::selectOne($sql, ['id' => $id]);

        if (! $item) {
            return null;
        }

        $item->id = (int) $item->id;
        $item->id_proveedor_minero = (int) $item->id_proveedor_minero;
        $item->id_empleado_registro = (int) $item->id_empleado_registro;
        $item->saldo_inicial = (float) $item->saldo_inicial;
        $item->saldo_actual = (float) $item->saldo_actual;
        $item->evidencias = isset($item->evidencias) ? json_decode($item->evidencias, true) ?? [] : [];
        $item->log_cambios = isset($item->log_cambios) ? json_decode($item->log_cambios, true) ?? [] : [];

        return $item;
    }

    /**
     * Obtener transacciones asociadas a un anticipo.
     */
    public static function get_transacciones_by_anticipo(int $idAnticipo): array
    {
        $sql = '
            SELECT 
                tap.id,
                tap.id_anticipo_proveedor,
                tap.id_valorizacion_compra,
                COALESCE(vc.numero_correlativo, CONCAT("VAL-", tap.id_valorizacion_compra)) AS valorizacion_codigo,
                tap.monto_retirado,
                tap.saldo_actual,
                tap.log_cambios,
                tap.estado,
                tap.created_at
            FROM transaccion_anticipo_proveedor tap
            LEFT JOIN valorizacion_compra vc ON vc.id = tap.id_valorizacion_compra
            WHERE tap.id_anticipo_proveedor = :id_anticipo
            ORDER BY tap.id DESC;
        ';

        $results = DB::select($sql, ['id_anticipo' => $idAnticipo]);

        foreach ($results as $item) {
            $item->id = (int) $item->id;
            $item->id_anticipo_proveedor = (int) $item->id_anticipo_proveedor;
            $item->id_valorizacion_compra = (int) $item->id_valorizacion_compra;
            $item->monto_retirado = (float) $item->monto_retirado;
            $item->saldo_actual = (float) $item->saldo_actual;
            $item->log_cambios = isset($item->log_cambios) ? (is_array($item->log_cambios) ? $item->log_cambios : json_decode($item->log_cambios, true) ?? []) : [];
        }

        return $results;
    }

    /**
     * Obtener historial de cambios unificado (cabecera de anticipo + transacciones).
     */
    public static function get_historial_cambios_combinado(int $idAnticipo): array
    {
        $anticipo = self::get_anticipo_by_id($idAnticipo);
        if (! $anticipo) {
            return [];
        }

        $combined = [];

        // Helper para asegurar que un log tenga la estructura 'cambios' en registros legados
        $normalizarLog = function (array $log, ?object $transContext = null) {
            if (empty($log['cambios']) || ! is_array($log['cambios'])) {
                $cambios = [];
                if (isset($log['saldo_anterior']) || isset($log['saldo_resultante'])) {
                    $sAnt = isset($log['saldo_anterior']) ? '$ '.number_format((float) $log['saldo_anterior'], 2) : '—';
                    $sNue = isset($log['saldo_resultante']) ? '$ '.number_format((float) $log['saldo_resultante'], 2) : '—';
                    $cambios[] = [
                        'campo_bd' => 'saldo_actual',
                        'campo' => 'Saldo Actual',
                        'valor_anterior' => $sAnt,
                        'valor_nuevo' => $sNue,
                    ];
                }

                if (isset($log['monto_retirado']) || ($transContext && isset($transContext->monto_retirado))) {
                    $monto = (float) ($log['monto_retirado'] ?? ($transContext ? $transContext->monto_retirado : 0));
                    $cambios[] = [
                        'campo_bd' => 'monto_retirado',
                        'campo' => 'Monto Retirado',
                        'valor_anterior' => '$ 0.00',
                        'valor_nuevo' => '$ '.number_format($monto, 2),
                    ];
                }

                if ($transContext && isset($transContext->estado)) {
                    $estVal = is_object($transContext->estado) ? $transContext->estado->value : (string) $transContext->estado;
                    $cambios[] = [
                        'campo_bd' => 'estado',
                        'campo' => 'Estado Transacción',
                        'valor_anterior' => '—',
                        'valor_nuevo' => $estVal,
                    ];
                }

                $log['cambios'] = $cambios;
            }

            return $log;
        };

        // 1. Logs de cabecera de anticipo
        $headerLogs = $anticipo->log_cambios ?? [];
        foreach ($headerLogs as $log) {
            if (is_array($log)) {
                $combined[] = $normalizarLog($log);
            }
        }

        // 2. Logs de transacciones asociadas
        $transacciones = self::get_transacciones_by_anticipo($idAnticipo);
        foreach ($transacciones as $trans) {
            $tLogs = $trans->log_cambios ?? [];
            foreach ($tLogs as $log) {
                if (is_array($log)) {
                    $logCopy = $log;
                    if (empty($logCopy['motivo'])) {
                        $montoFmt = number_format($trans->monto_retirado, 2);
                        $logCopy['motivo'] = "Uso de Anticipo en Valorización {$trans->valorizacion_codigo} - Retiro: \$ {$montoFmt}";
                    }
                    $combined[] = $normalizarLog($logCopy, $trans);
                }
            }
        }

        // 3. Ordenar cronológicamente (más recientes primero)
        usort($combined, function ($a, $b) {
            $fechaA = $a['update_at'] ?? ($a['fecha_hora'] ?? ($a['created_at'] ?? ''));
            $fechaB = $b['update_at'] ?? ($b['fecha_hora'] ?? ($b['created_at'] ?? ''));

            return strcmp($fechaB, $fechaA);
        });

        return $combined;
    }
}
