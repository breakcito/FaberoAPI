<?php

namespace App\Data;

use App\Shared\Enums\_Generic\EstadoBase;
use Illuminate\Support\Facades\DB;

/**
 * Resolución de las guías (remitente / transportista) que debe mostrar un
 * Ticket de Balanza.
 *
 * Hay dos tramos y dos puntos de registro distintos:
 *
 *  - **PRIMER TRAMO** (`guia_primer_tramo`): el del ingreso del mineral a la
 *    planta (proveedor → planta). Puede registrarse en dos sitios distintos:
 *      1. Al registrar la unidad de recepción → queda en
 *         `guia_primer_tramo.id_recepcion_unidad`.
 *      2. En el módulo de Guías de Primer Tramo → queda enlazada al lote (o a
 *         una de sus particiones) mediante `lote_guia`.
 *    Un `lote_guia` de partición tiene `id_lote_mineral` NULL y el lote en
 *    `id_particion_lote_mineral`, por lo que hay que cubrir ambos enlistados.
 *
 *  - **SEGUNDO TRAMO** (`guia_segundo_tramo`): el del embarque del mineral
 *    procesado hacia la planta destino. Se registra en el módulo de
 *    Programación de Despachos y se enlaza por distribución
 *    (`guia_segundo_tramo.id_ditribucion`).
 */
class GuiasData
{
    /**
     * Resuelve la guía que corresponde a un ticket de balanza.
     *
     * Prioridad: la del SEGUNDO TRAMO (la que acompaña al embarque cuando el
     * mineral sale de la planta) y, si no existe, la del PRIMER TRAMO.
     *
     * @param  int|null  $idDistribucion  Distribución concreta (ticket de despacho).
     * @return array{guia_remitente: string|null, guia_transportista: string|null, sin_guia_transportista: bool, id_guia: int|null, origen: string|null}
     */
    public static function resolver_guia_ticket(
        int $idLote,
        ?int $idParticion = null,
        ?int $idDistribucion = null
    ): array {
        $segundoTramo = $idDistribucion !== null
            ? self::get_guia_segundo_tramo_por_distribucion($idDistribucion)
            : null;

        if ($segundoTramo === null) {
            $segundoTramo = self::get_guia_segundo_tramo_por_lote($idLote);
        }

        if ($segundoTramo !== null) {
            return [
                'guia_remitente' => $segundoTramo['guia_remitente'],
                'guia_transportista' => $segundoTramo['sin_guia_transportista']
                    ? null
                    : $segundoTramo['guia_transportista'],
                'sin_guia_transportista' => $segundoTramo['sin_guia_transportista'],
                'id_guia' => $segundoTramo['id'],
                'origen' => 'SEGUNDO_TRAMO',
            ];
        }

        $primerTramo = self::get_guia_primer_tramo($idLote, $idParticion);

        if ($primerTramo === null) {
            return [
                'guia_remitente' => null,
                'guia_transportista' => null,
                'sin_guia_transportista' => false,
                'id_guia' => null,
                'origen' => null,
            ];
        }

        return [
            'guia_remitente' => $primerTramo['guia_remitente'],
            'guia_transportista' => $primerTramo['sin_guia_transportista']
                ? null
                : $primerTramo['guia_transportista'],
            'sin_guia_transportista' => $primerTramo['sin_guia_transportista'],
            'id_guia' => $primerTramo['id'],
            'origen' => 'PRIMER_TRAMO',
        ];
    }

    /**
     * Sobrescribe en una fila de ticket las guías (remitente / transportista)
     * con las resueltas por `resolver_guia_ticket`.
     *
     * @param  object  $item  Fila del ticket (se modifica in situ).
     */
    public static function aplicar_guia_ticket(
        object $item,
        int $idLote,
        ?int $idParticion = null,
        ?int $idDistribucion = null
    ): void {
        $guia = self::resolver_guia_ticket($idLote, $idParticion, $idDistribucion);

        $item->guia_remision = $guia['guia_remitente'];
        $item->guia_transporte = $guia['guia_transportista'];
    }

