<?php

namespace App\Data;

use App\Shared\Enums\_Generic\EstadoBase;
use Illuminate\Support\Facades\DB;

/**
 * Resolución de las guías (remitente / transportista) que debe mostrar un
 * Ticket de Balanza.
 *
 * Hay dos tramos, con dos orígenes de registro distintos:
 *
 *  - **PRIMER TRAMO** (`guia_primer_tramo`): el del ingreso del mineral a la
 *    planta (proveedor → planta). Es la guía del viaje y se vincula a los lotes
 *    o particiones que se registran en ese viaje mediante `lote_guia`.
 *    Puede registrarse en dos sitios distintos:
 *      1. En el módulo de Guías de Primer Tramo → queda enlazada al lote (o a
 *         una de sus particiones) mediante `lote_guia`.
 *      2. Al registrar la unidad de recepción → queda en
 *         `guia_primer_tramo.id_recepcion_unidad`.
 *    Antes de que exista la guía, la programación deja los números en
 *    `recepcion_unidad.guia_remitente` / `guia_transportista`.
 *
 *  - **SEGUNDO TRAMO** (`guia_segundo_tramo`): el del embarque del mineral
 *    procesado hacia la planta destino. Se registra en el módulo de
 *    Programación de Despachos y se enlaza por distribución
 *    (`guia_segundo_tramo.id_ditribucion`).
 */
class GuiasData
{
    /**
     * Ticket de RECEPCIÓN: las guías salen de la guía de primer tramo donde se
     * registró el lote o la partición.
     *
     * Orden de resolución:
     *   1. Guía de primer tramo enlazada a la partición (`lote_guia`).
     *   2. Guía de primer tramo enlazada al lote padre (`lote_guia`).
     *   3. Guía de primer tramo de la unidad de recepción.
     *   4. Números capturados en la programación (`recepcion_unidad`).
     *
     * @param  object  $item  Fila del ticket (se modifica in situ).
     */
    public static function aplicar_guia_recepcion(
        object $item,
        int $idLote,
        ?int $idParticion = null
    ): void {
        $guia = null;

        if ($idParticion !== null) {
            $guia = self::get_guia_primer_tramo_por_particion($idParticion);
        }
        if ($guia === null) {
            $guia = self::get_guia_primer_tramo_por_lote($idLote);
        }

        if ($guia === null) {
            $idUnidad = self::get_id_recepcion_unidad($idLote, $idParticion);
            if ($idUnidad !== null) {
                $guia = self::get_guia_primer_tramo_por_recepcion($idUnidad);
            }
        }

        if ($guia === null) {
            $idUnidad = self::get_id_recepcion_unidad($idLote, $idParticion);
            if ($idUnidad !== null) {
                $guia = self::get_guia_de_la_recepcion($idUnidad);
            }
        }

        if ($guia === null) {
            $item->guia_remision = null;
            $item->guia_transporte = null;

            return;
        }

        $item->guia_remision = $guia['guia_remitente'];
        $item->guia_transporte = $guia['sin_guia_transportista']
            ? null
            : $guia['guia_transportista'];
    }

    /**
     * Ticket de DESPACHO: las guías salen de la guía de segundo tramo de la
     * distribución (la que acompaña al embarque a la planta destino). Si no
     * hay guía de segundo tramo, se usa la del primer tramo del lote.
     *
     * @param  object  $item  Fila del ticket (se modifica in situ).
     */
    public static function aplicar_guia_despacho(
        object $item,
        int $idLote,
        ?int $idDistribucion = null
    ): void {
        $guia = null;

        if ($idDistribucion !== null) {
            $guia = self::get_guia_segundo_tramo_por_distribucion($idDistribucion);
        }
        if ($guia === null) {
            $guia = self::get_guia_segundo_tramo_por_lote($idLote);
        }
        if ($guia === null) {
            $guia = self::get_guia_primer_tramo_por_lote($idLote);
        }

        if ($guia === null) {
            $item->guia_remision = null;
            $item->guia_transporte = null;

            return;
        }

        $item->guia_remision = $guia['guia_remitente'];
        $item->guia_transporte = $guia['sin_guia_transportista']
            ? null
            : $guia['guia_transportista'];
    }

    /**
     * Unidad de recepción donde se pesó el lote o la partición.
     */
    public static function get_id_recepcion_unidad(
        int $idLote,
        ?int $idParticion = null
    ): ?int {
        if ($idParticion !== null) {
            $id = DB::table('particion_lote_mineral')
                ->where('id', $idParticion)
                ->value('id_recepcion_unidad');

            if ($id !== null) {
                return (int) $id;
            }
        }

        $id = DB::table('lote_mineral')
            ->where('id', $idLote)
            ->value('id_recepcion_unidad');

        return $id !== null ? (int) $id : null;
    }

