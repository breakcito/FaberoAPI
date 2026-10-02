<?php

namespace App\Modules\ContabilidadVenta\Data;

use App\Models\ComprobanteVenta;
use App\Models\DetalleComprobanteVenta;
use App\Models\PagoComprobanteVenta;
use App\Models\TransaccionAnticipoPlanta;
use App\Shared\Enums\ContabilidadVenta\EstadoComprobanteVenta;
use App\Shared\Enums\ContabilidadVenta\MedioPagoComprobanteVenta;
use App\Shared\Enums\ValorizacionCompra\EstadoTransaccionAnticipo;
use App\Shared\Enums\_Generic\EstadoAnticipoProveedor;
use Illuminate\Support\Facades\DB;

class ContabilidadVentaData
{
    /**
     * Listar comprobantes de venta con totales calculados y relaciones.
     *
     * @return array<int,object>
     */
    public static function get_comprobantes(?int $idPlanta = null, ?string $estado = null, ?string $fechaInicio = null, ?string $fechaFin = null): array
    {
        $sql = '
            SELECT
                cv.id,
                cv.id_empresa,
                emp_corp.razon_social AS empresa_nombre,
                emp_corp.ruc AS empresa_ruc,
                cv.id_planta_destino,
                pd.razon_social AS planta_nombre,
                pd.ruc AS planta_ruc,
                cv.id_tipo_cambio,
                tc.fecha AS tipo_cambio_fecha,
                cv.id_empleado_registro,
                cv.id_empleado_anulacion,
                cv.tipo_pago,
                cv.codigo_comprobante,
                cv.codigo_comprobante AS codigo_completo,
                cv.fecha_emision,
                cv.evidencias,
                cv.tipo_cambio_venta,
                cv.percentaje_igv,
                cv.porcentaje_detraccion,
                cv.total_dolares_antes_descuento,
                cv.total_soles_antes_descuento,
                cv.descuento,
                cv.total_dolares,
                cv.total_soles,
                cv.monto_igv_soles,
                cv.monto_pagado_anticipos,
                cv.monto_detraccion,
                cv.monto_detraccion_soles,
                cv.monto_neto,
                cv.avance_pago_neto,
                cv.avance_pago_detraccion,
                cv.created_at,
                cv.estado,
                CONCAT(emp_reg.nombre, " ", emp_reg.apellido) AS empleado_registro_nombre,
                CONCAT(emp_anul.nombre, " ", emp_anul.apellido) AS empleado_anulacion_nombre,
                COALESCE((SELECT SUM(pg.monto_pagado) FROM pago_comprobante_venta pg WHERE pg.id_comprobante_venta = cv.id AND pg.es_anulado = 0 AND pg.es_para_detraccion = 0), 0) AS total_pagado_neto,
                COALESCE((SELECT SUM(pg.monto_pagado) FROM pago_comprobante_venta pg WHERE pg.id_comprobante_venta = cv.id AND pg.es_anulado = 0 AND pg.es_para_detraccion = 1), 0) AS total_pagado_detraccion
            FROM comprobante_venta cv
            INNER JOIN planta_destino pd ON pd.id = cv.id_planta_destino
            INNER JOIN empresa emp_corp ON emp_corp.id = cv.id_empresa
            INNER JOIN tipo_cambio tc ON tc.id = cv.id_tipo_cambio
            INNER JOIN empleado emp_reg ON emp_reg.id = cv.id_empleado_registro
            LEFT JOIN empleado emp_anul ON emp_anul.id = cv.id_empleado_anulacion
            WHERE 1 = 1
        ';

        $params = [];

        if ($idPlanta !== null) {
            $sql .= ' AND cv.id_planta_destino = :id_planta';
            $params['id_planta'] = $idPlanta;
        }

        if ($estado !== null && $estado !== 'Todos') {
            $sql .= ' AND cv.estado = :estado';
            $params['estado'] = $estado;
        }

        if ($fechaInicio !== null) {
            $sql .= ' AND cv.fecha_emision >= :fecha_inicio';
            $params['fecha_inicio'] = $fechaInicio;
        }

        if ($fechaFin !== null) {
            $sql .= ' AND cv.fecha_emision <= :fecha_fin';
            $params['fecha_fin'] = $fechaFin;
        }

        $sql .= ' ORDER BY cv.id DESC';

        $rows = DB::select($sql, $params);

        if (empty($rows)) {
            return [];
        }

        $comprobanteIds = array_map(fn ($r) => (int) $r->id, $rows);

        // Fetch lotes valorizados
        $lotes = self::get_lotes_valorizados_by_comprobantes($comprobanteIds);

        // Fetch transacciones anticipo
        $transacciones = self::get_transacciones_anticipo_by_comprobantes($comprobanteIds);

        foreach ($rows as $r) {
            $id = (int) $r->id;
            $r->evidencias = is_string($r->evidencias) ? json_decode($r->evidencias, true) : ($r->evidencias ?? []);
            $r->lotes_valorizados = $lotes[$id] ?? [];
            $r->transacciones_anticipo = $transacciones[$id] ?? [];
            $r->tipo_cambio_venta = (float) $r->tipo_cambio_venta;
            $r->percentaje_igv = (float) $r->percentaje_igv;
            $r->porcentaje_detraccion = (float) $r->porcentaje_detraccion;
            $r->total_dolares_antes_descuento = (float) $r->total_dolares_antes_descuento;
            $r->total_soles_antes_descuento = (float) $r->total_soles_antes_descuento;
            $r->descuento = (float) $r->descuento;
            $r->total_dolares = (float) $r->total_dolares;
            $r->total_soles = (float) $r->total_soles;
            $r->monto_igv_soles = (float) $r->monto_igv_soles;
            $r->monto_pagado_anticipos = (float) $r->monto_pagado_anticipos;
            $r->monto_detraccion = (float) $r->monto_detraccion;
            $r->monto_detraccion_soles = (float) $r->monto_detraccion_soles;
            $r->monto_neto = (float) $r->monto_neto;
            $r->avance_pago_neto = (float) $r->avance_pago_neto;
            $r->avance_pago_detraccion = (float) $r->avance_pago_detraccion;
            $r->total_pagado_neto = (float) $r->total_pagado_neto;
            $r->total_pagado_detraccion = (float) $r->total_pagado_detraccion;
        }

        return $rows;
    }

