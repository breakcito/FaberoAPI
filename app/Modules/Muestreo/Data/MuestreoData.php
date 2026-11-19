<?php

namespace App\Modules\Muestreo\Data;

use App\Shared\Enums\_Generic\EstadoBase;
use App\Shared\Enums\_Generic\EstadoPesaje;
use Illuminate\Support\Facades\DB;

class MuestreoData
{
    /**
     * Tipo de ingreso de las recepciones de mineral (los lotes de despacho
     * se excluyen del módulo de muestreo).
     */
    private const TIPO_INGRESO_RECEPCION = 'Recepción de Mineral';

    /**
     * Lotes pendientes de muestreo.
     *
     * Un lote entra al muestreo cuando:
     *  - su correlativo es temporal (ingresado a mano) -> `con_codigo_temporal = 1`, o
     *  - todavía no se le ha confirmado el muestreo -> `muestreo_confirmado = 0`.
     *
     * Al confirmar el muestreo ambos flags pasan a 0/1, con lo que el lote
     * abandona el listado.
     *
     * Además solo se listan los lotes de RECEPCIÓN que ya terminaron de
     * pesar (mismo criterio que el Bloque A del resumen de balanza):
     *  - deben pertenecer a una recepción de unidad,
     *  - su tipo de ingreso es 'Recepción de Mineral' (excluye despachos),
     *  - la recepción está en estado de pesaje 'Pesado'.
     *
     * @param  array{fecha_inicio?: string|null, fecha_fin?: string|null}  $filtros
     * @return array<int, object>
     */
    public static function get_lotes_pendientes(array $filtros): array
    {
        $sql = '
        SELECT
            lm.id,
            lm.correlativo,
            lm.numero_correlativo,
            lm.tipo_producto,
            lm.tipo_mineral,
            lm.con_codigo_temporal,
            lm.muestreo_confirmado,
            lm.peso_inicial,
            lm.peso_final,
            lm.peso_neto,
            lm.estado,
            lm.tiene_particion,
            lm.particionado_desde_balanza,
            lm.id_recepcion_unidad,
            lm.id_proveedor_minero,
            lm.id_zona_origen,
            lm.created_at,
            ru.fecha_hora_ingreso,
            ru.tipo_ingreso,
            v.placa AS vehiculo_placa,
            pr.razon_social AS proveedor_razon_social,
            zo.nombre AS zona_origen_nombre
        FROM lote_mineral lm
        INNER JOIN recepcion_unidad ru ON ru.id = lm.id_recepcion_unidad
        LEFT JOIN vehiculo v ON v.id = ru.id_vehiculo
        LEFT JOIN proveedor pr ON pr.id = lm.id_proveedor_minero
        LEFT JOIN zona_origen zo ON zo.id = lm.id_zona_origen
        WHERE lm.estado = :estado
          AND (lm.con_codigo_temporal = 1 OR lm.muestreo_confirmado = 0)
          AND ru.estado_pesaje = :estado_pesaje
          AND ru.tipo_ingreso = :tipo_ingreso
        ';

        $params = [
            'estado' => EstadoBase::Activo->value,
            'estado_pesaje' => EstadoPesaje::Pesado->value,
            'tipo_ingreso' => self::TIPO_INGRESO_RECEPCION,
        ];

        if (! empty($filtros['fecha_inicio'])) {
            $sql .= ' AND DATE(lm.created_at) >= :fecha_inicio';
            $params['fecha_inicio'] = $filtros['fecha_inicio'];
        }

        if (! empty($filtros['fecha_fin'])) {
            $sql .= ' AND DATE(lm.created_at) <= :fecha_fin';
            $params['fecha_fin'] = $filtros['fecha_fin'];
        }

        $sql .= ' ORDER BY lm.created_at DESC;';

        $results = DB::select($sql, $params);

        // Particiones activas de cada lote: el modal las lista y se confirman
        // junto con el padre.
        $particionesPorLote = self::get_particiones_por_lote(
            array_map(static fn ($r) => (int) $r->id, $results)
        );

        foreach ($results as $item) {
            $item->con_codigo_temporal = (bool) $item->con_codigo_temporal;
            $item->muestreo_confirmado = (bool) $item->muestreo_confirmado;
            $item->tiene_particion = (bool) $item->tiene_particion;
            $item->particionado_desde_balanza = (bool) $item->particionado_desde_balanza;
            $item->numero_correlativo = $item->numero_correlativo !== null
                ? (int) $item->numero_correlativo
                : null;
            $item->peso_inicial = $item->peso_inicial !== null ? (float) $item->peso_inicial : null;
            $item->peso_final = $item->peso_final !== null ? (float) $item->peso_final : null;
            $item->peso_neto = $item->peso_neto !== null ? (float) $item->peso_neto : null;
            $item->particiones = $particionesPorLote[(int) $item->id] ?? [];
            $item->total_particiones = count($item->particiones);
            $item->particiones_pendientes = count(array_filter(
                $item->particiones,
                static fn ($p) => ! $p->muestreo_confirmado
            ));
        }

        return $results;
    }

    /**
     * Particiones activas de varios lotes, indexadas por id de lote.
     *
     * @param  array<int, int>  $idLotes
     * @return array<int, array<int, object>>
     */
    public static function get_particiones_por_lote(array $idLotes): array
    {
        if (empty($idLotes)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($idLotes), '?'));
        $rows = DB::select(
            "
            SELECT
                id,
                id_lote_mineral,
                particion,
                correlativo,
                peso_inicial,
                peso_neto,
                estado,
                con_codigo_temporal,
                muestreo_confirmado
            FROM particion_lote_mineral
            WHERE id_lote_mineral IN ($placeholders)
              AND estado = ?
            ORDER BY id_lote_mineral, particion
            ",
            [...$idLotes, EstadoBase::Activo->value]
        );

        $out = [];
        foreach ($rows as $row) {
            $row->con_codigo_temporal = (bool) $row->con_codigo_temporal;
            $row->muestreo_confirmado = (bool) $row->muestreo_confirmado;
            $row->peso_inicial = $row->peso_inicial !== null ? (float) $row->peso_inicial : null;
            $row->peso_neto = $row->peso_neto !== null ? (float) $row->peso_neto : null;
            $out[(int) $row->id_lote_mineral][] = $row;
        }

        return $out;
    }

    /**
     * Particiones activas de un lote.
     *
     * @return array<int, object>
     */
    public static function get_particiones_de_lote(int $idLote): array
    {
        return self::get_particiones_por_lote([$idLote])[$idLote] ?? [];
    }

    /**
     * Verificar si un correlativo ya está en uso por otro lote (o por las
     * particiones de otro lote). Evita correlativos duplicados al confirmar
     * el muestreo.
     */
    public static function existe_correlativo(string $correlativo, int $idLoteExcluir): bool
    {
        $lote = DB::table('lote_mineral')
            ->where('correlativo', $correlativo)
            ->where('id', '!=', $idLoteExcluir)
            ->where('estado', '!=', EstadoBase::Eliminado->value)
            ->exists();

        if ($lote) {
            return true;
        }

        return DB::table('particion_lote_mineral')
            ->where('correlativo', $correlativo)
            ->whereNotIn('id_lote_mineral', [$idLoteExcluir])
            ->where('estado', '!=', EstadoBase::Eliminado->value)
            ->exists();
    }
}
