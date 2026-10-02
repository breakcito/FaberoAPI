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
            'empresa:id,ruc,razon_social',
            'empleadoRegistro:id,nombre,apellido',
            'empleadoAprobacion:id,nombre,apellido',
            'empleadoAnulacion:id,nombre,apellido',
            'detalles',
            'detalles.despachoDetalle',
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
            'id_empresa' => $item->id_empresa,
            'empresa_ruc' => $item->empresa ? $item->empresa->ruc : null,
            'empresa_nombre' => $item->empresa ? $item->empresa->razon_social : null,
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
                $dd = $d->despachoDetalle;

                $pesoNeto = $dd ? (float) $dd->peso_tomado : 0.0;

                // Promedio de humedad de cliente de las distribuciones detalle hijas
                $leyHumedad = 0.0;
                if ($dd) {
                    $avgHumedad = DB::table('distribucion_detalle')
                        ->where('id_despacho_detalle', $dd->id)
                        ->whereNotNull('ley_humedad_cliente')
                        ->avg('ley_humedad_cliente');
                    $leyHumedad = $avgHumedad !== null ? round((float) $avgHumedad, 3) : 0.0;
                }
                $tms = round($pesoNeto * (1 - ($leyHumedad / 100)), 4);

                $despachoCorrelativo = null;
                $codigoPreliminar = null;
                $loteCorrelativo = null;
                $blendingCorrelativo = null;

                if ($dd) {
                    $dspRow = DB::table('despacho')
                        ->where('id', $dd->id_despacho)
                        ->select('correlativo')
                        ->first();
                    if ($dspRow) {
                        $despachoCorrelativo = (string) $dspRow->correlativo;
                    }
                    if ($dd->id_lote_mineral) {
                        $lote = DB::table('lote_mineral')->where('id', $dd->id_lote_mineral)->first();
                        $loteCorrelativo = $lote ? (string) ($lote->correlativo ?? $lote->numero_correlativo ?? '') : null;
                    }
                    if ($dd->id_blending) {
                        $b = DB::table('blending')->where('id', $dd->id_blending)->first();
                        $blendingCorrelativo = $b ? (string) ($b->correlativo ?? '') : null;
                    }
                    $codigoPreliminar = $dd->codigo_preliminar !== null ? (string) $dd->codigo_preliminar : null;
                    $codigosCliente = DB::table('distribucion_detalle')
                        ->where('id_despacho_detalle', $dd->id)
                        ->whereNotNull('codigo_cliente')
                        ->where('codigo_cliente', '!=', '')
                        ->orderBy('id')
                        ->pluck('codigo_cliente')
                        ->unique()
                        ->implode('.');
                }

                $leyFinal = 0.0;
                if ($dd) {
                    $leyFinal = (float) ($d->elemento_quimico && $d->elemento_quimico->value === 'Oro'
                        ? $dd->ley_oro_final
                        : $dd->ley_plata_final);
                }

                return [
                    'id' => $d->id,
                    'id_valorizacion_venta' => $d->id_valorizacion_venta,
                    'id_despacho_detalle' => $d->id_despacho_detalle,
                    'id_distribucion_detalle' => $d->id_despacho_detalle, // retrocompatibilidad
                    'id_condicion_comercial' => $d->id_condicion_comercial,
                    'id_valor_elemento_quimico' => $d->id_valor_elemento_quimico,
                    'elemento_quimico' => $d->elemento_quimico ? $d->elemento_quimico->value : null,
                    'numero_correlativo' => null,
                    'despacho_correlativo' => $despachoCorrelativo,
                    'lote_correlativo' => $loteCorrelativo,
                    'blending_correlativo' => $blendingCorrelativo,
                    'codigo_preliminar' => $codigoPreliminar,
                    'codigo_cliente' => !empty($codigosCliente) ? $codigosCliente : $codigoPreliminar,
                    'codigos_cliente' => !empty($codigosCliente) ? $codigosCliente : null,
                    'tmh' => $pesoNeto,
                    'ley_humedad' => $leyHumedad,
                    'tms' => $tms,
                    'ley' => $leyFinal,
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
     * Buscar un despacho_detalle con joins mínimos para valorizar (planta, leyes finales, peso tomado, distribuciones).
     */
    public static function find_despacho_detalle_con_planta(int $idDespachoDetalle): ?array
    {
        $row = DB::table('despacho_detalle as dd')
            ->join('despacho as dsp', 'dsp.id', '=', 'dd.id_despacho')
            ->join('planta_destino as pd', 'pd.id', '=', 'dsp.id_planta_destino')
            ->leftJoin('lote_mineral as lm', 'lm.id', '=', 'dd.id_lote_mineral')
            ->leftJoin('blending as b', 'b.id', '=', 'dd.id_blending')
            ->where('dd.id', $idDespachoDetalle)
            ->select([
                'dd.id',
                'dd.id_despacho',
                'dd.peso_tomado',
                'dd.codigo_preliminar',
                'dd.ley_oro_final',
                'dd.ley_plata_final',
                'dd.ley_oro_final_confirmada',
                'dd.ley_plata_final_confirmada',
                'dd.esta_valorizado_oro',
                'dd.esta_valorizado_plata',
                'dsp.correlativo as despacho_correlativo',
                'pd.id as id_planta',
                'pd.razon_social as planta_nombre',
                'lm.correlativo as lote_correlativo',
                'b.correlativo as blending_correlativo',
                DB::raw('(SELECT GROUP_CONCAT(DISTINCT ddt.codigo_cliente ORDER BY ddt.id SEPARATOR \'.\') FROM distribucion_detalle ddt WHERE ddt.id_despacho_detalle = dd.id AND ddt.codigo_cliente IS NOT NULL AND TRIM(ddt.codigo_cliente) != \'\') as codigos_cliente'),
                DB::raw('(SELECT COALESCE(SUM(ddt.peso_tomado), 0) FROM distribucion_detalle ddt WHERE ddt.id_despacho_detalle = dd.id) as peso_distribuido_total'),
                DB::raw('(SELECT AVG(ddt.ley_humedad_cliente) FROM distribucion_detalle ddt WHERE ddt.id_despacho_detalle = dd.id AND ddt.ley_humedad_cliente IS NOT NULL) as ley_humedad_cliente'),
                DB::raw('(SELECT COALESCE(SUM(ddt.peso_neto_cliente), 0) FROM distribucion_detalle ddt WHERE ddt.id_despacho_detalle = dd.id) as peso_neto_cliente_total'),
                DB::raw('(SELECT COUNT(*) FROM distribucion_detalle ddt WHERE ddt.id_despacho_detalle = dd.id) as total_distribuciones'),
                DB::raw('(SELECT COUNT(*) FROM distribucion_detalle ddt INNER JOIN distribucion di ON di.id = ddt.id_distribucion WHERE ddt.id_despacho_detalle = dd.id AND di.fecha_llegada_cliente IS NOT NULL AND ddt.peso_neto_cliente > 0) as total_distribuciones_validas'),
            ])
            ->first();

        if (! $row) {
            return null;
        }

        $row->despacho_correlativo = $row->despacho_correlativo !== null ? (string) $row->despacho_correlativo : null;
        $row->lote_correlativo = $row->lote_correlativo !== null ? (string) $row->lote_correlativo : null;
        $row->blending_correlativo = $row->blending_correlativo !== null ? (string) $row->blending_correlativo : null;
        $row->codigo_cliente = $row->codigos_cliente !== null ? (string) $row->codigos_cliente : null;
        $row->codigos_cliente = $row->codigos_cliente !== null ? (string) $row->codigos_cliente : null;
        $row->peso_neto_cliente = (float) $row->peso_tomado;

        return (array) $row;
    }

    /**
     * Alias de retrocompatibilidad
     */
    public static function find_distribucion_detalle_con_planta(int $idDetalle): ?array
    {
        return self::find_despacho_detalle_con_planta($idDetalle);
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
