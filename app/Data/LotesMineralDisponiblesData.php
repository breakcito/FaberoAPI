<?php

namespace App\Data;

use App\Modules\RecepcionUnidades\Data\RecepcionUnidadesData;
use App\Shared\Enums\_Generic\EstadoBase;
use App\Shared\Enums\_Generic\EstadoPesaje;
use Illuminate\Support\Facades\DB;

/**
 * Catálogo de items de mineral disponibles (lotes enteros sin particiones +
 * particiones) para seleccionar en una guía de primer tramo.
 *
 * Devuelve un shape unificado {tipo_item: 'LOTE'|'PARTICION', id_lote_mineral,
 * id_particion_lote_mineral, ...}.
 */
class LotesMineralDisponiblesData
{
    /**
     * Obtener items de mineral disponibles según los filtros indicados
     *
     * @param  string  $fechaInicio  YYYY-MM-DD
     * @param  string  $fechaFin  YYYY-MM-DD
     * @return array<int, object>
     */
    public static function get_lotes_mineral_disponibles(
        ?int $idSucursal,
        ?int $idProveedor,
        ?string $fechaInicio,
        ?string $fechaFin
    ): array {
        $estadoPesaje = EstadoPesaje::Pesado->value;
        $estadoGuiaActivo = EstadoBase::Activo->value;

        $results = [];

        // 1) Lotes enteros sin particiones y sin asignar a una guía activa.
        $sqlLotes = '
        SELECT
            "LOTE" AS tipo_item,
            lm.id AS id_lote_mineral,
            NULL AS id_particion_lote_mineral,
            lm.id_recepcion_unidad,
            lm.id_recepcion_unidad AS id_recepcion_unidad_padre,
            lm.id_proveedor_minero,
            lm.correlativo,
            lm.numero_correlativo,
            lm.tipo_producto,
            lm.tipo_mineral,
            lm.peso_inicial,
            lm.peso_final,
            lm.peso_neto,
            lm.created_at,
            p.razon_social AS proveedor_nombre,
            v.placa AS vehiculo_placa,
            ru.id_vehiculo,
            ru.id_vehiculo_carreta,
            vc.placa AS vehiculo_carreta_placa,
            ru.id_conductor,
            ru.id_empresa_transporte,
            ru.guia_remitente AS guia_remitente_recepcion,
            ru.fecha_hora_ingreso as fecha_hora_ingreso_vehiculo,
            ru.guia_transportista AS guia_transportista_recepcion,
            ru.documentos_programacion AS documentos_programacion_recepcion,
            (
                SELECT COUNT(*)
                FROM lote_guia lg
                INNER JOIN guia_primer_tramo gpt ON gpt.id = lg.id_guia_primer_tramo
                WHERE lg.id_lote_mineral = lm.id
                  AND lg.id_particion_lote_mineral IS NULL
                  AND gpt.estado = :estado_guia_activo
            ) AS en_guia
        FROM lote_mineral lm
        INNER JOIN recepcion_unidad ru ON ru.id = lm.id_recepcion_unidad
        INNER JOIN vehiculo v ON v.id = ru.id_vehiculo
        LEFT JOIN vehiculo vc ON vc.id = ru.id_vehiculo_carreta
        LEFT JOIN proveedor p ON p.id = lm.id_proveedor_minero
        WHERE lm.peso_inicial IS NOT NULL
          AND lm.peso_final IS NOT NULL
          AND lm.peso_neto > 0
          AND lm.tiene_particion = 0
          AND lm.esta_validado = 1
          AND (lm.estado IS NULL OR lm.estado <> :estado_lote_no_eliminado)
          AND ru.estado_pesaje = :estado_pesaje
        ';

        $params = [
            'estado_pesaje' => $estadoPesaje,
            'estado_guia_activo' => $estadoGuiaActivo,
            'estado_lote_no_eliminado' => EstadoBase::Eliminado->value,
        ];

        if ($idSucursal !== null) {
            $sqlLotes .= ' AND ru.id_sucursal = :id_sucursal';
            $params['id_sucursal'] = $idSucursal;
        }

        if ($idProveedor !== null) {
            $sqlLotes .= ' AND lm.id_proveedor_minero = :id_proveedor';
            $params['id_proveedor'] = $idProveedor;
        }

        if ($fechaInicio !== null && $fechaFin !== null) {
            $sqlLotes .= ' AND DATE(ru.fecha_hora_ingreso) BETWEEN :fecha_inicio AND :fecha_fin';
            $params['fecha_inicio'] = $fechaInicio;
            $params['fecha_fin'] = $fechaFin;
        }

        $sqlLotes .= ' ORDER BY lm.correlativo ASC;';

        foreach (DB::select($sqlLotes, $params) as $row) {
            $row->id_lote_mineral = (int) $row->id_lote_mineral;
            $row->id_particion_lote_mineral = null;
            $row->peso_inicial = $row->peso_inicial !== null ? (float) $row->peso_inicial : null;
            $row->peso_final = $row->peso_final !== null ? (float) $row->peso_final : null;
            $row->peso_neto = $row->peso_neto !== null ? (float) $row->peso_neto : null;
            $row->id_recepcion_unidad_padre = $row->id_recepcion_unidad_padre !== null
                ? (int) $row->id_recepcion_unidad_padre
                : null;
            $row->en_guia = (int) $row->en_guia > 0;
            $row->id = (int) $row->id_lote_mineral;
            $row->documentos_programacion_recepcion = RecepcionUnidadesData::normalizar_documentos_programacion(
                $row->documentos_programacion_recepcion ?? null,
            );
            $row->guia_remitente_recepcion = $row->guia_remitente_recepcion !== null ? (string) $row->guia_remitente_recepcion : null;
            $row->guia_transportista_recepcion = $row->guia_transportista_recepcion !== null ? (string) $row->guia_transportista_recepcion : null;
            $results[] = $row;
        }

        // 2) Particiones de lotes, sin asignar a una guía activa.
        // Solo se listan particiones cuando la suma de las particiones con peso_neto>0
        // del lote padre coincide con el peso_neto del lote padre.
        $sqlParticiones = '
        SELECT
            "PARTICION" AS tipo_item,
            plm.id_lote_mineral,
            plm.id AS id_particion_lote_mineral,
            lm.id_recepcion_unidad,
            lm.id_recepcion_unidad AS id_recepcion_unidad_padre,
            lm.id_proveedor_minero,
            plm.correlativo,
            lm.numero_correlativo,
            lm.tipo_producto,
            lm.tipo_mineral,
            plm.peso_inicial,
            plm.peso_final,
            plm.peso_neto,
            plm.fecha_hora_peso_inicial AS created_at,
            p.razon_social AS proveedor_nombre,
            v.placa AS vehiculo_placa,
            ru.id_vehiculo,
            ru.id_vehiculo_carreta,
            vc.placa AS vehiculo_carreta_placa,
            ru.id_conductor,
            ru.id_empresa_transporte,
            ru.guia_remitente AS guia_remitente_recepcion,
            ru.guia_transportista AS guia_transportista_recepcion,
            ru.documentos_programacion AS documentos_programacion_recepcion,
            ru.fecha_hora_ingreso as fecha_hora_ingreso_vehiculo,
            (
                SELECT COUNT(*)
                FROM lote_guia lg
                INNER JOIN guia_primer_tramo gpt ON gpt.id = lg.id_guia_primer_tramo
                WHERE lg.id_particion_lote_mineral = plm.id
                  AND gpt.estado = :estado_guia_activo
            ) AS en_guia
        FROM particion_lote_mineral plm
        INNER JOIN lote_mineral lm ON lm.id = plm.id_lote_mineral
        INNER JOIN recepcion_unidad ru ON ru.id = plm.id_recepcion_unidad
        INNER JOIN vehiculo v ON v.id = ru.id_vehiculo
        LEFT JOIN vehiculo vc ON vc.id = ru.id_vehiculo_carreta
        LEFT JOIN proveedor p ON p.id = lm.id_proveedor_minero
        WHERE (lm.peso_inicial IS NOT NULL OR lm.particionado_desde_balanza = 1)
          AND (lm.peso_final IS NOT NULL OR lm.particionado_desde_balanza = 1)
          AND plm.peso_neto > 0
          AND ru.estado_pesaje = :estado_pesaje
          AND plm.esta_validado = 1
          AND lm.esta_validado = 1
          AND plm.estado = :estado_particion_activo
          AND (lm.estado IS NULL OR lm.estado <> :estado_lote_no_eliminado)
          AND COALESCE((
                SELECT SUM(plm2.peso_neto)
                FROM particion_lote_mineral plm2
                WHERE plm2.id_lote_mineral = lm.id
                  AND plm2.peso_neto > 0
                  AND plm2.estado = :estado_particion_activo_suma
            ), 0) = COALESCE(lm.peso_neto, 0)
        ';

        $params2 = [
            'estado_pesaje' => $estadoPesaje,
            'estado_guia_activo' => $estadoGuiaActivo,
            'estado_particion_activo' => EstadoBase::Activo->value,
            'estado_particion_activo_suma' => EstadoBase::Activo->value,
            'estado_lote_no_eliminado' => EstadoBase::Eliminado->value,
        ];

        if ($idSucursal !== null) {
            $sqlParticiones .= ' AND ru.id_sucursal = :id_sucursal';
            $params2['id_sucursal'] = $idSucursal;
        }

        if ($idProveedor !== null) {
            $sqlParticiones .= ' AND lm.id_proveedor_minero = :id_proveedor';
            $params2['id_proveedor'] = $idProveedor;
        }

        if ($fechaInicio !== null && $fechaFin !== null) {
            $sqlParticiones .= ' AND DATE(ru.fecha_hora_ingreso) BETWEEN :fecha_inicio AND :fecha_fin';
            $params2['fecha_inicio'] = $fechaInicio;
            $params2['fecha_fin'] = $fechaFin;
        }

        $sqlParticiones .= ' ORDER BY plm.correlativo ASC;';

        foreach (DB::select($sqlParticiones, $params2) as $row) {
            $row->id_lote_mineral = (int) $row->id_lote_mineral;
            $row->id_particion_lote_mineral = (int) $row->id_particion_lote_mineral;
            $row->peso_inicial = $row->peso_inicial !== null ? (float) $row->peso_inicial : null;
            $row->peso_final = $row->peso_final !== null ? (float) $row->peso_final : null;
            $row->peso_neto = $row->peso_neto !== null ? (float) $row->peso_neto : null;
            $row->id_recepcion_unidad_padre = $row->id_recepcion_unidad_padre !== null
                ? (int) $row->id_recepcion_unidad_padre
                : null;
            $row->en_guia = (int) $row->en_guia > 0;
            $row->id = (int) $row->id_particion_lote_mineral;
            $row->documentos_programacion_recepcion = RecepcionUnidadesData::normalizar_documentos_programacion(
                $row->documentos_programacion_recepcion ?? null,
            );
            $row->guia_remitente_recepcion = $row->guia_remitente_recepcion !== null ? (string) $row->guia_remitente_recepcion : null;
            $row->guia_transportista_recepcion = $row->guia_transportista_recepcion !== null ? (string) $row->guia_transportista_recepcion : null;
            $results[] = $row;
        }

        return $results;
    }
}
