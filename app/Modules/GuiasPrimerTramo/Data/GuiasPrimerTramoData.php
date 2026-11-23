<?php

namespace App\Modules\GuiasPrimerTramo\Data;

use App\Data\LotesMineralDisponiblesData;
use App\Shared\Enums\_Generic\EstadoBase;
use Illuminate\Support\Facades\DB;

class GuiasPrimerTramoData
{
    /**
     * Obtener listado de guías de primer tramo filtradas por sucursal.
     */
    public static function get_guias(array $filters): array
    {
        $sql = '
        SELECT
            gpt.id,
            gpt.id_sucursal,
            suc.nombre AS sucursal_nombre,
            gpt.id_proveedor,
            p.razon_social AS proveedor_razon_social,
            IFNULL(p.ruc, p.dni) AS proveedor_documento,
            gpt.id_concesion,
            c.nombre AS concesion_nombre,
            gpt.id_conductor,
            CONCAT(cd.nombre, " ", cd.apellido) AS conductor_nombre,
            cd.dni AS conductor_dni,
            cd.numero_licencia AS conductor_licencia,
            gpt.id_vehiculo,
            v.placa AS vehiculo_placa,
            gpt.id_empresa_transporte,
            et.razon_social AS empresa_transporte_razon_social,
            gpt.id_vehiculo_carreta,
            vc.placa AS vehiculo_carreta_placa,
            gpt.id_empresa_transporte_carreta,
            etc.razon_social AS empresa_transporte_carreta_razon_social,
            gpt.motivo_traslado,
            gpt.condicion_ingreso,
            gpt.fecha_inicio_traslado,
            gpt.fecha_emision,
            gpt.fecha_en_planta,
            gpt.guia_remitente,
            gpt.guia_transportista,
            gpt.sin_guia_transportista,
            gpt.documentos,
            gpt.id_recepcion_unidad,
            gpt.con_no_conformidad,
            gpt.peso_guia_remision,
            gpt.peso_vehicular_total,
            gpt.id_empleado_registro,
            gpt.log_cambios,
            gpt.estado,
            gpt.created_at
        FROM guia_primer_tramo gpt
        INNER JOIN sucursal suc ON suc.id = gpt.id_sucursal
        INNER JOIN proveedor p ON p.id = gpt.id_proveedor
        LEFT JOIN concesion c ON c.id = gpt.id_concesion
        INNER JOIN conductor cd ON cd.id = gpt.id_conductor
        INNER JOIN vehiculo v ON v.id = gpt.id_vehiculo
        LEFT JOIN empresa_transporte et ON et.id = gpt.id_empresa_transporte
        LEFT JOIN vehiculo vc ON vc.id = gpt.id_vehiculo_carreta
        LEFT JOIN empresa_transporte etc ON etc.id = gpt.id_empresa_transporte_carreta
        WHERE gpt.id_sucursal = :id_sucursal
        ';

        $params = ['id_sucursal' => (int) $filters['id_sucursal']];

        if (! empty($filters['id_proveedor'])) {
            $sql .= ' AND gpt.id_proveedor = :id_proveedor';
            $params['id_proveedor'] = (int) $filters['id_proveedor'];
        }

        if (! empty($filters['fecha_inicio'])) {
            $sql .= ' AND DATE(gpt.created_at) >= :fecha_inicio';
            $params['fecha_inicio'] = $filters['fecha_inicio'];
        }

        if (! empty($filters['fecha_fin'])) {
            $sql .= ' AND DATE(gpt.created_at) <= :fecha_fin';
            $params['fecha_fin'] = $filters['fecha_fin'];
        }

        if (! empty($filters['guia_remitente'])) {
            $sql .= ' AND gpt.guia_remitente LIKE :guia_remitente';
            $params['guia_remitente'] = '%'.$filters['guia_remitente'].'%';
        }

        $sql .= ' ORDER BY gpt.created_at DESC;';

        $rows = DB::select($sql, $params);

        foreach ($rows as $row) {
            $row->documentos = isset($row->documentos) ? json_decode($row->documentos, true) ?? [] : [];
            $row->log_cambios = isset($row->log_cambios) ? json_decode($row->log_cambios, true) ?? [] : [];
            $row->sin_guia_transportista = (bool) $row->sin_guia_transportista;
            $row->con_no_conformidad = (bool) ($row->con_no_conformidad ?? false);
            $row->peso_guia_remision = $row->peso_guia_remision !== null ? (float) $row->peso_guia_remision : 0.0;
            $row->peso_vehicular_total = $row->peso_vehicular_total !== null ? (float) $row->peso_vehicular_total : 0.0;
            $row->id_recepcion_unidad = $row->id_recepcion_unidad !== null ? (int) $row->id_recepcion_unidad : null;
            $row->id_empleado_registro = $row->id_empleado_registro !== null ? (int) $row->id_empleado_registro : null;
            $row->lotes = self::get_lotes_guia((int) $row->id);
        }

        return $rows;
    }