    /**
     * Guía de primer tramo de un lote o de una partición.
     *
     * Cubre los tres orígenes posibles de la guía:
     *   1. Definida al registrar la unidad de recepción (`id_recepcion_unidad`).
     *   2. Enlazada al lote mediante `lote_guia.id_lote_mineral`.
     *   3. Enlazada a una partición mediante `lote_guia.id_particion_lote_mineral`.
     *
     * @return array{id: int, guia_remitente: string|null, guia_transportista: string|null, sin_guia_transportista: bool}|null
     */
    public static function get_guia_primer_tramo(int $idLote, ?int $idParticion = null): ?array
    {
        $row = DB::selectOne(
            'SELECT DISTINCT
                gui.id AS id,
                gui.guia_remitente AS guia_remitente,
                gui.guia_transportista AS guia_transportista,
                gui.sin_guia_transportista AS sin_guia_transportista
             FROM guia_primer_tramo gui
             LEFT JOIN lote_guia lg ON lg.id_guia_primer_tramo = gui.id
             LEFT JOIN lote_mineral lm ON lm.id = lg.id_lote_mineral
             LEFT JOIN particion_lote_mineral plm ON plm.id = lg.id_particion_lote_mineral
             WHERE gui.estado = :estado_activo
               AND (
                    lm.id = :id_lote
                 OR plm.id_lote_mineral = :id_lote_p
                 OR gui.id_recepcion_unidad = COALESCE(
                        (SELECT plm2.id_recepcion_unidad
                           FROM particion_lote_mineral plm2
                          WHERE plm2.id = :id_particion),
                        (SELECT lm2.id_recepcion_unidad
                           FROM lote_mineral lm2
                          WHERE lm2.id = :id_lote_u)
                    )
               )
             ORDER BY gui.id ASC
             LIMIT 1',
            [
                'estado_activo' => EstadoBase::Activo->value,
                'id_lote' => $idLote,
                'id_lote_p' => $idLote,
                'id_lote_u' => $idLote,
                'id_particion' => $idParticion ?? 0,
            ]
        );

        if (! $row) {
            return null;
        }

        return [
            'id' => (int) $row->id,
            'guia_remitente' => $row->guia_remitente !== null ? (string) $row->guia_remitente : null,
            'guia_transportista' => $row->guia_transportista !== null ? (string) $row->guia_transportista : null,
            'sin_guia_transportista' => (bool) $row->sin_guia_transportista,
        ];
    }

    /**
     * Guía de segundo tramo del último despacho con guía del lote.
     *
     * @return array{id: int, guia_remitente: string|null, guia_transportista: string|null, sin_guia_transportista: bool}|null
     */
    public static function get_guia_segundo_tramo_por_lote(int $idLote): ?array
    {
        $row = DB::selectOne(
            'SELECT
                gst.id AS id,
                gst.guia_remitente AS guia_remitente,
                gst.guia_transportista AS guia_transportista,
                gst.sin_guia_transportista AS sin_guia_transportista
             FROM distribucion_detalle ddt
             INNER JOIN despacho_detalle dd ON dd.id = ddt.id_despacho_detalle
             INNER JOIN guia_segundo_tramo gst ON gst.id_ditribucion = ddt.id_distribucion
             WHERE dd.id_lote_mineral = :id_lote
               AND gst.estado <> :estado_eliminado
             ORDER BY gst.created_at DESC
             LIMIT 1',
            [
                'id_lote' => $idLote,
                'estado_eliminado' => EstadoBase::Eliminado->value,
            ]
        );

        return $row ? self::map_guia_segundo_tramo($row) : null;
    }

    /**
     * Guía de segundo tramo de una distribución concreta (ticket de despacho).
     *
     * @return array{id: int, guia_remitente: string|null, guia_transportista: string|null, sin_guia_transportista: bool}|null
     */
    public static function get_guia_segundo_tramo_por_distribucion(int $idDistribucion): ?array
    {
        $row = DB::selectOne(
            'SELECT
                gst.id AS id,
                gst.guia_remitente AS guia_remitente,
                gst.guia_transportista AS guia_transportista,
                gst.sin_guia_transportista AS sin_guia_transportista
             FROM guia_segundo_tramo gst
             WHERE gst.id_ditribucion = :id_distribucion
               AND gst.estado <> :estado_eliminado
             ORDER BY gst.created_at DESC
             LIMIT 1',
            [
                'id_distribucion' => $idDistribucion,
                'estado_eliminado' => EstadoBase::Eliminado->value,
            ]
        );

        return $row ? self::map_guia_segundo_tramo($row) : null;
    }

    /**
     * @param  object  $row
     * @return array{id: int, guia_remitente: string|null, guia_transportista: string|null, sin_guia_transportista: bool}
     */
    private static function map_guia_segundo_tramo(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'guia_remitente' => $row->guia_remitente !== null ? (string) $row->guia_remitente : null,
            'guia_transportista' => $row->guia_transportista !== null ? (string) $row->guia_transportista : null,
            'sin_guia_transportista' => (bool) $row->sin_guia_transportista,
        ];
    }
}
