<?php

namespace App\Modules\GuiasPrimerTramo\Data;

use Illuminate\Support\Facades\DB;

class GuiasPrimerTramoHistorialData
{
    /**
     * Devuelve el historial cronológico (DESC) unificando cabecera + lotes para una guía.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function get_historial(int $idGuia): array
    {
        // Cabecera
        $cabecera = DB::select(
            'SELECT
                gph.id,
                gph.id_guia_primer_tramo,
                NULL AS id_lote_guia,
                NULL AS id_lote_mineral,
                gph.accion,
                gph.id_usuario,
                gph.usuario_nombre,
                gph.cambios,
                gph.valores_anteriores,
                gph.valores_nuevos,
                gph.created_at,
                "CABECERA" AS origen
            FROM guia_primer_tramo_historial gph
            WHERE gph.id_guia_primer_tramo = :id_guia',
            ['id_guia' => $idGuia]
        );

        // Lotes
        $lotes = DB::select(
            'SELECT
                lgh.id,
                lgh.id_guia_primer_tramo,
                lgh.id_lote_guia,
                lgh.id_lote_mineral,
                lgh.accion,
                lgh.id_usuario,
                lgh.usuario_nombre,
                lgh.cambios,
                lgh.valores_anteriores,
                lgh.valores_nuevos,
                lgh.created_at,
                "LOTE" AS origen
            FROM lote_guia_historial lgh
            WHERE lgh.id_guia_primer_tramo = :id_guia',
            ['id_guia' => $idGuia]
        );

        $unificado = array_merge($cabecera, $lotes);

        // Resolver correlativos de lote_mineral en una sola query (sin N+1)
        $loteIds = [];
        foreach ($unificado as $row) {
            if ($row->origen === 'LOTE' && $row->id_lote_mineral !== null) {
                $loteIds[(int) $row->id_lote_mineral] = true;
            }
        }
        $lotesById = [];
        if (! empty($loteIds)) {
            $rows = DB::table('lote_mineral')
                ->whereIn('id', array_keys($loteIds))
                ->select(['id', 'correlativo'])
                ->get();
            foreach ($rows as $r) {
                $lotesById[(int) $r->id] = $r->correlativo;
            }
        }

        usort($unificado, function ($a, $b) {
            return strcmp((string) $b->created_at, (string) $a->created_at);
        });

        // Decodificar JSON y añadir lote_correlativo
        foreach ($unificado as $idx => $row) {
            $unificado[$idx] = [
                'id' => (int) $row->id,
                'id_guia_primer_tramo' => (int) $row->id_guia_primer_tramo,
                'id_lote_guia' => $row->id_lote_guia !== null ? (int) $row->id_lote_guia : null,
                'id_lote_mineral' => $row->id_lote_mineral !== null ? (int) $row->id_lote_mineral : null,
                'lote_correlativo' => $row->id_lote_mineral !== null
                    ? ($lotesById[(int) $row->id_lote_mineral] ?? null)
                    : null,
                'origen' => $row->origen,
                'accion' => $row->accion,
                'id_usuario' => (int) $row->id_usuario,
                'usuario_nombre' => $row->usuario_nombre,
                'cambios' => self::decodeJson($row->cambios),
                'valores_anteriores' => self::decodeJson($row->valores_anteriores),
                'valores_nuevos' => self::decodeJson($row->valores_nuevos),
                'created_at' => $row->created_at,
            ];
        }

        return $unificado;
    }

    /**
     * Decodifica JSON a array. Si no se puede, devuelve el valor original.
     *
     * @return mixed
     */
    private static function decodeJson(mixed $value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : $value;
        }

        return $value;
    }
}
