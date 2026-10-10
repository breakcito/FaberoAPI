<?php

namespace App\Modules\RecepcionUnidades\Data;

use App\Models\LoteMineral;
use App\Models\RecepcionUnidad;
use App\Shared\Enums\_Generic\EstadoBase;
use App\Shared\Enums\_Generic\EstadoPesaje;
use App\Shared\Enums\_Generic\EstadoVisita;
use App\Shared\Enums\_Generic\Periodo;
use App\Shared\Helpers\CorrelativoHelper;
use Illuminate\Support\Facades\DB;

class RecepcionUnidadesData
{
    /**
     * Obtener lista de recepciones de unidades con filtros dinámicos.
     */
    public static function get_recepciones(array $filters = [])
    {
        $sql = '
        SELECT
            ru.id,
            ru.id_empleado_recepcion AS id_empleado_registro,
            CONCAT(emp_reg.nombre, " ", emp_reg.apellido) AS empleado_registro_nombre,
            ru.id_vehiculo,
            v.placa AS vehiculo_placa,
            ru.id_vehiculo_carreta,
            vc.placa AS vehiculo_carreta_placa,
            ru.id_empresa_transporte,
            et.razon_social AS empresa_transporte_razon_social,
            ru.id_tipo_vehiculo,
            tv.nombre AS tipo_vehiculo_nombre,
            ru.id_conductor,
            CONCAT(c.nombre, " ", c.apellido) AS conductor_nombre_completo,
            c.dni AS conductor_dni,
            c.numero_licencia AS conductor_numero_licencia,
            ru.tipo_ingreso,
            ru.fecha_hora_ingreso,
            ru.evidencias,
            ru.observacion,
            ru.log_cambios,
            ru.estado,
            ru.estado_salida,
            ru.fecha_hora_salida,
            ru.observacion_salida,
            ru.estado_pesaje,
            ru.id_proveedor_minero,
            pr.razon_social AS proveedor_razon_social,
            pr.ruc AS proveedor_ruc,
            ru.id_empleado_autoriza,
            CONCAT(emp_aut.nombre, " ", emp_aut.apellido) AS empleado_autoriza_nombre,
            ru.id_empleado_recepcion,
            CONCAT(emp_rec.nombre, " ", emp_rec.apellido) AS empleado_recepcion_nombre,
            ru.es_programacion,
            ru.fecha_estimada_llegada,
            ru.guia_remitente,
            ru.guia_transportista,
            ru.documentos_programacion,
            ru.es_recepcion_ficticia,
            ru.id_ticket_recepcion_unidades,
            tru.correlativo AS ticket_correlativo,
            tru.numero_correlativo AS ticket_numero_correlativo
        FROM
            recepcion_unidad ru
        LEFT JOIN ticket_recepcion_unidades tru ON tru.id = ru.id_ticket_recepcion_unidades
        LEFT JOIN empleado emp_reg ON emp_reg.id = ru.id_empleado_recepcion
        LEFT JOIN vehiculo v ON v.id = ru.id_vehiculo
        LEFT JOIN vehiculo vc ON vc.id = ru.id_vehiculo_carreta
        INNER JOIN empresa_transporte et ON et.id = ru.id_empresa_transporte
        LEFT JOIN tipo_vehiculo tv ON tv.id = ru.id_tipo_vehiculo
        LEFT JOIN conductor c ON c.id = ru.id_conductor
        LEFT JOIN proveedor pr ON pr.id = ru.id_proveedor_minero
        LEFT JOIN empleado emp_aut ON emp_aut.id = ru.id_empleado_autoriza
        LEFT JOIN empleado emp_rec ON emp_rec.id = ru.id_empleado_recepcion
        WHERE 1 = 1
            AND ru.es_recepcion_ficticia = 0
        ';

        $params = [];

        // Filtro por fecha:
        // A las recepciones programadas sin confirmar (es_programacion = 1 AND fecha_hora_ingreso IS NULL) NO les afecta el filtro de fecha.
        // A las confirmadas (fecha_hora_ingreso IS NOT NULL) y recepciones directas SÍ les afecta el filtro de fecha.
        if (! empty($filters['fecha_inicio'])) {
            $sql .= ' AND ((ru.es_programacion = 1 AND ru.fecha_hora_ingreso IS NULL) OR COALESCE(ru.fecha_hora_ingreso, ru.created_at) >= :fecha_inicio)';
            $params['fecha_inicio'] = $filters['fecha_inicio'].' 00:00:00';
        }

        if (! empty($filters['fecha_fin'])) {
            $sql .= ' AND ((ru.es_programacion = 1 AND ru.fecha_hora_ingreso IS NULL) OR COALESCE(ru.fecha_hora_ingreso, ru.created_at) <= :fecha_fin)';
            $params['fecha_fin'] = $filters['fecha_fin'].' 23:59:59';
        }

        // Filtro por placa
        if (! empty($filters['placa'])) {
            $sql .= ' AND v.placa LIKE :placa';
            $params['placa'] = '%'.$filters['placa'].'%';
        }

        // Filtro por transportista (empresa de transporte)
        if (! empty($filters['id_empresa_transporte'])) {
            $sql .= ' AND ru.id_empresa_transporte = :id_empresa_transporte';
            $params['id_empresa_transporte'] = (int) $filters['id_empresa_transporte'];
        }

        // Filtro por condición de ingreso (tipo_ingreso)
        if (! empty($filters['tipo_ingreso'])) {
            $sql .= ' AND ru.tipo_ingreso = :tipo_ingreso';
            $params['tipo_ingreso'] = $filters['tipo_ingreso'];
        }

        $sql .= ' ORDER BY COALESCE(ru.fecha_hora_ingreso, ru.fecha_estimada_llegada, ru.created_at) DESC;';

        $results = DB::select($sql, $params);

        // Decodificar la columna JSON de evidencias manualmente para que coincida con lo esperado por Eloquent
        foreach ($results as $item) {
            if (isset($item->evidencias)) {
                $item->evidencias = self::normalizar_evidencias($item->evidencias);
            }
            if (isset($item->log_cambios)) {
                $item->log_cambios = isset($item->log_cambios) ? (is_array($item->log_cambios) ? $item->log_cambios : (json_decode($item->log_cambios, true) ?? [])) : [];
            }
            if (isset($item->documentos_programacion)) {
                $item->documentos_programacion = self::normalizar_documentos_programacion($item->documentos_programacion);
            }
        }

        return $results;
    }

