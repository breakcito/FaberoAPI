<?php

namespace App\Modules\ValorizacionVenta\Data;

use App\Models\ValorizacionVenta;
use App\Models\ValorizacionVentaDetalle;
use Illuminate\Support\Facades\DB;

class ValorizacionVentaData
{
    /**
     * Base query para relaciones de ValorizacionVenta
     */
    private static function queryBase()
    {
        return ValorizacionVenta::query()->with([
            'planta:id,ruc,razon_social',
            'empleadoRegistro:id,nombre,apellido',
            'empleadoAprobacion:id,nombre,apellido',
            'empleadoAnulacion:id,nombre,apellido',
            'detalles',
            'detalles.distribucionDetalle',
            'detalles.condicionComercial',
        ]);
    }

    /**
     * Formatear un modelo ValorizacionVenta a arreglo de respuesta
     */
    public static function format_valorizacion(ValorizacionVenta $item): array
    {
        $totalSubtotal = $item->detalles->sum('subtotal');

        // Correlativo persistido en columnas dedicadas (si existen); fallback al derivado del id.
        $correlativoStr = $item->correlativo;
        $numeroCorrelativoStr = $item->numero_correlativo !== null
            ? (string) $item->numero_correlativo
            : (string) $item->id;

        if (empty($correlativoStr)) {
            $anio = $item->created_at ? $item->created_at->format('y') : date('y');
            $correlativoStr = "{$anio}-VV-".str_pad($numeroCorrelativoStr, 5, '0', STR_PAD_LEFT);
        }

        return [
            'id' => $item->id,
            'numero_correlativo' => $numeroCorrelativoStr,
            'correlativo' => $correlativoStr,
            'id_planta' => $item->id_planta,
            'planta_ruc' => $item->planta ? $item->planta->ruc : null,
            'planta_nombre' => $item->planta ? $item->planta->razon_social : null,
            'codigo' => $item->codigo,
            'estado' => $item->estado ? $item->estado->value : null,
            'created_at' => $item->created_at ? $item->created_at->format('Y-m-d H:i:s') : null,
            'fecha_hora_valorizacion' => $item->fecha_hora_valorizacion
                ? ($item->fecha_hora_valorizacion instanceof \DateTimeInterface
                    ? $item->fecha_hora_valorizacion->format('Y-m-d H:i:s')
                    : date('Y-m-d H:i:s', (int) $item->fecha_hora_valorizacion))
                : null,
            'fecha_hora_aprobacion' => $item->fecha_hora_aprobacion
                ? ($item->fecha_hora_aprobacion instanceof \DateTimeInterface
                    ? $item->fecha_hora_aprobacion->format('Y-m-d H:i:s')
                    : date('Y-m-d H:i:s', (int) $item->fecha_hora_aprobacion))
                : null,
            'fecha_hora_anulacion' => $item->fecha_hora_anulacion
                ? ($item->fecha_hora_anulacion instanceof \DateTimeInterface
                    ? $item->fecha_hora_anulacion->format('Y-m-d H:i:s')
                    : date('Y-m-d H:i:s', (int) $item->fecha_hora_anulacion))
                : null,
            'monto_penalidad' => round((float) ($item->monto_penalidad ?? 0), 2),
            'monto_flete' => round((float) ($item->monto_flete ?? 0), 2),
            'empleado_registro' => $item->empleadoRegistro ? trim($item->empleadoRegistro->nombre.' '.$item->empleadoRegistro->apellido) : null,
            'empleado_aprobacion' => $item->empleadoAprobacion ? trim($item->empleadoAprobacion->nombre.' '.$item->empleadoAprobacion->apellido) : null,
            'empleado_anulacion' => $item->empleadoAnulacion ? trim($item->empleadoAnulacion->nombre.' '.$item->empleadoAnulacion->apellido) : null,
            'motivo_anulacion' => $item->motivo_anulacion,
            'evidencias_anulacion' => self::decode_json_field($item->evidencias_anulacion),
            'log_cambios' => self::decode_json_field($item->log_cambios),
            'total_subtotal' => round($totalSubtotal, 2),
            'evidencias' => self::decode_json_field($item->evidencias),
            'detalles' => $item->detalles->map(function (ValorizacionVentaDetalle $d) {
                $ddt = $d->distribucionDetalle;

                $pesoNeto = $ddt && $ddt->peso_neto_cliente !== null ? (float) $ddt->peso_neto_cliente : 0;
                $leyHumedad = $ddt && $ddt->ley_humedad_cliente !== null ? (float) $ddt->ley_humedad_cliente : 0;
                $tms = round($pesoNeto * (1 - ($leyHumedad / 100)), 4);

                $despachoCorrelativo = null;
                $codigoCliente = null;
                $loteCorrelativo = null;
                $blendingCorrelativo = null;

                if ($ddt) {
                    $distRow = DB::table('distribucion as d')
                        ->join('despacho as dsp', 'dsp.id', '=', 'd.id_despacho')
                        ->where('d.id', $ddt->id_distribucion)
                        ->select('d.id_despacho', 'dsp.correlativo as despacho_correlativo')
                        ->first();

                    if ($distRow) {
                        $despachoCorrelativo = $distRow->despacho_correlativo !== null ? (string) $distRow->despacho_correlativo : null;
                    }

                    $despachoDetalle = DB::table('despacho_detalle')->where('id', $ddt->id_despacho_detalle)->first();
                    if ($despachoDetalle) {
                        if ($despachoDetalle->id_lote_mineral) {
                            $lote = DB::table('lote_mineral')->where('id', $despachoDetalle->id_lote_mineral)->first();
                            $loteCorrelativo = $lote ? (string) ($lote->correlativo ?? $lote->numero_correlativo ?? '') : null;
                        }
                        if ($despachoDetalle->id_blending) {
                            $b = DB::table('blending')->where('id', $despachoDetalle->id_blending)->first();
                            $blendingCorrelativo = $b ? (string) ($b->correlativo ?? '') : null;
                        }
                    }

                    $codigoCliente = $ddt->codigo_cliente !== null ? (string) $ddt->codigo_cliente : null;
                }

                return [
                    'id' => $d->id,
                    'id_valorizacion_venta' => $d->id_valorizacion_venta,
                    'id_distribucion_detalle' => $d->id_distribucion_detalle,
                    'id_condicion_comercial' => $d->id_condicion_comercial,
                    'id_valor_elemento_quimico' => $d->id_valor_elemento_quimico,
                    'elemento_quimico' => $d->elemento_quimico ? $d->elemento_quimico->value : null,
                    'numero_correlativo' => null,
                    'despacho_correlativo' => $despachoCorrelativo,
                    'lote_correlativo' => $loteCorrelativo,
                    'blending_correlativo' => $blendingCorrelativo,
                    'codigo_cliente' => $codigoCliente,
                    'tmh' => $pesoNeto,
                    'ley_humedad' => $leyHumedad,
                    'tms' => $tms,
                    'ley' => $ddt ? (float) ($d->elemento_quimico && $d->elemento_quimico->value === 'Oro' ? $ddt->ley_oro_cliente : $ddt->ley_plata_cliente) : 0,
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
        ];
    }

    /**
     * Decodificar campo JSON que puede venir como string, array o null
     */
    private static function decode_json_field(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    /**
     * Obtener listado de valorizaciones
     */
    public static function get_valorizaciones(?int $idPlanta = null): array
    {
        $query = self::queryBase()->orderBy('id', 'desc');

        if ($idPlanta !== null && $idPlanta > 0) {
            $query->where('id_planta', $idPlanta);
        }

        return $query->get()->map(fn (ValorizacionVenta $item) => self::format_valorizacion($item))->toArray();
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

    /**
     * Buscar modelo ValorizacionVenta por ID
     */
    public static function find_model(int $id): ?ValorizacionVenta
    {
        return ValorizacionVenta::find($id);
    }

    /**
     * Buscar una distribucion_detalle con joins mínimos para valorizar (planta, leyes cliente, peso cliente).
     */
    public static function find_distribucion_detalle_con_planta(int $idDistribucionDetalle): ?array
    {
        $row = DB::table('distribucion_detalle as ddt')
            ->join('distribucion as d', 'd.id', '=', 'ddt.id_distribucion')
            ->join('despacho as dsp', 'dsp.id', '=', 'd.id_despacho')
            ->join('despacho_detalle as dd', 'dd.id', '=', 'ddt.id_despacho_detalle')
            ->join('planta_destino as pd', 'pd.id', '=', 'dsp.id_planta_destino')
            ->leftJoin('lote_mineral as lm', 'lm.id', '=', 'dd.id_lote_mineral')
            ->leftJoin('blending as b', 'b.id', '=', 'dd.id_blending')
            ->where('ddt.id', $idDistribucionDetalle)
            ->select([
                'ddt.id',
                'ddt.id_distribucion',
                'ddt.id_despacho_detalle',
                'ddt.numero_particion',
                'ddt.peso_neto_cliente',
                'ddt.ley_oro_cliente',
                'ddt.ley_plata_cliente',
                'ddt.ley_humedad_cliente',
                'ddt.codigo_cliente',
                'ddt.esta_valorizado_oro',
                'ddt.esta_valorizado_plata',
                'd.id_despacho',
                'dsp.correlativo as despacho_correlativo',
                'pd.id as id_planta',
                'pd.razon_social as planta_nombre',
                'lm.correlativo as lote_correlativo',
                'b.correlativo as blending_correlativo',
            ])
            ->first();

        if (! $row) {
            return null;
        }

        $row->despacho_correlativo = $row->despacho_correlativo !== null ? (string) $row->despacho_correlativo : null;
        $row->lote_correlativo = $row->lote_correlativo !== null ? (string) $row->lote_correlativo : null;
        $row->blending_correlativo = $row->blending_correlativo !== null ? (string) $row->blending_correlativo : null;

        return (array) $row;
    }

    /**
     * Eliminar detalles de una valorización por ID
     */
    public static function delete_detalles_by_valorizacion(int $idValorizacion): int
    {
        return ValorizacionVentaDetalle::where('id_valorizacion_venta', $idValorizacion)->delete();
    }

    /**
     * Eliminar modelo ValorizacionVenta
     */
    public static function delete_model(ValorizacionVenta $valorizacion): ?bool
    {
        return $valorizacion->delete();
    }
}