    /**
     * Guía de primer tramo enlazada a una partición (`lote_guia`).
     *
     * @return array{id: int, guia_remitente: string|null, guia_transportista: string|null, sin_guia_transportista: bool}|null
     */
    public static function get_guia_primer_tramo_por_particion(int $idParticion): ?array
    {
        $row = DB::selectOne(
            'SELECT gui.id AS id,
                    gui.guia_remitente AS guia_remitente,
                    gui.guia_transportista AS guia_transportista,
                    gui.sin_guia_transportista AS sin_guia_transportista
             FROM guia_primer_tramo gui
             INNER JOIN lote_guia lg ON lg.id_guia_primer_tramo = gui.id
             WHERE gui.estado = :estado_activo
               AND lg.id_particion_lote_mineral = :id_particion
             ORDER BY gui.id ASC
             LIMIT 1',
            [
                'estado_activo' => EstadoBase::Activo->value,
                'id_particion' => $idParticion,
            ]
        );

        return $row ? self::map_guia($row) : null;
    }

    /**
     * Guía de primer tramo enlazada a un lote (`lote_guia`).
     *
     * @return array{id: int, guia_remitente: string|null, guia_transportista: string|null, sin_guia_transportista: bool}|null
     */
    public static function get_guia_primer_tramo_por_lote(int $idLote): ?array
    {
        $row = DB::selectOne(
            'SELECT gui.id AS id,
                    gui.guia_remitente AS guia_remitente,
                    gui.guia_transportista AS guia_transportista,
                    gui.sin_guia_transportista AS sin_guia_transportista
             FROM guia_primer_tramo gui
             INNER JOIN lote_guia lg ON lg.id_guia_primer_tramo = gui.id
             WHERE gui.estado = :estado_activo
               AND lg.id_lote_mineral = :id_lote
             ORDER BY gui.id ASC
             LIMIT 1',
            [
                'estado_activo' => EstadoBase::Activo->value,
                'id_lote' => $idLote,
            ]
        );

        return $row ? self::map_guia($row) : null;
    }

    /**
     * Guía de primer tramo registrada en una unidad de recepción.
     *
     * @return array{id: int, guia_remitente: string|null, guia_transportista: string|null, sin_guia_transportista: bool}|null
     */
    public static function get_guia_primer_tramo_por_recepcion(int $idRecepcionUnidad): ?array
    {
        $row = DB::selectOne(
            'SELECT gui.id AS id,
                    gui.guia_remitente AS guia_remitente,
                    gui.guia_transportista AS guia_transportista,
                    gui.sin_guia_transportista AS sin_guia_transportista
             FROM guia_primer_tramo gui
             WHERE gui.estado = :estado_activo
               AND gui.id_recepcion_unidad = :id_recepcion
             ORDER BY gui.id ASC
             LIMIT 1',
            [
                'estado_activo' => EstadoBase::Activo->value,
                'id_recepcion' => $idRecepcionUnidad,
            ]
        );

        return $row ? self::map_guia($row) : null;
    }

    /**
     * Números de guía capturados en la programación de la recepción
     * (`recepcion_unidad`), por si todavía no se registró la guía de primer
     * tramo.
     *
     * @return array{id: int, guia_remitente: string|null, guia_transportista: string|null, sin_guia_transportista: bool}|null
     */
    public static function get_guia_de_la_recepcion(int $idRecepcionUnidad): ?array
    {
        $row = DB::selectOne(
            'SELECT ru.id AS id,
                    ru.guia_remitente AS guia_remitente,
                    ru.guia_transportista AS guia_transportista
             FROM recepcion_unidad ru
             WHERE ru.id = :id_recepcion
             LIMIT 1',
            ['id_recepcion' => $idRecepcionUnidad]
        );

        if (! $row) {
            return null;
        }

        return [
            'id' => (int) $row->id,
            'guia_remitente' => $row->guia_remitente !== null ? (string) $row->guia_remitente : null,
            'guia_transportista' => $row->guia_transportista !== null ? (string) $row->guia_transportista : null,
            'sin_guia_transportista' => false,
        ];
    }

    /**
     * Guía de segundo tramo del último despacho con guía de un lote.
     *
     * @return array{id: int, guia_remitente: string|null, guia_transportista: string|null, sin_guia_transportista: bool}|null
     */
    public static function get_guia_segundo_tramo_por_lote(int $idLote): ?array
    {
        $row = DB::selectOne(
            'SELECT gst.id AS id,
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

        return $row ? self::map_guia($row) : null;
    }

    /**
     * Guía de segundo tramo de una distribución concreta (ticket de despacho).
     *
     * @return array{id: int, guia_remitente: string|null, guia_transportista: string|null, sin_guia_transportista: bool}|null
     */
    public static function get_guia_segundo_tramo_por_distribucion(int $idDistribucion): ?array
    {
        $row = DB::selectOne(
            'SELECT gst.id AS id,
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

        return $row ? self::map_guia($row) : null;
    }

    /**
     * @param  object  $row
     * @return array{id: int, guia_remitente: string|null, guia_transportista: string|null, sin_guia_transportista: bool}
     */
    private static function map_guia(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'guia_remitente' => $row->guia_remitente !== null ? (string) $row->guia_remitente : null,
            'guia_transportista' => $row->guia_transportista !== null ? (string) $row->guia_transportista : null,
            'sin_guia_transportista' => (bool) $row->sin_guia_transportista,
        ];
    }
}