    /**
     * Obtener recepción específica por su ID.
     */
    public static function get_recepcion_by_id(int $id)
    {
        $sql = '
        SELECT
            ru.id,
            ru.id_empleado_recepcion AS id_empleado_registro,
            CONCAT(emp_reg.nombre, " ", emp_reg.apellido) AS empleado_registro_nombre,
            ru.id_vehiculo,
            v.placa AS vehiculo_placa,
            ru.id_vehiculo_carreta,
            vc.placa AS vehiculo_carreta_placa,
            ru.id_empresa_transporte,
            et.razon_social AS empresa_transporte_razon_social,
            ru.id_tipo_vehiculo,
            tv.nombre AS tipo_vehiculo_nombre,
            ru.id_conductor,
            CONCAT(c.nombre, " ", c.apellido) AS conductor_nombre_completo,
            c.dni AS conductor_dni,
            c.numero_licencia AS conductor_numero_licencia,
            ru.tipo_ingreso,
            ru.fecha_hora_ingreso,
            ru.evidencias,
            ru.observacion,
            ru.log_cambios,
            ru.estado,
            ru.estado_salida,
            ru.fecha_hora_salida,
            ru.observacion_salida,
            ru.id_sucursal AS id_sucursal,
            ru.fecha_hora_inicio_pesaje,
            ru.fecha_hora_final_pesaje,
            ru.estado_pesaje,
            ru.id_proveedor_minero,
            pr.razon_social AS proveedor_razon_social,
            pr.ruc AS proveedor_ruc,
            ru.id_empleado_autoriza,
            CONCAT(emp_aut.nombre, " ", emp_aut.apellido) AS empleado_autoriza_nombre,
            ru.id_empleado_recepcion,
            CONCAT(emp_rec.nombre, " ", emp_rec.apellido) AS empleado_recepcion_nombre,
            ru.es_programacion,
            ru.fecha_estimada_llegada,
            ru.guia_remitente,
            ru.guia_transportista,
            ru.es_recepcion_ficticia,
            ru.id_ticket_recepcion_unidades,
            tru.correlativo AS ticket_correlativo,
            tru.numero_correlativo AS ticket_numero_correlativo
        FROM
            recepcion_unidad ru
        LEFT JOIN ticket_recepcion_unidades tru ON tru.id = ru.id_ticket_recepcion_unidades
        LEFT JOIN empleado emp_reg ON emp_reg.id = ru.id_empleado_recepcion
        LEFT JOIN vehiculo v ON v.id = ru.id_vehiculo
        LEFT JOIN vehiculo vc ON vc.id = ru.id_vehiculo_carreta
        INNER JOIN empresa_transporte et ON et.id = ru.id_empresa_transporte
        LEFT JOIN tipo_vehiculo tv ON tv.id = ru.id_tipo_vehiculo
        LEFT JOIN conductor c ON c.id = ru.id_conductor
        LEFT JOIN proveedor pr ON pr.id = ru.id_proveedor_minero
        LEFT JOIN empleado emp_aut ON emp_aut.id = ru.id_empleado_autoriza
        LEFT JOIN empleado emp_rec ON emp_rec.id = ru.id_empleado_recepcion
        WHERE ru.id = :id
            AND ru.es_recepcion_ficticia = 0
            LIMIT 1;
        ';

        $item = DB::selectOne($sql, ['id' => $id]);

        if ($item) {
            if (isset($item->evidencias)) {
                $item->evidencias = self::normalizar_evidencias($item->evidencias);
            }
            if (isset($item->log_cambios)) {
                $item->log_cambios = isset($item->log_cambios) ? (is_array($item->log_cambios) ? $item->log_cambios : (json_decode($item->log_cambios, true) ?? [])) : [];
            }
            if (isset($item->documentos_programacion)) {
                $item->documentos_programacion = self::normalizar_documentos_programacion($item->documentos_programacion);
            }
        }

        return $item ? (array) $item : null;
    }