    /**
     * Obtener comprobante de venta por ID con todos sus detalles.
     */
    public static function get_comprobante_by_id(int $id): ?object
    {
        $sql = '
            SELECT
                cv.id,
                cv.id_empresa,
                emp_corp.razon_social AS empresa_nombre,
                emp_corp.ruc AS empresa_ruc,
                cv.id_planta_destino,
                pd.razon_social AS planta_nombre,
                pd.ruc AS planta_ruc,
                cv.id_tipo_cambio,
                tc.fecha AS tipo_cambio_fecha,
                cv.id_empleado_registro,
                cv.id_empleado_anulacion,
                cv.tipo_pago,
                cv.codigo_comprobante,
                cv.codigo_comprobante AS codigo_completo,
                cv.fecha_emision,
                cv.evidencias,
                cv.tipo_cambio_venta,
                cv.percentaje_igv,
                cv.porcentaje_detraccion,
                cv.total_dolares_antes_descuento,
                cv.total_soles_antes_descuento,
                cv.descuento,
                cv.total_dolares,
                cv.total_soles,
                cv.monto_igv_soles,
                cv.monto_pagado_anticipos,
                cv.monto_detraccion,
                cv.monto_detraccion_soles,
                cv.monto_neto,
                cv.avance_pago_neto,
                cv.avance_pago_detraccion,
                cv.created_at,
                cv.estado,
                CONCAT(emp_reg.nombre, " ", emp_reg.apellido) AS empleado_registro_nombre,
                CONCAT(emp_anul.nombre, " ", emp_anul.apellido) AS empleado_anulacion_nombre,
                COALESCE((SELECT SUM(pg.monto_pagado) FROM pago_comprobante_venta pg WHERE pg.id_comprobante_venta = cv.id AND pg.es_anulado = 0 AND pg.es_para_detraccion = 0), 0) AS total_pagado_neto,
                COALESCE((SELECT SUM(pg.monto_pagado) FROM pago_comprobante_venta pg WHERE pg.id_comprobante_venta = cv.id AND pg.es_anulado = 0 AND pg.es_para_detraccion = 1), 0) AS total_pagado_detraccion
            FROM comprobante_venta cv
            INNER JOIN planta_destino pd ON pd.id = cv.id_planta_destino
            INNER JOIN empresa emp_corp ON emp_corp.id = cv.id_empresa
            INNER JOIN tipo_cambio tc ON tc.id = cv.id_tipo_cambio
            INNER JOIN empleado emp_reg ON emp_reg.id = cv.id_empleado_registro
            LEFT JOIN empleado emp_anul ON emp_anul.id = cv.id_empleado_anulacion
            WHERE cv.id = :id
            LIMIT 1
        ';

        $rows = DB::select($sql, ['id' => $id]);
        if (empty($rows)) {
            return null;
        }

        $r = $rows[0];
        $r->evidencias = is_string($r->evidencias) ? json_decode($r->evidencias, true) : ($r->evidencias ?? []);

        $lotes = self::get_lotes_valorizados_by_comprobantes([$id]);
        $r->lotes_valorizados = $lotes[$id] ?? [];

        $transacciones = self::get_transacciones_anticipo_by_comprobantes([$id]);
        $r->transacciones_anticipo = $transacciones[$id] ?? [];

        $r->tipo_cambio_venta = (float) $r->tipo_cambio_venta;
        $r->percentaje_igv = (float) $r->percentaje_igv;
        $r->porcentaje_detraccion = (float) $r->porcentaje_detraccion;
        $r->total_dolares_antes_descuento = (float) $r->total_dolares_antes_descuento;
        $r->total_soles_antes_descuento = (float) $r->total_soles_antes_descuento;
        $r->descuento = (float) $r->descuento;
        $r->total_dolares = (float) $r->total_dolares;
        $r->total_soles = (float) $r->total_soles;
        $r->monto_igv_soles = (float) $r->monto_igv_soles;
        $r->monto_pagado_anticipos = (float) $r->monto_pagado_anticipos;
        $r->monto_detraccion = (float) $r->monto_detraccion;
        $r->monto_detraccion_soles = (float) $r->monto_detraccion_soles;
        $r->monto_neto = (float) $r->monto_neto;
        $r->avance_pago_neto = (float) $r->avance_pago_neto;
        $r->avance_pago_detraccion = (float) $r->avance_pago_detraccion;
        $r->total_pagado_neto = (float) $r->total_pagado_neto;
        $r->total_pagado_detraccion = (float) $r->total_pagado_detraccion;

        return $r;
    }