    /**
     * Obtener una guía por id con todos sus lotes.
     */
    public static function get_guia_by_id(int $id): ?array
    {
        $sql = '
        SELECT
            gpt.*,
            suc.nombre AS sucursal_nombre,
            p.razon_social AS proveedor_razon_social,
            IFNULL(p.ruc, p.dni) AS proveedor_documento,
            c.nombre AS concesion_nombre,
            CONCAT(cd.nombre, " ", cd.apellido) AS conductor_nombre,
            cd.dni AS conductor_dni,
            cd.numero_licencia AS conductor_licencia,
            v.placa AS vehiculo_placa,
            et.razon_social AS empresa_transporte_razon_social,
            vc.placa AS vehiculo_carreta_placa,
            etc.razon_social AS empresa_transporte_carreta_razon_social
        FROM guia_primer_tramo gpt
        INNER JOIN sucursal suc ON suc.id = gpt.id_sucursal
        INNER JOIN proveedor p ON p.id = gpt.id_proveedor
        LEFT JOIN concesion c ON c.id = gpt.id_concesion
        INNER JOIN conductor cd ON cd.id = gpt.id_conductor
        INNER JOIN vehiculo v ON v.id = gpt.id_vehiculo
        LEFT JOIN empresa_transporte et ON et.id = gpt.id_empresa_transporte
        LEFT JOIN vehiculo vc ON vc.id = gpt.id_vehiculo_carreta
        LEFT JOIN empresa_transporte etc ON etc.id = gpt.id_empresa_transporte_carreta
        WHERE gpt.id = :id
        LIMIT 1
        ';

        $row = DB::selectOne($sql, ['id' => $id]);

        if (! $row) {
            return null;
        }

        $row->documentos = isset($row->documentos) ? json_decode($row->documentos, true) ?? [] : [];
        $row->log_cambios = isset($row->log_cambios) ? json_decode($row->log_cambios, true) ?? [] : [];
        $row->sin_guia_transportista = (bool) $row->sin_guia_transportista;
        $row->con_no_conformidad = (bool) ($row->con_no_conformidad ?? false);
        $row->peso_guia_remision = $row->peso_guia_remision !== null ? (float) $row->peso_guia_remision : 0.0;
        $row->peso_vehicular_total = $row->peso_vehicular_total !== null ? (float) $row->peso_vehicular_total : 0.0;
        $row->id_recepcion_unidad = $row->id_recepcion_unidad !== null ? (int) $row->id_recepcion_unidad : null;
        $row->id_empleado_registro = $row->id_empleado_registro !== null ? (int) $row->id_empleado_registro : null;
        $row->lotes = self::get_lotes_guia($id);

        return (array) $row;
    }

    /**
     * Obtener los items (lote o partición) asociados a una guía con shape unificado.
     */
    public static function get_lotes_guia(int $idGuia): array
    {
        $sql = '
        SELECT
            lg.id,
            lg.id_guia_primer_tramo,
            lg.id_lote_mineral,
            lg.id_particion_lote_mineral,
            lg.created_at,
            lg.log_cambios,
            CASE
                WHEN lg.id_particion_lote_mineral IS NOT NULL THEN "PARTICION"
                ELSE "LOTE"
            END AS tipo_item,
            COALESCE(plm.correlativo, lm.correlativo) AS correlativo,
            lm.tipo_producto,
            lm.tipo_mineral,
            COALESCE(lg.peso_inicial, plm.peso_inicial, lm.peso_inicial) AS peso_inicial,
            COALESCE(lg.peso_final, plm.peso_final, lm.peso_final) AS peso_final,
            COALESCE(lg.peso_neto, plm.peso_neto, lm.peso_neto) AS peso_neto,
            COALESCE(plm.peso_inicial, lm.peso_inicial) AS balanza_peso_inicial,
            COALESCE(plm.peso_final, lm.peso_final) AS balanza_peso_final,
            COALESCE(plm.peso_neto, lm.peso_neto) AS balanza_peso_neto
        FROM lote_guia lg
        LEFT JOIN lote_mineral lm ON lm.id = lg.id_lote_mineral
        LEFT JOIN particion_lote_mineral plm ON plm.id = lg.id_particion_lote_mineral
        WHERE lg.id_guia_primer_tramo = :id_guia
        ORDER BY lg.id ASC
        ';

        $rows = DB::select($sql, ['id_guia' => $idGuia]);

        foreach ($rows as $row) {
            $row->peso_inicial = $row->peso_inicial !== null ? (float) $row->peso_inicial : null;
            $row->peso_final = $row->peso_final !== null ? (float) $row->peso_final : null;
            $row->peso_neto = $row->peso_neto !== null ? (float) $row->peso_neto : null;
            $row->balanza_peso_inicial = $row->balanza_peso_inicial !== null ? (float) $row->balanza_peso_inicial : null;
            $row->balanza_peso_final = $row->balanza_peso_final !== null ? (float) $row->balanza_peso_final : null;
            $row->balanza_peso_neto = $row->balanza_peso_neto !== null ? (float) $row->balanza_peso_neto : null;
            $row->log_cambios = isset($row->log_cambios) ? json_decode($row->log_cambios, true) ?? [] : [];
        }

        return $rows;
    }

