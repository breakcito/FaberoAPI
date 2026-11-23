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
     * JOIN de la tabla derivada que RESUELVE la unidad (recepción) de cada
     * partición.
     *
     * REGLA DE ATRIBUCIÓN DE UNIDAD (fuente única de verdad):
     *   La unidad de una partición es su PROPIA recepción
     *   (`particion_lote_mineral.id_recepcion_unidad`).
     *   PERO si esa recepción es una COPIA EXACTA de la unidad del lote padre
     *   (mismo `id_vehiculo` y misma `fecha_hora_ingreso`), la partición
     *   pertenece al viaje del padre → se atribuye a
     *   `lote_mineral.id_recepcion_unidad`.
     *
     * Contexto: al partir un lote en "Validación y Distribución" se crea una
     * recepción FICTICIA por partición (`es_recepcion_ficticia = 1`):
     *   - si la partición se pesó en el MISMO vehículo del padre, la ficticia
     *     es una copia exacta (mismo vehículo + misma fecha de ingreso) → es
     *     el mismo viaje → se usa la unidad REAL del padre;
     *   - si el operador la envió a OTRO vehículo, es un viaje distinto → se
     *     mantiene su propia unidad (la ficticia).
     *
     * La derivada expone:
     *   - `id_particion_lote_mineral`: clave de cruce con la partición.
     *   - `id_unidad_resuelta`: `recepcion_unidad.id` ya resuelto.
     *
     * @param  string  $aliasParticion  Alias de `particion_lote_mineral` en la consulta exterior.
     * @param  string  $aliasResultado  Alias de la tabla derivada en la consulta exterior.
     * @param  bool  $leftJoin  `true` para LEFT JOIN (la consulta exterior puede
     *                          traer filas SIN partición: la unidad resuelta
     *                          queda en NULL y no las descarta).
     */
    public static function join_unidad_particion_resuelta(
        string $aliasParticion,
        string $aliasResultado = 'unp',
        bool $leftJoin = false
    ): string {
        $join = $leftJoin ? 'LEFT JOIN' : 'INNER JOIN';

        return '
        '.$join.' (
            SELECT
                plmr.id AS id_particion_lote_mineral,
                CASE
                    WHEN rupr.id IS NOT NULL
                         AND rupr.id_vehiculo = rfur.id_vehiculo
                         AND rupr.fecha_hora_ingreso = rfur.fecha_hora_ingreso
                    THEN rupr.id
                    WHEN rfur.id IS NULL THEN rupr.id
                    ELSE rfur.id
                END AS id_unidad_resuelta
            FROM particion_lote_mineral plmr
            INNER JOIN lote_mineral lmr ON lmr.id = plmr.id_lote_mineral
            LEFT JOIN recepcion_unidad rupr ON rupr.id = lmr.id_recepcion_unidad
            LEFT JOIN recepcion_unidad rfur ON rfur.id = plmr.id_recepcion_unidad
        ) '.$aliasResultado.' ON '.$aliasResultado.'.id_particion_lote_mineral = '.$aliasParticion.'.id
        ';
    }

    /**
     * Proveedores de la CARGA de una unidad (recepción de unidad).
     *
     * La carga de una unidad es el mineral que le pertenece:
     *  - `lote_mineral`: los lotes con `id_recepcion_unidad` = la unidad.
     *  - `particion_lote_mineral`: las particiones cuya UNIDAD RESUELTA es la
     *    unidad consultada (misma regla que el catálogo de ítems: su propia
     *    recepción, salvo que sea una copia exacta de la del LOTE PADRE → el
     *    viaje del padre).
     *
     * NO se exige que el proveedor esté en la tabla puente
     * `proveedor_recepcion`: esa tabla la captura el operador al ingresar la
     * unidad y puede quedar vacía aunque la unidad sí traiga mineral (p.ej.
     * cuando el mineral se pesó o se partió después del ingreso).
     *
     * @return array<int, array{id_proveedor: int, razon_social: string, ruc: ?string}>
     */
    public static function get_proveedores_carga_unidad(int $idUnidad): array
    {
        return self::consultar_proveedores_carga_unidad($idUnidad, false);
    }

    /**
     * Igual que `get_proveedores_carga_unidad()`, pero sólo devuelve los
     * proveedores que AÚN tienen mineral pendiente de guía de primer tramo.
     *
     * @return array<int, array{id_proveedor: int, razon_social: string, ruc: ?string}>
     */
    public static function get_proveedores_carga_unidad_pendientes(int $idUnidad): array
    {
        return self::consultar_proveedores_carga_unidad($idUnidad, true);
    }

    /**
     * Consulta base de los proveedores de la carga de una unidad.
     *
     * @param  bool  $soloMineralPendiente  `true` excluye a los proveedores
     *                                      cuyo mineral ya tiene guía activa.
     * @return array<int, array{id_proveedor: int, razon_social: string, ruc: ?string}>
     */
    private static function consultar_proveedores_carga_unidad(int $idUnidad, bool $soloMineralPendiente): array
    {
        $params = [
            'id_unidad_lotes' => $idUnidad,
            'id_unidad_particiones' => $idUnidad,
        ];

        $filtroPendienteLotes = '';
        $filtroPendienteParticiones = '';

        if ($soloMineralPendiente) {
            $estadoGuiaActivo = EstadoBase::Activo->value;

            $filtroPendienteLotes = '
              AND NOT EXISTS (
                  SELECT 1
                  FROM lote_guia lg
                  INNER JOIN guia_primer_tramo gpt ON gpt.id = lg.id_guia_primer_tramo
                  WHERE lg.id_lote_mineral = lm.id
                    AND lg.id_particion_lote_mineral IS NULL
                    AND gpt.estado = :estado_guia_activo_lotes
              )';

            $filtroPendienteParticiones = '
              AND NOT EXISTS (
                  SELECT 1
                  FROM lote_guia lg
                  INNER JOIN guia_primer_tramo gpt ON gpt.id = lg.id_guia_primer_tramo
                  WHERE lg.id_particion_lote_mineral = plm.id
                    AND gpt.estado = :estado_guia_activo_particiones
              )';

            $params['estado_guia_activo_lotes'] = $estadoGuiaActivo;
            $params['estado_guia_activo_particiones'] = $estadoGuiaActivo;
        }

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
              '.$filtroPendienteLotes.'

            UNION

            SELECT lm.id_proveedor_minero
            FROM particion_lote_mineral plm
            INNER JOIN lote_mineral lm ON lm.id = plm.id_lote_mineral
            '.self::join_unidad_particion_resuelta('plm', 'unp').'
            WHERE unp.id_unidad_resuelta = :id_unidad_particiones
              AND lm.id_proveedor_minero IS NOT NULL
              '.$filtroPendienteParticiones.'
        )
        ORDER BY razon_social ASC
        ';

        $rows = DB::select($sql, $params);

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
     * Obtener items de mineral disponibles según los filtros indicados
     *
     * En la rama de PARTICIONES, la unidad del item es la UNIDAD RESUELTA
     * (`join_unidad_particion_resuelta()`): la propia recepción de la
     * partición, salvo que sea una copia exacta de la unidad del lote padre,
     * en cuyo caso es el viaje del padre. El campo `id_recepcion_unidad`
     * conserva siempre la recepción propia de la partición.
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
            ru.guias AS guias_recepcion,
            ru.fecha_hora_ingreso as fecha_hora_ingreso_vehiculo,
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
            $row->id_recepcion_unidad = $row->id_recepcion_unidad !== null
                ? (int) $row->id_recepcion_unidad
                : null;
            $row->id_recepcion_unidad_padre = $row->id_recepcion_unidad_padre !== null
                ? (int) $row->id_recepcion_unidad_padre
                : null;
            $row->en_guia = (int) $row->en_guia > 0;
            $row->id = (int) $row->id_lote_mineral;
            self::hidratar_guias_recepcion($row);
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
            -- Unidad donde vive la partición (puede ser una "recepción ficticia"
            -- creada al particionar, que no tiene guías).
            plm.id_recepcion_unidad,
            -- Unidad RESUELTA de la partición: el viaje al que realmente
            -- pertenece el mineral. Es su propia recepción, salvo que ésta sea
            -- una copia exacta de la unidad del LOTE PADRE (mismo vehículo y
            -- misma fecha de ingreso), en cuyo caso es el viaje del padre.
            -- Un padre particionado desde balanza tiene id_recepcion_unidad
            -- NULL (la unidad original quedó en su partición A), por eso se
            -- resuelve con la misma regla que el resto del backend.
            unp.id_unidad_resuelta AS id_recepcion_unidad_padre,
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
            ru.guias AS guias_recepcion,
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
        '.self::join_unidad_particion_resuelta('plm', 'unp').'
        -- `ru` es la UNIDAD RESUELTA de la partición: de ella salen el
        -- vehículo, el conductor, la empresa de transporte y las guías del
        -- viaje al que pertenece el mineral.
        INNER JOIN recepcion_unidad ru ON ru.id = unp.id_unidad_resuelta
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
            $row->id_recepcion_unidad = $row->id_recepcion_unidad !== null
                ? (int) $row->id_recepcion_unidad
                : null;
            $row->id_recepcion_unidad_padre = $row->id_recepcion_unidad_padre !== null
                ? (int) $row->id_recepcion_unidad_padre
                : null;
            $row->en_guia = (int) $row->en_guia > 0;
            $row->id = (int) $row->id_particion_lote_mineral;
            self::hidratar_guias_recepcion($row);
            $results[] = $row;
        }

        return $results;
    }

    /**
     * Derivar los datos de guías de la recepción (texto + archivos) desde el
     * array JSON `guias`.
     *
     * La guía que corresponde al item es la del proveedor de la carga
     * (`lote_mineral.id_proveedor_minero`); si no hay entrada para ese
     * proveedor se usa la primera del array.
     */
    private static function hidratar_guias_recepcion(object $row): void
    {
        $guias = RecepcionUnidadesData::normalizar_guias($row->guias_recepcion ?? null);
        $guia = RecepcionUnidadesData::guia_principal_de_json(
            $row->guias_recepcion ?? null,
            $row->id_proveedor_minero !== null ? (int) $row->id_proveedor_minero : null,
        );

        $row->guias_recepcion = $guias;
        $row->guia_remitente_recepcion = ($guia['guia_remitente'] ?? '') !== '' ? $guia['guia_remitente'] : null;
        $row->guia_transportista_recepcion = ($guia['guia_transportista'] ?? '') !== '' ? $guia['guia_transportista'] : null;
        $row->documentos_programacion_recepcion = [
            'guia_remitente' => $guia['guia_remitente_archivo'] ?? null,
            'guia_transportista' => $guia['guia_transportista_archivo'] ?? null,
        ];
    }
}
