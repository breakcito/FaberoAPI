<?php

namespace App\Modules\ValorizacionCompra\Data;

use App\Models\ValorizacionCompra;

class ValorizacionCompraData
{
    /**
     * Base query para relaciones de ValorizacionCompra
     */
    private static function queryBase()
    {
        return ValorizacionCompra::query()->with([
            'proveedor:id,razon_social,ruc',
            'concesion:id,nombre,codigo_reinfo',
            'cuentaBancaria:id,numero_cuenta,moneda,id_banco',
            'cuentaBancaria.banco:id,nombre',
            'cuentaDetraccion:id,numero_cuenta,moneda,id_banco',
            'cuentaDetraccion.banco:id,nombre',
            'empleadoRegistro:id,nombre,apellido',
            'empleadoAprobacion:id,nombre,apellido',
            'detalles',
            'detalles.loteGuia',
            'detalles.loteGuia.loteMineral:id,numero_correlativo,correlativo,ley_humedad,ley_oro,ley_plata,peso_neto',
            'detalles.loteGuia.guiaPrimerTramo:id,serie_guia_remitente,numero_guia_remitente,serie_guia_transportista,numero_guia_transportista,fecha_en_planta',
            'transaccionesAnticipo',
            'transaccionesAnticipo.anticipo:id,serie_factura,numero_factura,saldo_inicial,saldo_actual',
        ]);
    }