    /**
     * Unidades (recepciones de unidad) que todavía tienen items de mineral
     * pendientes de asociar a una guía de primer tramo.
     *
     * Se consideran pendientes los lotes sin particionar y las particiones que
     * todavía no están en `lote_guia`.
     *
     * Cada unidad se devuelve UNA sola vez. Para resolver a qué unidad
     * pertenece cada item se usa la UNIDAD RESUELTA
     * (`LotesMineralDisponiblesData::join_unidad_particion_resuelta()`):
     *  - Lote normal: la unidad del propio lote.
     *  - Partición: su propia recepción; si esa recepción es una copia exacta
     *    de la unidad del LOTE PADRE (mismo vehículo y misma fecha de
     *    ingreso), la partición pertenece al viaje del padre y se atribuye a
     *    la unidad real del padre. Si el operador la envió a otro vehículo,
     *    es un viaje propio y se mantiene su unidad.
     *
     * @return array<int, array{id: int, placa: ?string, fecha_hora_ingreso: ?string}>
     */
    public static function get_unidades_disponibles(?int $idSucursal = null): array
    {
        // Rama 1: lotes NORMALES (sin partición) pendientes de guía.
        $sqlLotes = '
        SELECT DISTINCT
            r.id,
            v.placa,
            r.fecha_hora_ingreso
        FROM lote_mineral l
        INNER JOIN recepcion_unidad r ON r.id = l.id_recepcion_unidad
        INNER JOIN vehiculo v ON v.id = r.id_vehiculo
        LEFT JOIN lote_guia g ON g.id_lote_mineral = l.id
        WHERE l.tiene_particion = 0
          AND g.id IS NULL
          AND r.es_recepcion_ficticia = 0
        ';

        // Rama 2: particiones pendientes de guía.
        // La unidad es la UNIDAD RESUELTA de la partición: su propia
        // recepción, salvo que sea una copia exacta de la unidad del lote
        // padre (mismo vehículo y misma fecha de ingreso), en cuyo caso
        // pertenece al viaje del padre.
        // No se filtra `es_recepcion_ficticia = 0`: una partición pesada en
        // OTRO vehículo es un viaje propio y su unidad (ficticia) debe
        // listarse. Las ficticias que son copia exacta del padre se
        // resuelven a la unidad real del padre, así que no duplican placa.
        $sqlParticiones = '
        SELECT DISTINCT
            ru.id,
            v.placa,
            ru.fecha_hora_ingreso
        FROM particion_lote_mineral p
        INNER JOIN lote_mineral lm ON lm.id = p.id_lote_mineral
        '.LotesMineralDisponiblesData::join_unidad_particion_resuelta('p', 'unp').'
        INNER JOIN recepcion_unidad ru ON ru.id = unp.id_unidad_resuelta
        INNER JOIN vehiculo v ON v.id = ru.id_vehiculo
        LEFT JOIN lote_guia g ON g.id_particion_lote_mineral = p.id
        WHERE g.id IS NULL
        ';

        $params = [];
        if ($idSucursal !== null) {
            // Placeholders con nombres distintos en cada rama: PDO no admite
            // repetir el mismo placeholder nombrado dentro de la misma consulta.
            $sqlLotes .= ' AND r.id_sucursal = :id_sucursal_lotes';
            $sqlParticiones .= ' AND ru.id_sucursal = :id_sucursal_particiones';
            $params['id_sucursal_lotes'] = $idSucursal;
            $params['id_sucursal_particiones'] = $idSucursal;
        }

        $sql = 'SELECT * FROM ('.$sqlLotes.' UNION '.$sqlParticiones.') AS u
                ORDER BY u.fecha_hora_ingreso DESC, u.id DESC';

        $rows = DB::select($sql, $params);

        $unidades = [];
        $vistos = [];
        foreach ($rows as $row) {
            $id = (int) $row->id;

            // Salvaguarda: una unidad se lista una sola vez aunque llegue por
            // varias ramas (lote normal + particiones del mismo viaje).
            if (isset($vistos[$id])) {
                continue;
            }
            $vistos[$id] = true;

            $unidades[] = [
                'id' => $id,
                'placa' => $row->placa !== null ? (string) $row->placa : null,
                'fecha_hora_ingreso' => $row->fecha_hora_ingreso !== null ? (string) $row->fecha_hora_ingreso : null,
            ];
        }

        return $unidades;
    }

