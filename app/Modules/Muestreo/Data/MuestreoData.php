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
     * Se lista el LOTE PADRE: cuando tiene particiones, estas van englobadas
     * en la fila del padre (columna "Particiones") y se confirman junto con él.
     *
     * Un lote entra al muestreo mientras no se le haya confirmado:
     *  - `con_codigo_temporal = 1` (nace así en TODOS los lotes: significa
     *    "pendiente de muestreo"), o
     *  - `muestreo_confirmado = 0`.
     *
     * Solo se listan los lotes de RECEPCIÓN que ya terminaron de pesar
     * (mismo criterio que el Bloque A del resumen de balanza):
     *  - sin particiones: su propia unidad está 'Pesado' y es 'Recepción de Mineral',
     *  - con particiones: TODAS sus particiones viven en unidades 'Pesado' de
     *    recepción (el padre creado desde balanza puede no tener unidad propia).
     *
     * @param  array{fecha_inicio?: string|null, fecha_fin?: string|null}  $filtros
     * @return array<int, object>
     */
    public static function get_lotes_pendientes(array $filtros): array
    {
        $params = [
            'estado' => EstadoBase::Activo->value,
        ];

        $filtroFechas = '';
        if (! empty($filtros['fecha_inicio'])) {
            $filtroFechas .= ' AND DATE(%columna_fecha%) >= :fecha_inicio';
            $params['fecha_inicio'] = $filtros['fecha_inicio'];
        }
        if (! empty($filtros['fecha_fin'])) {
            $filtroFechas .= ' AND DATE(%columna_fecha%) <= :fecha_fin';
            $params['fecha_fin'] = $filtros['fecha_fin'];
        }
        $filtroFechas = str_replace('%columna_fecha%', 'lm.created_at', $filtroFechas);

        $sql = "
        SELECT
            'LOTE' AS tipo_registro,
            lm.id,
            NULL AS id_particion,
            NULL AS particion,
            lm.correlativo,
            lm.correlativo AS correlativo_padre,
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
            ru.estado_pesaje AS unidad_estado_pesaje,
            ru.tipo_ingreso AS unidad_tipo_ingreso,
            ru.fecha_hora_ingreso,
            v.placa AS vehiculo_placa,
            pr.razon_social AS proveedor_razon_social,
            zo.nombre AS zona_origen_nombre
        FROM lote_mineral lm
        LEFT JOIN recepcion_unidad ru ON ru.id = lm.id_recepcion_unidad
        LEFT JOIN vehiculo v ON v.id = ru.id_vehiculo
        LEFT JOIN proveedor pr ON pr.id = lm.id_proveedor_minero
        LEFT JOIN zona_origen zo ON zo.id = lm.id_zona_origen
        WHERE lm.estado = :estado
          AND (lm.con_codigo_temporal = 1 OR lm.muestreo_confirmado = 0)
        ".$filtroFechas.' ORDER BY lm.created_at DESC';

        $candidatos = DB::select($sql, $params);

        // Particiones activas de cada lote (con el estado de pesaje de SU unidad).
        $particionesPorLote = self::get_particiones_por_lote(
            array_map(static fn ($r) => (int) $r->id, $candidatos)
        );

        $results = [];

        foreach ($candidatos as $lote) {
            $particiones = $particionesPorLote[(int) $lote->id] ?? [];

            if (! self::terminoDePesar($lote, $particiones)) {
                continue;
            }

            $lote->tipo_registro = 'LOTE';
            $lote->id_particion = null;
            $lote->particion = null;
            $lote->con_codigo_temporal = (bool) $lote->con_codigo_temporal;
            $lote->muestreo_confirmado = (bool) $lote->muestreo_confirmado;
            $lote->tiene_particion = (bool) $lote->tiene_particion;
            $lote->particionado_desde_balanza = (bool) $lote->particionado_desde_balanza;
            $lote->numero_correlativo = $lote->numero_correlativo !== null
                ? (int) $lote->numero_correlativo
                : null;
            $lote->peso_inicial = $lote->peso_inicial !== null ? (float) $lote->peso_inicial : null;
            $lote->peso_final = $lote->peso_final !== null ? (float) $lote->peso_final : null;
            $lote->peso_neto = $lote->peso_neto !== null ? (float) $lote->peso_neto : null;
            $lote->particiones = $particiones;
            $lote->total_particiones = count($particiones);
            $lote->particiones_pendientes = count(array_filter(
                $particiones,
                static fn ($p) => ! $p->muestreo_confirmado
            ));

            $results[] = $lote;
        }

        return $results;
    }

    /**
     * Un lote terminó de pesar cuando:
     *  - no tiene particiones: su propia unidad está 'Pesado' y es de recepción,
     *  - tiene particiones: TODAS ellas viven en unidades 'Pesado' de recepción.
     *
     * @param  array<int, object>  $particiones
     */
    private static function terminoDePesar(object $lote, array $particiones): bool
    {
        if (empty($particiones)) {
            return ($lote->unidad_estado_pesaje ?? null) === EstadoPesaje::Pesado->value
                && ($lote->unidad_tipo_ingreso ?? null) === self::TIPO_INGRESO_RECEPCION;
        }

        foreach ($particiones as $particion) {
            if (($particion->unidad_estado_pesaje ?? null) !== EstadoPesaje::Pesado->value) {
                return false;
            }

            if (($particion->unidad_tipo_ingreso ?? null) !== self::TIPO_INGRESO_RECEPCION) {
                return false;
            }
        }

        return true;
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
                plm.id,
                plm.id_lote_mineral,
                plm.particion,
                plm.correlativo,
                plm.peso_inicial,
                plm.peso_neto,
                plm.estado,
                plm.con_codigo_temporal,
                plm.muestreo_confirmado,
                ru.estado_pesaje AS unidad_estado_pesaje,
                ru.tipo_ingreso AS unidad_tipo_ingreso
            FROM particion_lote_mineral plm
            LEFT JOIN recepcion_unidad ru ON ru.id = plm.id_recepcion_unidad
            WHERE plm.id_lote_mineral IN ($placeholders)
              AND plm.estado = ?
            ORDER BY plm.id_lote_mineral, plm.particion
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