    /**
     * Formatear un modelo ValorizacionCompra a arreglo de respuesta
     */
    public static function format_valorizacion(ValorizacionCompra $item): array
    {
        $totalSubtotal = $item->detalles->sum('subtotal');
        $totalAnticipos = $item->transaccionesAnticipo->sum('monto_retirado');
        $montoTransferencia = max(0, $totalSubtotal - $totalAnticipos);

        return [
            'id' => $item->id,
            'numero_correlativo' => $item->numero_correlativo,
            'id_proveedor_minero' => $item->id_proveedor_minero,
            'proveedor_nombre' => $item->proveedor ? $item->proveedor->razon_social : null,
            'proveedor_ruc' => $item->proveedor ? $item->proveedor->ruc : null,
            'id_concesion' => $item->id_concesion,
            'concesion_nombre' => $item->concesion ? $item->concesion->nombre : null,
            'id_cuenta_bancaria' => $item->id_cuenta_bancaria,
            'cuenta_bancaria_info' => $item->cuentaBancaria ? ($item->cuentaBancaria->banco ? $item->cuentaBancaria->banco->nombre : '').' - '.$item->cuentaBancaria->numero_cuenta : null,
            'id_cuenta_detraccion' => $item->id_cuenta_detraccion,
            'cuenta_detraccion_info' => $item->cuentaDetraccion ? $item->cuentaDetraccion->numero_cuenta : null,
            'tipo_pago' => $item->tipo_pago ? $item->tipo_pago->value : null,
            'estado' => $item->estado ? $item->estado->value : null,
            'created_at' => $item->created_at ? $item->created_at->format('Y-m-d H:i:s') : null,
            'fecha_hora_aprobacion' => $item->fecha_hora_aprobacion ? $item->fecha_hora_aprobacion->format('Y-m-d H:i:s') : null,
            'empleado_registro' => $item->empleadoRegistro ? trim($item->empleadoRegistro->nombre.' '.$item->empleadoRegistro->apellido) : null,
            'empleado_aprobacion' => $item->empleadoAprobacion ? trim($item->empleadoAprobacion->nombre.' '.$item->empleadoAprobacion->apellido) : null,
            'total_subtotal' => round($totalSubtotal, 2),
            'total_anticipos' => round($totalAnticipos, 2),
            'monto_transferencia' => round($montoTransferencia, 2),
            'evidencias' => isset($item->evidencias) ? (is_array($item->evidencias) ? $item->evidencias : (json_decode($item->evidencias, true) ?? [])) : [],
            'log_cambios' => $item->log_cambios ?? [],
            'detalles' => $item->detalles->map(function ($d) {
                $lg = $d->loteGuia;
                $lm = $lg ? $lg->loteMineral : null;
                $gpt = $lg ? $lg->guiaPrimerTramo : null;

                $tmh = $lg && $lg->peso_neto !== null ? (float) $lg->peso_neto : ($lm ? (float) $lm->peso_neto : 0);
                $leyHumedad = $lm ? (float) $lm->ley_humedad : 0;
                $tms = round($tmh * (1 - ($leyHumedad / 100)), 4);

                return [
                    'id' => $d->id,
                    'id_valorizacion_compra' => $d->id_valorizacion_compra,
                    'id_lote_guia' => $d->id_lote_guia,
                    'id_condicion_comercial' => $d->id_condicion_comercial,
                    'elemento_quimico' => $d->elemento_quimico ? $d->elemento_quimico->value : null,
                    'codigo_gel' => $lm ? $lm->numero_correlativo : null,
                    'lote_correlativo' => $lm ? $lm->correlativo : null,
                    'grr' => $gpt ? ($gpt->serie_guia_remitente.'-'.$gpt->numero_guia_remitente) : null,
                    'grt' => $gpt ? ($gpt->serie_guia_transportista.'-'.$gpt->numero_guia_transportista) : null,
                    'fecha_ingreso' => $gpt ? ($gpt->fecha_en_planta ? $gpt->fecha_en_planta->format('Y-m-d') : null) : null,
                    'tmh' => $tmh,
                    'ley_humedad' => $leyHumedad,
                    'tms' => $tms,
                    'ley' => $lm ? (float) ($d->elemento_quimico && $d->elemento_quimico->value === 'Oro' ? $lm->ley_oro : $lm->ley_plata) : 0,
                    'inter' => (float) $d->inter,
                    'des_inter' => (float) $d->des_inter,
                    'recuperacion' => (float) $d->recuperacion,
                    'maquila' => (float) $d->maquila,
                    'consumo' => (float) $d->consumo,
                    'factor' => (float) $d->factor,
                    'precio_por_tonelada' => (float) $d->precio_por_tonelada,
                    'subtotal' => (float) $d->subtotal,
                    'log_cambios' => $d->log_cambios ?? [],
                ];
            })->toArray(),
            'transacciones_anticipo' => $item->transaccionesAnticipo->map(function ($t) {
                $ant = $t->anticipo;

                return [
                    'id' => $t->id,
                    'id_anticipo_proveedor' => $t->id_anticipo_proveedor,
                    'id_valorizacion_compra' => $t->id_valorizacion_compra,
                    'factura' => $ant ? ($ant->serie_factura.'-'.$ant->numero_factura) : null,
                    'monto_retirado' => (float) $t->monto_retirado,
                    'saldo_actual' => (float) $t->saldo_actual,
                    'estado' => $t->estado ? $t->estado->value : null,
                    'created_at' => $t->created_at ? $t->created_at->format('Y-m-d H:i:s') : null,
                    'log_cambios' => $t->log_cambios ?? [],
                ];
            })->toArray(),
        ];
    }

    /**
     * Obtener listado de valorizaciones
     */
    public static function get_valorizaciones(?int $idProveedor = null): array
    {
        $query = self::queryBase()->orderBy('id', 'desc');

        if ($idProveedor !== null && $idProveedor > 0) {
            $query->where('id_proveedor_minero', $idProveedor);
        }

        return $query->get()->map(fn (ValorizacionCompra $item) => self::format_valorizacion($item))->toArray();
    }

    /**
     * Obtener valorización individual atómicamente por ID
     */
    public static function get_valorizacion_by_id(int $id): ?array
    {
        $item = self::queryBase()->where('id', $id)->first();
        if (! $item) {
            return null;
        }

        return self::format_valorizacion($item);
    }
}