    /**
     * Normalizar evidencias a la estructura IArchivo[] esperada por el frontend.
     */
    private static function normalizar_evidencias(mixed $evidencias): array
    {
        if (empty($evidencias)) {
            return [];
        }
        $arr = is_string($evidencias) ? (json_decode($evidencias, true) ?? []) : (array) $evidencias;
        if (! is_array($arr)) {
            return [];
        }

        return array_values(array_map(function ($item) {
            if (is_string($item)) {
                $nombre = pathinfo(parse_url($item, PHP_URL_PATH) ?? '', PATHINFO_FILENAME);
                $ext = pathinfo(parse_url($item, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION);

                return [
                    'url' => $item,
                    'path_relativo' => str_replace(asset('storage/').'/', '', $item),
                    'nombre_original' => $nombre ?: 'archivo',
                    'extension' => $ext ?: 'bin',
                ];
            }

            return $item;
        }, $arr));
    }

    /**
     * Normalizar la columna JSON documentos_programacion.
     * Estructura esperada: { guia_remitente: IArchivo|null, guia_transportista: IArchivo|null }
     * Cada IArchivo: { url, path_relativo, nombre_original, extension }
     */
    public static function normalizar_documentos_programacion(mixed $val): array
    {
        if (empty($val)) {
            return ['guia_remitente' => null, 'guia_transportista' => null];
        }
        $arr = is_string($val) ? (json_decode($val, true) ?? []) : (array) $val;
        if (! is_array($arr)) {
            return ['guia_remitente' => null, 'guia_transportista' => null];
        }

        $result = ['guia_remitente' => null, 'guia_transportista' => null];
        foreach (['guia_remitente', 'guia_transportista'] as $key) {
            if (isset($arr[$key]) && is_array($arr[$key]) && ! empty($arr[$key]['url'])) {
                $result[$key] = [
                    'url' => $arr[$key]['url'] ?? '',
                    'path_relativo' => $arr[$key]['path_relativo'] ?? '',
                    'nombre_original' => $arr[$key]['nombre_original'] ?? null,
                    'extension' => $arr[$key]['extension'] ?? null,
                ];
            }
        }

        return $result;
    }

    /**
     * Validar que el string de guía (remitente o transportista) no esté repetido para el mismo proveedor.
     * Excluye por defecto las recepciones anuladas (estado_pesaje / estado_salida en estados terminales)
     * y la propia fila indicada por $excluirId (para updates).
     *
     * @return string|null Mensaje de error si hay duplicado, null si OK.
     */
    public static function validar_unicidad_guia_proveedor(
        ?int $idProveedor,
        ?string $guiaRemitente,
        ?string $guiaTransportista,
        ?int $excluirId = null,
    ): ?string {
        if (! $idProveedor) {
            return null;
        }

        $campos = [
            'guia_remitente' => $guiaRemitente,
            'guia_transportista' => $guiaTransportista,
        ];

        foreach ($campos as $column => $valor) {
            if (! $valor || trim($valor) === '') {
                continue;
            }

            $sql = 'SELECT id, guia_remitente, guia_transportista FROM recepcion_unidad WHERE id_proveedor_minero = :id_proveedor AND es_recepcion_ficticia = 0 AND '.$column.' = :valor';
            $params = [
                'id_proveedor' => $idProveedor,
                'valor' => $valor,
            ];

            if ($excluirId !== null) {
                $sql .= ' AND id <> :excluir';
                $params['excluir'] = $excluirId;
            }

            $row = DB::selectOne($sql, $params);
            if ($row) {
                $etiqueta = $column === 'guia_remitente' ? 'remitente' : 'transportista';

                return "Ya existe una recepción del mismo proveedor con la misma guía {$etiqueta}.";
            }
        }

        return null;
    }

    /**
     * Generar ticket en ticket_recepcion_unidades con reinicio anual.
     * Correlativo formato: FAB-<año><numero correlativo> (ej. FAB-2026100)
     */
    public static function generar_ticket_recepcion_unidad(): array
    {
        $now = now();
        $siguienteNumero = (DB::table('ticket_recepcion_unidades')
            ->whereYear('created_at', $now->year)
            ->max('numero_correlativo') ?? 0) + 1;

        $correlativo = 'FAB-'.$now->year.$siguienteNumero;

        $ticketId = DB::table('ticket_recepcion_unidades')->insertGetId([
            'correlativo' => $correlativo,
            'numero_correlativo' => $siguienteNumero,
            'created_at' => $now,
        ]);

        return [
            'id' => (int) $ticketId,
            'correlativo' => $correlativo,
            'numero_correlativo' => $siguienteNumero,
        ];
    }

    /**
     * Asegura que una recepción de unidad tenga su ticket asignado.
     * Si ya tiene, lo devuelve. Si no tiene, lo genera y actualiza la recepción.
     */
    public static function asegurar_ticket_recepcion_unidad(int $idRecepcionUnidad): ?array
    {
        $recepcion = DB::table('recepcion_unidad')->where('id', $idRecepcionUnidad)->first();
        if (! $recepcion) {
            return null;
        }

        if (! empty($recepcion->id_ticket_recepcion_unidades)) {
            $ticket = DB::table('ticket_recepcion_unidades')->where('id', $recepcion->id_ticket_recepcion_unidades)->first();
            if ($ticket) {
                return [
                    'id' => (int) $ticket->id,
                    'correlativo' => (string) $ticket->correlativo,
                    'numero_correlativo' => (int) $ticket->numero_correlativo,
                ];
            }
        }

        $nuevoTicket = self::generar_ticket_recepcion_unidad();
        DB::table('recepcion_unidad')
            ->where('id', $idRecepcionUnidad)
            ->update(['id_ticket_recepcion_unidades' => $nuevoTicket['id']]);

        return $nuevoTicket;
    }

    /**
     * Crear un registro de recepción.
     */
    public static function crear_recepcion(array $data): int
    {
        $ticket = self::generar_ticket_recepcion_unidad();

        $recepcion = RecepcionUnidad::create([
            'id_empleado_recepcion' => $data['id_empleado_registro'],
            'id_vehiculo' => $data['id_vehiculo'] ?? null,
            'id_vehiculo_carreta' => $data['id_vehiculo_carreta'] ?? null,
            'id_empresa_transporte' => $data['id_empresa_transporte'],
            'id_tipo_vehiculo' => $data['id_tipo_vehiculo'],
            'id_conductor' => $data['id_conductor'],
            'id_proveedor_minero' => $data['id_proveedor_minero'] ?? null,
            'tipo_ingreso' => $data['tipo_ingreso'] ?? 'Recepción de Mineral',
            'fecha_hora_ingreso' => now()->toDateTimeString(),
            'evidencias' => $data['evidencias'] ?? [],
            'observacion' => $data['observacion'] ?? null,
            'estado' => EstadoVisita::EnPlanta->value,
            'id_sucursal' => $data['id_sucursal'],
            'estado_pesaje' => EstadoPesaje::SinPesar->value,
            'guia_remitente' => $data['guia_remitente'] ?? null,
            'guia_transportista' => $data['guia_transportista'] ?? null,
            'documentos_programacion' => isset($data['documentos_programacion']) && is_array($data['documentos_programacion'])
                ? json_encode($data['documentos_programacion'])
                : null,
            'id_ticket_recepcion_unidades' => $ticket['id'],
        ]);

        return $recepcion->id;
    }

    /**
     * Listar lotes de una recepción de unidad (usa la tabla compartida lote_mineral).
     */
    public static function get_lotes(int $idRecepcionUnidad): array
    {
        $sql = '
        SELECT
            lm.id,
            lm.id_recepcion_unidad,
            lm.correlativo,
            lm.numero_correlativo,
            lm.created_at AS fecha_hora_registro,
            lm.peso_inicial,
            lm.fecha_hora_peso_inicial,
            lm.peso_final,
            lm.fecha_hora_peso_final,
            lm.peso_neto,
            lm.peso_actual,
            lm.tiene_particion,
            lm.estado
        FROM lote_mineral lm
        WHERE lm.id_recepcion_unidad = :id_recepcion_unidad
          AND (lm.estado IS NULL OR lm.estado <> :estado_lote_no_eliminado)
        ORDER BY lm.numero_correlativo ASC
        ';

        $results = DB::select($sql, [
            'id_recepcion_unidad' => $idRecepcionUnidad,
            'estado_lote_no_eliminado' => EstadoBase::Eliminado->value,
        ]);

        foreach ($results as $item) {
            $item->id = (int) $item->id;
            $item->id_recepcion_unidad = (int) $item->id_recepcion_unidad;
            $item->numero_correlativo = (int) $item->numero_correlativo;
            $item->peso_inicial = $item->peso_inicial !== null ? (float) $item->peso_inicial : null;
            $item->peso_final = $item->peso_final !== null ? (float) $item->peso_final : null;
        }

        return $results;
    }

    /**
     * Generar un nuevo lote (vacío) para la recepción indicada.
     * Reutiliza la tabla compartida lote_mineral para mantener un correlativo único anual.
     */
    public static function crear_lote(int $idRecepcionUnidad, int $idEmpleadoRegistro): ?LoteMineral
    {
        $recepcion = RecepcionUnidad::find($idRecepcionUnidad);
        if (! $recepcion) {
            return null;
        }

        $correlativoData = CorrelativoHelper::generar(
            tabla: 'lote_mineral',
            prefijo: 'LOT',
            filtros: [],
            longitudCeros: 5,
            reseteo: Periodo::Anual,
        );

        // Crear automáticamente el registro en ticket_balanza al generar el lote
        $ticketId = DB::table('ticket_balanza')->insertGetId([
            'created_at' => now(),
        ]);

        $lote = LoteMineral::create([
            'id_recepcion_unidad' => $idRecepcionUnidad,
            'id_empleado_registro' => $idEmpleadoRegistro,
            'correlativo' => $correlativoData['correlativo'],
            'numero_correlativo' => $correlativoData['numero_correlativo'],
            'id_ticket_balanza' => $ticketId,
            'created_at' => now()->toDateTimeString(),
        ]);

        return $lote;
    }

    /**
     * Eliminar un lote por su ID.
     */
    public static function eliminar_lote(int $loteId): bool
    {
        $lote = LoteMineral::find($loteId);
        if (! $lote) {
            return false;
        }

        if ($lote->id_ticket_balanza) {
            DB::table('ticket_balanza')->where('id', $lote->id_ticket_balanza)->delete();
        }

        $lote->delete();

        return true;
    }

    /**
     * Obtener toda la información estructurada para el Ticket de Ingreso de Vehículos con Carga.
     */
    public static function get_ticket_ingreso_info(int $idRecepcionUnidad): ?array
    {
        $ticket = self::asegurar_ticket_recepcion_unidad($idRecepcionUnidad);
        if (! $ticket) {
            return null;
        }

        $sql = <<<'SQL'
            SELECT
                ru.id                                AS recepcion_id,
                ru.tipo_ingreso                      AS tipo_ingreso,
                ru.id_distribucion                   AS id_distribucion,
                ru.id_ticket_recepcion_unidades      AS id_ticket_recepcion_unidades,
                tru.correlativo                      AS ticket_correlativo,
                tru.numero_correlativo               AS ticket_numero_correlativo,

                -- Fabero
                emp_fabero.razon_social              AS fabero_razon_social,
                emp_fabero.ruc                       AS fabero_ruc,
                emp_fabero.domicilio_fiscal          AS fabero_domicilio_fiscal,
                s_dir.direccion                      AS fabero_sede_productiva,

                -- Proveedor
                pr.id                                AS id_proveedor,
                pr.razon_social                      AS proveedor_razon_social,
                pr.ruc                               AS proveedor_ruc,
                pr.direccion                         AS proveedor_direccion,

                -- Concesion minera del proveedor
                cns_origen.nombre                    AS concesion_nombre,
                dep_cori.nombre                      AS concesion_departamento,
                prv_cori.nombre                      AS concesion_provincia,
                dis_cori.nombre                      AS concesion_distrito,

                -- Despacho si aplica
                pd.razon_social                      AS planta_destino_razon_social,
                pd.ruc                               AS planta_destino_ruc,
                pd.direccion                         AS planta_destino_direccion,

                -- Vehiculo y Carreta
                v.placa                              AS vehiculo_placa,
                mv.nombre                            AS vehiculo_marca,
                vc.placa                             AS carreta_placa,
                mc.nombre                            AS carreta_marca,

                -- Transportista y Conductor
                et.razon_social                      AS transportista_razon_social,
                et.ruc                               AS transportista_ruc,
                ru.guia_transportista                AS guia_transportista,
                CONCAT(COALESCE(c.nombre, ''), ' ', COALESCE(c.apellido, '')) AS conductor_nombre,
                c.numero_licencia                    AS conductor_licencia,

                -- Guias, fechas, producto y observaciones
                ru.guia_remitente                    AS guia_remitente,
                ru.fecha_hora_ingreso                AS fecha_hora_ingreso,
                ru.fecha_hora_salida                 AS fecha_hora_salida,
                ru.observacion                       AS observacion,

                -- Lote / producto
                lm.tipo_producto                     AS lote_tipo_producto,
                lm.correlativo                       AS lote_correlativo
            FROM recepcion_unidad ru
            LEFT JOIN ticket_recepcion_unidades tru ON tru.id = ru.id_ticket_recepcion_unidades
            LEFT JOIN vehiculo v ON v.id = ru.id_vehiculo
            LEFT JOIN marca mv ON mv.id = v.id_marca
            LEFT JOIN vehiculo vc ON vc.id = ru.id_vehiculo_carreta
            LEFT JOIN marca mc ON mc.id = vc.id_marca
            LEFT JOIN empresa_transporte et ON et.id = ru.id_empresa_transporte
            LEFT JOIN conductor c ON c.id = ru.id_conductor
            LEFT JOIN proveedor pr ON pr.id = ru.id_proveedor_minero
            LEFT JOIN distribucion dist ON dist.id = ru.id_distribucion
            LEFT JOIN despacho desp ON desp.id = dist.id_despacho
            LEFT JOIN planta_destino pd ON pd.id = desp.id_planta_destino
            LEFT JOIN empresa emp_fabero ON emp_fabero.ruc = '20604623007'
            LEFT JOIN (
                SELECT
                    s.id AS id_sucursal,
                    CONCAT_WS(' - ', s.direccion, d2.nombre, p2.nombre, di.nombre) AS direccion
                FROM sucursal s
                LEFT JOIN departamento d2 ON d2.id = s.id_departamento
                LEFT JOIN provincia p2 ON p2.id = s.id_provincia
                LEFT JOIN distrito di ON di.id = s.id_distrito
            ) s_dir ON s_dir.id_sucursal = ru.id_sucursal
            LEFT JOIN (
                SELECT id_proveedor, MIN(id_concesion) AS min_concesion_id
                FROM concesion_proveedor
                GROUP BY id_proveedor
            ) cp_min ON cp_min.id_proveedor = pr.id
            LEFT JOIN concesion cns_origen ON cns_origen.id = cp_min.min_concesion_id
            LEFT JOIN departamento dep_cori ON dep_cori.id = cns_origen.id_departamento
            LEFT JOIN provincia prv_cori ON prv_cori.id = cns_origen.id_provincia
            LEFT JOIN distrito dis_cori ON dis_cori.id = cns_origen.id_distrito
            LEFT JOIN (
                SELECT id_recepcion_unidad, MIN(id) AS id_primer_lote
                FROM lote_mineral
                GROUP BY id_recepcion_unidad
            ) lm_first ON lm_first.id_recepcion_unidad = ru.id
            LEFT JOIN lote_mineral lm ON lm.id = lm_first.id_primer_lote
            WHERE ru.id = :id
            LIMIT 1
        SQL;

        $row = DB::selectOne($sql, ['id' => $idRecepcionUnidad]);
        if (! $row) {
            return null;
        }

        return self::hydrate_ticket_ingreso($row);
    }

    /**
     * Convierte el row crudo al shape que requiere el PDF Ticket de Ingreso.
     */
    private static function hydrate_ticket_ingreso(object $row): array
    {
        $esDespacho = ($row->tipo_ingreso === 'Despacho de Mineral');

        // Fabero datos
        $faberoSedeProductiva = self::strOrDash(
            $row->fabero_sede_productiva ?? 'KM. 573 OTR. PANAMERICANA NORTE (B083464-13-01) LA LIBERTAD - TRUJILLO - HUANCHACO'
        );
        $faberoDomicilioFiscal = self::strOrDash(
            $row->fabero_domicilio_fiscal ?? 'CAL. ESTAMBUL NRO. 178 URB. SANTA ISABEL LA LIBERTAD - TRUJILLO - TRUJILLO'
        );
        $faberoRazonSocial = self::strOrDash($row->fabero_razon_social ?? 'FABRICACIONES FABERO S.A.C.');
        $faberoRuc = self::strOrDash($row->fabero_ruc ?? '20604623007');

        // Fechas y horas
        $fechaHoraIngreso = ! empty($row->fecha_hora_ingreso) ? \Carbon\Carbon::parse($row->fecha_hora_ingreso) : null;
        $fechaHoraSalida = ! empty($row->fecha_hora_salida) ? \Carbon\Carbon::parse($row->fecha_hora_salida) : null;

        $formatHora = function (?\Carbon\Carbon $c): string {
            if (! $c) {
                return '—';
            }
            $time = $c->format('h:i:s');
            $meridiem = strtolower($c->format('a')) === 'pm' ? 'p.m.' : 'a.m.';

            return "{$time} {$meridiem}";
        };

        $fechaIngreso = $fechaHoraIngreso ? $fechaHoraIngreso->format('d/m/Y') : '—';
        $horaIngreso = $formatHora($fechaHoraIngreso);
        $fechaSalida = $fechaHoraSalida ? $fechaHoraSalida->format('d/m/Y') : '—';
        $horaSalida = $formatHora($fechaHoraSalida);

        if ($esDespacho) {
            $remitenteRazonSocial = $faberoRazonSocial;
            $remitenteRuc = $faberoRuc;
            $procedencia = $faberoSedeProductiva;
            $destino = self::strOrDash($row->planta_destino_direccion ?? $row->planta_destino_razon_social ?? null);
            $producto = 'MINERAL AURIFERO EN BRUTO SIN PROCESAR';
        } else {
            $remitenteRazonSocial = self::strOrDash($row->proveedor_razon_social ?? null);
            $remitenteRuc = self::strOrDash($row->proveedor_ruc ?? null);

            $ubicacionConcesion = implode('-', array_filter([
                $row->concesion_distrito ?? null,
                $row->concesion_provincia ?? null,
                $row->concesion_departamento ?? null,
            ], fn ($p) => ! empty($p)));

            if (! empty($row->concesion_nombre)) {
                $procedencia = 'CONCESION MINERA: '.$row->concesion_nombre;
                if ($ubicacionConcesion !== '') {
                    $procedencia .= ' UBICADA EN '.$ubicacionConcesion;
                }
            } else {
                $procedencia = self::strOrDash($row->proveedor_direccion ?? null);
            }

            $destino = $faberoSedeProductiva;
            $producto = 'MINERAL AURIFERO EN BRUTO SIN PROCESAR';
        }

        // Por el momento estos datos van con raya (—)
        $pesoGuiaTm = null;
        $pesoVehicularTotalTm = null;

        return [
            'id' => (int) $row->recepcion_id,
            'correlativo' => self::strOrDash($row->ticket_correlativo ?? null),
            'tiv' => self::strOrDash($row->ticket_correlativo ?? null),
            'numero_correlativo' => $row->ticket_numero_correlativo !== null ? (int) $row->ticket_numero_correlativo : null,
            'tipo_ingreso' => $row->tipo_ingreso,

            'empresa_fabero' => [
                'razon_social' => $faberoRazonSocial,
                'ruc' => $faberoRuc,
                'domicilio_fiscal' => $faberoDomicilioFiscal,
                'sede_productiva' => $faberoSedeProductiva,
            ],

            'remitente' => [
                'razon_social' => $remitenteRazonSocial,
                'ruc' => $remitenteRuc,
                'procedencia' => $procedencia,
                'destino' => $destino,
                'guia_remitente' => self::strOrDash($row->guia_remitente ?? null),
                'producto' => $producto,
            ],

            'vehiculo' => [
                'placa' => self::strOrDash($row->vehiculo_placa ?? null),
                'marca_tracto' => self::strOrDash($row->vehiculo_marca ?? null),
                'placa_carreta' => self::strOrDash($row->carreta_placa ?? null),
                'marca_carreta' => self::strOrDash($row->carreta_marca ?? null),
                'subcontratista' => '—',
            ],

            'transportista' => [
                'razon_social' => self::strOrDash($row->transportista_razon_social ?? null),
                'ruc' => self::strOrDash($row->transportista_ruc ?? null),
                'guia_transportista' => self::strOrDash($row->guia_transportista ?? null),
                'conductor_nombre' => self::strOrDash($row->conductor_nombre ?? null),
                'licencia' => self::strOrDash($row->conductor_licencia ?? null),
            ],

            'generales' => [
                'fecha_ingreso' => $fechaIngreso,
                'hora_ingreso' => $horaIngreso,
                'fecha_salida' => $fechaSalida,
                'hora_salida' => $horaSalida,
                'peso_guia_tm' => $pesoGuiaTm,
                'peso_vehicular_total_tm' => $pesoVehicularTotalTm,
            ],

            'observaciones' => self::strOrDash($row->observacion ?? null),
        ];
    }

    private static function strOrDash(?string $v): string
    {
        if ($v === null) {
            return '—';
        }
        $trimmed = trim($v);

        return $trimmed === '' ? '—' : $trimmed;
    }
}