    /**
     * Lotes valorizados agrupados por comprobante.
     *
     * @param  array<int,int>  $comprobanteIds
     * @return array<int,array<int,object>>
     */
    private static function get_lotes_valorizados_by_comprobantes(array $comprobanteIds): array
    {
        if (empty($comprobanteIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($comprobanteIds), '?'));
        $sql = "
            SELECT
                dcv.id,
                dcv.id_comprobante_venta,
                vvd.id AS id_valorizacion_venta_detalle,
                vvd.id_valorizacion_venta,
                vv.correlativo AS valorizacion_correlativo,
                COALESCE(NULLIF(vv.codigo, ''), vv.correlativo) AS valorizacion_codigo,
                vv.codigo AS valorizacion_codigo_personalizado,
                vvd.elemento_quimico,
                vvd.subtotal,
                vvd.precio_por_tonelada,
                dd.lote_correlativo,
                dd.codigo_preliminar,
                dd.despacho_correlativo,
                dd.blending_correlativo,
                dd.codigo_cliente
            FROM detalle_comprobante_venta dcv
            INNER JOIN valorizacion_venta_detalle vvd ON vvd.id = dcv.id_valorizacion_venta_detalle
            INNER JOIN valorizacion_venta vv ON vv.id = vvd.id_valorizacion_venta
            LEFT JOIN (
                SELECT
                    dd_inner.id,
                    dd_inner.codigo_preliminar,
                    dsp.correlativo AS despacho_correlativo,
                    lm.correlativo AS lote_correlativo,
                    bl.correlativo AS blending_correlativo,
                    (SELECT GROUP_CONCAT(DISTINCT dist.codigo_cliente SEPARATOR '.') FROM distribucion_detalle dist WHERE dist.id_despacho_detalle = dd_inner.id AND dist.codigo_cliente IS NOT NULL AND dist.codigo_cliente != '') AS codigo_cliente
                FROM despacho_detalle dd_inner
                LEFT JOIN despacho dsp ON dsp.id = dd_inner.id_despacho
                LEFT JOIN lote_mineral lm ON lm.id = dd_inner.id_lote_mineral
                LEFT JOIN blending bl ON bl.id = dd_inner.id_blending
            ) dd ON dd.id = vvd.id_despacho_detalle
            WHERE dcv.id_comprobante_venta IN ($placeholders)
            ORDER BY dcv.id ASC
        ";

        $rows = DB::select($sql, $comprobanteIds);

        $result = [];
        foreach ($rows as $r) {
            $idCv = (int) $r->id_comprobante_venta;
            $r->subtotal = (float) $r->subtotal;
            $r->precio_por_tonelada = (float) $r->precio_por_tonelada;
            $result[$idCv][] = $r;
        }

        return $result;
    }