    /**
     * Proveedores de la carga de una unidad (recepción de unidad) que aún
     * tienen mineral PENDIENTE de guía.
     *
     * Un proveedor al que ya se le hizo la guía de todo su mineral de la
     * unidad no debe volver a ofrecerse: no hay nada que guiar.
     *
     * Fuentes (solo mineral sin guía activa):
     *  - `lote_mineral`: los lotes de la unidad sin partida en `lote_guia`.
     *  - `particion_lote_mineral`: las particiones cuya UNIDAD RESUELTA es la
     *    unidad consultada (misma regla que el catálogo de ítems: su propia
     *    recepción, salvo copia exacta de la del padre → viaje del padre).
     *
     * @return array<int, array{id_proveedor: int, razon_social: string, ruc: ?string}>
     */
    public static function get_proveedores_unidad(int $idUnidad): array
    {
        $estadoGuiaActivo = EstadoBase::Activo->value;

        $sql = '
        SELECT DISTINCT
            p.id AS id_proveedor,
            p.razon_social AS razon_social,
            p.ruc AS ruc
        FROM proveedor p
        WHERE p.id IN (
            SELECT lm.id_proveedor_minero
            FROM lote_mineral lm
            WHERE lm.id_recepcion_unidad = :id_unidad_lotes
              AND lm.id_proveedor_minero IS NOT NULL
              AND NOT EXISTS (
                  SELECT 1
                  FROM lote_guia lg
                  INNER JOIN guia_primer_tramo gpt ON gpt.id = lg.id_guia_primer_tramo
                  WHERE lg.id_lote_mineral = lm.id
                    AND lg.id_particion_lote_mineral IS NULL
                    AND gpt.estado = :estado_guia_activo_lotes
              )

            UNION

            SELECT lm.id_proveedor_minero
            FROM particion_lote_mineral plm
            INNER JOIN lote_mineral lm ON lm.id = plm.id_lote_mineral
            '.LotesMineralDisponiblesData::join_unidad_particion_resuelta('plm', 'unp').'
            WHERE unp.id_unidad_resuelta = :id_unidad_particiones
              AND lm.id_proveedor_minero IS NOT NULL
              AND NOT EXISTS (
                  SELECT 1
                  FROM lote_guia lg
                  INNER JOIN guia_primer_tramo gpt ON gpt.id = lg.id_guia_primer_tramo
                  WHERE lg.id_particion_lote_mineral = plm.id
                    AND gpt.estado = :estado_guia_activo_particiones
              )
        )
        ORDER BY razon_social ASC
        ';

        $rows = DB::select($sql, [
            'id_unidad_lotes' => $idUnidad,
            'id_unidad_particiones' => $idUnidad,
            'estado_guia_activo_lotes' => $estadoGuiaActivo,
            'estado_guia_activo_particiones' => $estadoGuiaActivo,
        ]);

        $proveedores = [];
        foreach ($rows as $row) {
            $proveedores[] = [
                'id_proveedor' => (int) $row->id_proveedor,
                'razon_social' => $row->razon_social !== null ? (string) $row->razon_social : '',
                'ruc' => $row->ruc !== null ? (string) $row->ruc : null,
            ];
        }

        return $proveedores;
    }

    /**
     * Obtener metadatos para los filtros de la página (proveedores, rango fechas disponibles).
     */
    public static function get_filtros_metadata(int $idSucursal): array
    {
        $proveedores = DB::select('
            SELECT DISTINCT p.id, p.razon_social
            FROM guia_primer_tramo gpt
            INNER JOIN proveedor p ON p.id = gpt.id_proveedor
            WHERE gpt.id_sucursal = :id_sucursal
            ORDER BY p.razon_social ASC
        ', ['id_sucursal' => $idSucursal]);

        return [
            'proveedores' => $proveedores,
        ];
    }
}