    /**
     * Transacciones de anticipo agrupadas por comprobante.
     *
     * @param  array<int,int>  $comprobanteIds
     * @return array<int,array<int,object>>
     */
    private static function get_transacciones_anticipo_by_comprobantes(array $comprobanteIds): array
    {
        if (empty($comprobanteIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($comprobanteIds), '?'));
        $sql = "
            SELECT
                tap.id,
                tap.id_anticipo_planta,
                tap.id_comprobante_venta,
                tap.saldo_actual,
                tap.monto_retirado,
                tap.estado,
                tap.created_at,
                ap.codigo_comprobante AS anticipo_codigo
            FROM transaccion_anticipo_planta tap
            INNER JOIN anticipo_planta ap ON ap.id = tap.id_anticipo_planta
            WHERE tap.id_comprobante_venta IN ($placeholders)
            ORDER BY tap.id ASC
        ";

        $rows = DB::select($sql, $comprobanteIds);

        $result = [];
        foreach ($rows as $r) {
            $idCv = (int) $r->id_comprobante_venta;
            $r->saldo_actual = (float) $r->saldo_actual;
            $r->monto_retirado = (float) $r->monto_retirado;
            $result[$idCv][] = $r;
        }

        return $result;
    }

    /**
     * Obtener detalles de valorización de venta disponibles para comprobante (aprobados y no usados en otro comprobante activo).
     *
     * @return array<int,object>
     */
    public static function get_detalles_valorizacion_disponibles(int $idPlanta): array
    {
        $sql = '
            SELECT
                vvd.id,
                vvd.id AS id_valorizacion_venta_detalle,
                vvd.id_valorizacion_venta,
                vv.correlativo AS valorizacion_correlativo,
                COALESCE(NULLIF(vv.codigo, \'\'), vv.correlativo) AS valorizacion_codigo,
                vv.codigo AS valorizacion_codigo_personalizado,
                vv.fecha_hora_aprobacion,
                vvd.elemento_quimico,
                vvd.subtotal,
                vvd.precio_por_tonelada,
                vvd.factor,
                dd.lote_correlativo,
                dd.codigo_preliminar,
                dd.despacho_correlativo,
                dd.blending_correlativo,
                dd.codigo_cliente
            FROM valorizacion_venta_detalle vvd
            INNER JOIN valorizacion_venta vv ON vv.id = vvd.id_valorizacion_venta
            LEFT JOIN (
                SELECT
                    dd_inner.id,
                    dd_inner.codigo_preliminar,
                    dsp.correlativo AS despacho_correlativo,
                    lm.correlativo AS lote_correlativo,
                    bl.correlativo AS blending_correlativo,
                    (SELECT GROUP_CONCAT(DISTINCT dist.codigo_cliente SEPARATOR ".") FROM distribucion_detalle dist WHERE dist.id_despacho_detalle = dd_inner.id AND dist.codigo_cliente IS NOT NULL AND dist.codigo_cliente != "") AS codigo_cliente
                FROM despacho_detalle dd_inner
                LEFT JOIN despacho dsp ON dsp.id = dd_inner.id_despacho
                LEFT JOIN lote_mineral lm ON lm.id = dd_inner.id_lote_mineral
                LEFT JOIN blending bl ON bl.id = dd_inner.id_blending
            ) dd ON dd.id = vvd.id_despacho_detalle
            WHERE vv.id_planta = :id_planta
              AND vv.estado = "Aprobado"
              AND (vvd.tiene_comprobante = 0 OR vvd.tiene_comprobante IS NULL)
              AND vvd.id NOT IN (
                  SELECT dcv.id_valorizacion_venta_detalle
                  FROM detalle_comprobante_venta dcv
                  INNER JOIN comprobante_venta cv ON cv.id = dcv.id_comprobante_venta
                  WHERE cv.estado != "Anulado"
              )
            ORDER BY vv.fecha_hora_aprobacion DESC, vvd.id ASC
        ';

        $rows = DB::select($sql, ['id_planta' => $idPlanta]);
        foreach ($rows as $r) {
            $r->subtotal = (float) $r->subtotal;
            $r->precio_por_tonelada = (float) $r->precio_por_tonelada;
        }

        return $rows;
    }

    /**
     * Obtener anticipos activos con saldo para una planta destino.
     *
     * @return array<int,object>
     */
    public static function get_anticipos_disponibles(int $idPlanta): array
    {
        $sql = '
            SELECT
                ap.id,
                ap.id_planta,
                ap.codigo_comprobante,
                ap.saldo_inicial,
                ap.saldo_actual,
                ap.estado,
                ap.created_at
            FROM anticipo_planta ap
            WHERE ap.id_planta = :id_planta
              AND ap.saldo_actual > 0
              AND ap.estado = "Con Saldo"
            ORDER BY ap.id ASC
        ';

        $rows = DB::select($sql, ['id_planta' => $idPlanta]);
        foreach ($rows as $r) {
            $r->saldo_inicial = (float) $r->saldo_inicial;
            $r->saldo_actual = (float) $r->saldo_actual;
        }

        return $rows;
    }

    /**
     * Crear comprobante de venta, sus detalles y transacciones de anticipo en BD.
     *
     * @param  array<string,mixed>  $campos
     * @param  array<int,int>  $detallesIds
     * @param  array<int,array{id_anticipo_planta:int,monto_retirado:float}>  $anticiposItems
     */
    public static function crear_comprobante(array $campos, array $detallesIds, array $anticiposItems, int $idEmpleado): int
    {
        $montoNeto = (float) ($campos['monto_neto'] ?? 0);
        $montoDetraccionSoles = (float) ($campos['monto_detraccion_soles'] ?? 0);
        $netoSaldado = $montoNeto <= 0.01;
        $detraccionSaldada = $montoDetraccionSoles <= 0.01;
        $estadoInicial = ($netoSaldado && $detraccionSaldada)
            ? EstadoComprobanteVenta::Pagado->value
            : EstadoComprobanteVenta::EnEspera->value;

        $idComprobante = (int) ComprobanteVenta::insertGetId($campos + [
            'avance_pago_neto' => 0,
            'avance_pago_detraccion' => 0,
            'created_at' => now(),
            'estado' => $estadoInicial,
        ]);

        // Insertar detalles seleccionados y marcar tiene_comprobante
        foreach ($detallesIds as $idDetalle) {
            DetalleComprobanteVenta::create([
                'id_comprobante_venta' => $idComprobante,
                'id_valorizacion_venta_detalle' => (int) $idDetalle,
            ]);
        }
        if (! empty($detallesIds)) {
            DB::table('valorizacion_venta_detalle')
                ->whereIn('id', $detallesIds)
                ->update(['tiene_comprobante' => 1]);
        }

        // Aplicar anticipos si existen
        foreach ($anticiposItems as $item) {
            $idAnticipo = (int) $item['id_anticipo_planta'];
            $montoRetirado = (float) $item['monto_retirado'];
            if ($montoRetirado <= 0) {
                continue;
            }

            $antRow = DB::table('anticipo_planta')->where('id', $idAnticipo)->lockForUpdate()->first();
            if (! $antRow) {
                continue;
            }

            $saldoAnterior = (float) $antRow->saldo_actual;
            $nuevoSaldo = max(round($saldoAnterior - $montoRetirado, 2), 0.0);
            $nuevoEstadoAnticipo = $nuevoSaldo <= 0.001
                ? EstadoAnticipoProveedor::SinSaldo->value
                : EstadoAnticipoProveedor::ConSaldo->value;

            $logTrans = [
                [
                    'id_empleado' => $idEmpleado,
                    'fecha_hora' => now()->toDateTimeString(),
                    'update_at' => now()->toIso8601String(),
                    'accion' => 'Aprobación de Transacción de Anticipo',
                    'motivo' => "Uso de Anticipo en Comprobante Venta {$campos['codigo_comprobante']} - Retiro: \$ ".number_format($montoRetirado, 2),
                    'cambios' => [
                        [
                            'campo_bd' => 'monto_retirado',
                            'campo' => 'Monto Retirado',
                            'valor_anterior' => '$ 0.00',
                            'valor_nuevo' => '$ '.number_format($montoRetirado, 2),
                        ],
                        [
                            'campo_bd' => 'saldo_actual',
                            'campo' => 'Saldo Actual',
                            'valor_anterior' => '$ '.number_format($saldoAnterior, 2),
                            'valor_nuevo' => '$ '.number_format($nuevoSaldo, 2),
                        ],
                        [
                            'campo_bd' => 'estado',
                            'campo' => 'Estado Transacción',
                            'valor_anterior' => 'Pendiente',
                            'valor_nuevo' => EstadoTransaccionAnticipo::Aprobado->value,
                        ],
                    ],
                ],
            ];

            // Crear transaccion anticipo planta
            TransaccionAnticipoPlanta::create([
                'id_anticipo_planta' => $idAnticipo,
                'id_comprobante_venta' => $idComprobante,
                'saldo_actual' => $saldoAnterior,
                'monto_retirado' => $montoRetirado,
                'log_cambios' => $logTrans,
                'created_at' => now(),
                'estado' => EstadoTransaccionAnticipo::Aprobado->value,
            ]);

            // Actualizar saldo_actual y estado en anticipo_planta
            DB::table('anticipo_planta')->where('id', $idAnticipo)->update([
                'saldo_actual' => $nuevoSaldo,
                'estado' => $nuevoEstadoAnticipo,
            ]);
        }

        return $idComprobante;
    }

    /**
     * Anular comprobante de venta revirtiendo anticipos y anulando pagos.
     *
     * @param  array<int,mixed>|null  $evidenciasAnulacion
     */
    public static function anular_comprobante(int $id, int $idEmpleadoAnulacion, string $motivo, ?array $evidenciasAnulacion = null): bool
    {
        $comprobante = ComprobanteVenta::find($id);
        if (! $comprobante) {
            return false;
        }

        // Revertir transacciones de anticipo planta
        $transacciones = TransaccionAnticipoPlanta::where('id_comprobante_venta', $id)->get();
        foreach ($transacciones as $trans) {
            if ($trans->estado?->value === EstadoTransaccionAnticipo::Aprobado->value || $trans->estado === 'Aprobado') {
                $anticipo = DB::table('anticipo_planta')->where('id', $trans->id_anticipo_planta)->lockForUpdate()->first();
                if ($anticipo) {
                    $saldoActual = (float) $anticipo->saldo_actual;
                    $restituido = round($saldoActual + (float) $trans->monto_retirado, 2);
                    DB::table('anticipo_planta')->where('id', $trans->id_anticipo_planta)->update([
                        'saldo_actual' => $restituido,
                        'estado' => EstadoAnticipoProveedor::ConSaldo->value,
                    ]);
                }

                $logTrans = $trans->log_cambios ?? [];
                if (! is_array($logTrans)) {
                    $logTrans = json_decode((string) $logTrans, true) ?? [];
                }
                $logTrans[] = [
                    'id_empleado' => $idEmpleadoAnulacion,
                    'fecha_hora' => now()->toDateTimeString(),
                    'update_at' => now()->toIso8601String(),
                    'accion' => 'Anulación de Transacción de Anticipo',
                    'motivo' => "Transacción revertida por anulación de Comprobante Venta {$comprobante->codigo_comprobante}: {$motivo}",
                    'cambios' => [
                        [
                            'campo_bd' => 'estado',
                            'campo' => 'Estado Transacción',
                            'valor_anterior' => EstadoTransaccionAnticipo::Aprobado->value,
                            'valor_nuevo' => EstadoTransaccionAnticipo::Anulado->value,
                        ],
                    ],
                ];

                $trans->update([
                    'estado' => EstadoTransaccionAnticipo::Anulado->value,
                    'log_cambios' => $logTrans,
                ]);
            }
        }

        // Liberar detalles de valorización de venta (revertir tiene_comprobante)
        $detallesIds = DetalleComprobanteVenta::where('id_comprobante_venta', $id)
            ->pluck('id_valorizacion_venta_detalle')
            ->all();
        if (! empty($detallesIds)) {
            DB::table('valorizacion_venta_detalle')
                ->whereIn('id', $detallesIds)
                ->update(['tiene_comprobante' => 0]);
        }

        // Anular pagos asociados activos
        self::anular_pagos_del_comprobante($id, $idEmpleadoAnulacion, "Anulación en cascada por anulación de comprobante {$comprobante->codigo_comprobante}: {$motivo}");

        // Actualizar comprobante a Anulado
        $comprobante->update([
            'estado' => EstadoComprobanteVenta::Anulado->value,
            'id_empleado_anulacion' => $idEmpleadoAnulacion,
            'avance_pago_neto' => 0,
            'avance_pago_detraccion' => 0,
        ]);

        return true;
    }

    /**
     * Anular en cascada todos los pagos no anulados de un comprobante.
     */
    public static function anular_pagos_del_comprobante(int $idComprobante, int $idEmpleadoAnulacion, string $motivo): int
    {
        $pagos = PagoComprobanteVenta::where('id_comprobante_venta', $idComprobante)
            ->where('es_anulado', 0)
            ->get();

        $afectados = 0;
        foreach ($pagos as $p) {
            $p->update([
                'es_anulado' => 1,
                'id_empleado_anulacion' => $idEmpleadoAnulacion,
                'fecha_hora_anulacion' => now(),
                'motivo_anulacion' => $motivo,
            ]);
            $afectados++;
        }

        return $afectados;
    }

    /**
     * Listar pagos de un comprobante de venta.
     *
     * @return array<int,object>
     */
    public static function get_pagos_by_comprobante(int $idComprobante): array
    {
        $sql = '
            SELECT
                pg.id,
                pg.id_comprobante_venta,
                pg.id_cuenta_bancaria_planta,
                pg.id_cuenta_bancaria_empresa,
                pg.id_empleado_registro,
                pg.id_empleado_anulacion,
                pg.es_para_detraccion,
                pg.medio_pago,
                pg.monto_pagado,
                pg.fecha_hora_pago,
                pg.numero_operacion,
                pg.observacion,
                pg.evidencias,
                pg.created_at,
                pg.es_anulado,
                pg.fecha_hora_anulacion,
                pg.motivo_anulacion,
                pg.evidencias_anulacion,
                cb_planta.numero_cuenta AS cuenta_planta_numero,
                banco_planta.nombre AS banco_planta_nombre,
                cb_emp.numero_cuenta AS cuenta_empresa_numero,
                banco_emp.nombre AS banco_empresa_nombre,
                CONCAT(emp_reg.nombre, " ", emp_reg.apellido) AS empleado_registro_nombre,
                CONCAT(emp_anul.nombre, " ", emp_anul.apellido) AS empleado_anulacion_nombre
            FROM pago_comprobante_venta pg
            LEFT JOIN cuenta_bancaria_planta_destino cb_planta ON cb_planta.id = pg.id_cuenta_bancaria_planta
            LEFT JOIN banco banco_planta ON banco_planta.id = cb_planta.id_banco
            LEFT JOIN cuenta_bancaria_empresa cb_emp ON cb_emp.id = pg.id_cuenta_bancaria_empresa
            LEFT JOIN banco banco_emp ON banco_emp.id = cb_emp.id_banco
            INNER JOIN empleado emp_reg ON emp_reg.id = pg.id_empleado_registro
            LEFT JOIN empleado emp_anul ON emp_anul.id = pg.id_empleado_anulacion
            WHERE pg.id_comprobante_venta = :id_comprobante
            ORDER BY pg.id DESC
        ';

        $rows = DB::select($sql, ['id_comprobante' => $idComprobante]);
        foreach ($rows as $r) {
            $r->monto_pagado = (float) $r->monto_pagado;
            $r->es_para_detraccion = (bool) $r->es_para_detraccion;
            $r->es_anulado = (bool) $r->es_anulado;
            $r->evidencias = is_string($r->evidencias) ? json_decode($r->evidencias, true) : ($r->evidencias ?? []);
            $r->evidencias_anulacion = is_string($r->evidencias_anulacion) ? json_decode($r->evidencias_anulacion, true) : ($r->evidencias_anulacion ?? []);
        }

        return $rows;
    }

    /**
     * Registrar un nuevo pago y actualizar avance en comprobante_venta.
     *
     * @param  array<string,mixed>  $campos
     */
    public static function registrar_pago(array $campos): int
    {
        $idPago = (int) PagoComprobanteVenta::insertGetId($campos + [
            'es_anulado' => 0,
            'created_at' => now(),
        ]);

        self::recalcular_avances_comprobante((int) $campos['id_comprobante_venta']);

        return $idPago;
    }

    /**
     * Anular un pago individual y recalcular avances.
     *
     * @param  array<int,mixed>|null  $evidenciasAnulacion
     */
    public static function anular_pago(int $idPago, int $idEmpleadoAnulacion, string $motivo, ?array $evidenciasAnulacion = null): bool
    {
        $pago = PagoComprobanteVenta::find($idPago);
        if (! $pago || $pago->es_anulado) {
            return false;
        }

        $pago->update([
            'es_anulado' => 1,
            'id_empleado_anulacion' => $idEmpleadoAnulacion,
            'fecha_hora_anulacion' => now(),
            'motivo_anulacion' => $motivo,
            'evidencias_anulacion' => $evidenciasAnulacion,
        ]);

        self::recalcular_avances_comprobante($pago->id_comprobante_venta);

        return true;
    }

    /**
     * Recalcular avance_pago_neto, avance_pago_detraccion y actualizar estado del comprobante.
     */
    public static function recalcular_avances_comprobante(int $idComprobante): void
    {
        $comprobante = ComprobanteVenta::find($idComprobante);
        if (! $comprobante || $comprobante->estado?->value === EstadoComprobanteVenta::Anulado->value) {
            return;
        }

        $avanceNeto = (float) DB::table('pago_comprobante_venta')
            ->where('id_comprobante_venta', $idComprobante)
            ->where('es_anulado', 0)
            ->where('es_para_detraccion', 0)
            ->sum('monto_pagado');

        $avanceDetraccion = (float) DB::table('pago_comprobante_venta')
            ->where('id_comprobante_venta', $idComprobante)
            ->where('es_anulado', 0)
            ->where('es_para_detraccion', 1)
            ->sum('monto_pagado');

        $montoNeto = (float) $comprobante->monto_neto;
        $montoDetraccionSoles = (float) $comprobante->monto_detraccion_soles;

        $netoSaldado = ($montoNeto - $avanceNeto) <= 0.01;
        $detraccionSaldada = ($montoDetraccionSoles - $avanceDetraccion) <= 0.01;

        if ($netoSaldado && $detraccionSaldada) {
            $nuevoEstado = EstadoComprobanteVenta::Pagado;
        } elseif ($avanceNeto > 0.001 || $avanceDetraccion > 0.001) {
            $nuevoEstado = EstadoComprobanteVenta::EnProceso;
        } else {
            $nuevoEstado = EstadoComprobanteVenta::EnEspera;
        }

        $comprobante->update([
            'avance_pago_neto' => round($avanceNeto, 2),
            'avance_pago_detraccion' => round($avanceDetraccion, 2),
            'estado' => $nuevoEstado->value,
        ]);
    }
}
