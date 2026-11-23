<?php

namespace App\Modules\ProgramarRecepcion\Data;

use App\Modules\RecepcionUnidades\Data\RecepcionUnidadesData;
use App\Shared\Enums\_Generic\EstadoPesaje;
use App\Shared\Enums\_Generic\EstadoVisita;
use App\Shared\Helpers\ArchivoHelper;
use App\Shared\Helpers\CamposBloqueadosHelper;
use App\Shared\Responses\_Generic\RES_CambiosLog;
use Illuminate\Support\Facades\DB;

class ProgramarRecepcionData
{
    /**
     * Obtener programaciones (recepciones con es_programacion = 1).
     * Opcionalmente filtrar por estado de confirmación: las no confirmadas (id_empleado_recepcion IS NULL).
     */
    public static function get_programaciones(array $filtros = []): array
    {
        $sql = '
        SELECT
            ru.id,
            ru.id_empleado_autoriza,
            CONCAT(emp_aut.nombre, " ", emp_aut.apellido) AS empleado_autoriza_nombre,
            ru.id_empresa_transporte,
            et.razon_social AS empresa_transporte_razon_social,
            ru.id_vehiculo,
            v.placa AS vehiculo_placa,
            ru.id_tipo_vehiculo,
            tv.nombre AS tipo_vehiculo_nombre,
            ru.id_conductor,
            CONCAT(c.nombre, " ", c.apellido) AS conductor_nombre_completo,
            c.numero_licencia AS conductor_numero_licencia,
            ru.tipo_ingreso,
            ru.guias,
            ru.fecha_estimada_llegada,
            ru.observacion,
            ru.es_programacion,
            ru.es_recepcion_ficticia,
            ru.id_empleado_recepcion,
            ru.fecha_hora_ingreso,
            ru.estado,
            ru.created_at,
            ru.id_ticket_recepcion_unidades,
            tru.correlativo AS ticket_correlativo,
            tru.numero_correlativo AS ticket_numero_correlativo
        FROM
            recepcion_unidad ru
        LEFT JOIN empleado emp_aut ON emp_aut.id = ru.id_empleado_autoriza
        INNER JOIN empresa_transporte et ON et.id = ru.id_empresa_transporte
        LEFT JOIN vehiculo v ON v.id = ru.id_vehiculo
        LEFT JOIN tipo_vehiculo tv ON tv.id = ru.id_tipo_vehiculo
        LEFT JOIN conductor c ON c.id = ru.id_conductor
        LEFT JOIN ticket_recepcion_unidades tru ON tru.id = ru.id_ticket_recepcion_unidades
        WHERE ru.es_programacion = 1
        -- Las recepciones ficticias (copias técnicas que alojan particiones)
        -- heredan es_programacion de su origen, pero NO son programaciones:
        -- mostrarlas duplicaba el registro en este listado.
          AND ru.es_recepcion_ficticia = 0
        ';

        $params = [];

        $estado = $filtros['estado_confirmacion'] ?? null;
        if ($estado === 'pendientes' || ($estado === null && ($filtros['solo_pendientes'] ?? false) === true)) {
            $sql .= ' AND ru.fecha_hora_ingreso IS NULL';
        } elseif ($estado === 'confirmadas') {
            $sql .= ' AND ru.fecha_hora_ingreso IS NOT NULL';
        }

        // Filtro por fecha. Mismo criterio que RecepcionUnidadesData::get_recepciones():
        // una programacion PENDIENTE (es_programacion = 1 y fecha_hora_ingreso IS NULL)
        // todavia no tiene llegada real; su fecha de referencia es la estimada, que por
        // definicion es futura. Si se le aplicara el rango [fecha_inicio, fecha_fin] tal
        // cual (que por defecto termina en HOY), toda programacion agendada para manana
        // quedaria oculta del listado y solo se veria tras insertarla en el estado local
        // del front. Las confirmadas (fecha_hora_ingreso IS NOT NULL) si se filtran por
        // su fecha de llegada.
        $referenciaFecha = 'DATE(COALESCE(ru.fecha_hora_ingreso, ru.fecha_estimada_llegada, ru.created_at))';
        $esPendiente = '(ru.es_programacion = 1 AND ru.fecha_hora_ingreso IS NULL)';

        if (! empty($filtros['fecha_inicio'])) {
            $sql .= ' AND ('.$esPendiente.' OR '.$referenciaFecha.' >= :fecha_inicio)';
            $params['fecha_inicio'] = $filtros['fecha_inicio'];
        }
        if (! empty($filtros['fecha_fin'])) {
            $sql .= ' AND ('.$esPendiente.' OR '.$referenciaFecha.' <= :fecha_fin)';
            $params['fecha_fin'] = $filtros['fecha_fin'];
        }

        $sql .= ' ORDER BY COALESCE(ru.fecha_hora_ingreso, ru.fecha_estimada_llegada, ru.created_at) DESC';

        $results = DB::select($sql, $params);

        self::hidratar_contrato_guias($results);

        return $results;
    }

    /**
     * Hidratar el contrato de guías y proveedores de un listado de programaciones.
     *
     * Los proveedores de la carga viven en `proveedor_recepcion` y las guías
     * (con sus archivos) en la columna JSON `guias` de `recepcion_unidad`.
     *
     * @param  array<int, object>  $rows
     */
    private static function hidratar_contrato_guias(array $rows): void
    {
        if (empty($rows)) {
            return;
        }

        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int) $row->id;
        }

        $proveedoresPorRecepcion = RecepcionUnidadesData::get_proveedores_por_recepcion($ids);

        foreach ($rows as $row) {
            $idRecepcion = (int) $row->id;
            $guias = RecepcionUnidadesData::normalizar_guias($row->guias ?? null);
            $proveedores = array_values($proveedoresPorRecepcion[$idRecepcion] ?? []);

            $row->guias = $guias;
            $row->proveedores = $proveedores;

            // Campos legacy derivados del proveedor y de la guía principal:
            // los consumen módulos que todavía no migraron al contrato nuevo.
            $principal = $proveedores[0] ?? null;
            $row->id_proveedor_minero = $principal !== null ? $principal['id_proveedor'] : null;
            $row->proveedor_razon_social = $principal !== null ? $principal['razon_social'] : null;
            $row->proveedor_ruc = $principal !== null ? $principal['ruc'] : null;

            $guia = RecepcionUnidadesData::guia_principal($guias, $row->id_proveedor_minero);
            $row->guia_remitente = ($guia['guia_remitente'] ?? '') !== '' ? $guia['guia_remitente'] : null;
            $row->guia_transportista = ($guia['guia_transportista'] ?? '') !== '' ? $guia['guia_transportista'] : null;
            $row->documentos_programacion = [
                'guia_remitente' => $guia['guia_remitente_archivo'] ?? null,
                'guia_transportista' => $guia['guia_transportista_archivo'] ?? null,
            ];
        }
    }

    /**
     * Detalle completo de una programación + visita asociada + vehículos + visitantes.
     */
    public static function get_programacion_full(int $id): ?array
    {
        $programacion = RecepcionUnidadesData::get_recepcion_by_id($id);
        if ($programacion === null) {
            return null;
        }

        // `get_recepcion_by_id` ya devuelve el contrato de guías y proveedores
        // hidratado (`guias`, `proveedores` y los campos legacy derivados).

        // Buscar visita asociada (si fue confirmada)
        $visita = DB::selectOne('
            SELECT
                rv.id AS id_recepcion_visita,
                rv.id_motivo_ingreso,
                mi.nombre AS motivo_ingreso_nombre,
                rv.fecha_hora_ingreso,
                rv.observacion,
                rv.estado
            FROM recepcion_visita rv
            LEFT JOIN motivo_ingreso mi ON mi.id = rv.id_motivo_ingreso
            WHERE rv.id_recepcion_unidad = :id
            LIMIT 1
        ', ['id' => $id]);

        $visitaId = $visita->id_recepcion_visita ?? null;
        $visitaPayload = $visita ? (array) $visita : null;

        if ($visitaId !== null) {
            // Cargar vehículos acompañantes
            $vehiculos = DB::select('
                SELECT
                    id, id_recepcion_visita, placa, cantidad_personas, url_foto
                FROM visita_vehiculo
                WHERE id_recepcion_visita = :id
                ORDER BY id ASC
            ', ['id' => $visitaId]);

            foreach ($vehiculos as $vv) {
                $vv->url_foto = $vv->url_foto ? json_decode($vv->url_foto, true) : null;
            }

            // Cargar detalles (visitantes) de la visita
            $detalles = DB::select('
                SELECT
                    rvd.id AS id_detalle,
                    rvd.id_visitante,
                    rvd.id_visita_vehiculo,
                    rvd.es_conductor,
                    rvd.estado,
                    v.nombre AS visitante_nombre,
                    v.apellido AS visitante_apellido,
                    v.dni AS visitante_dni,
                    v.telefono AS visitante_telefono,
                    rvd.url_foto_documento
                FROM recepcion_visita_detalle rvd
                INNER JOIN visitante v ON v.id = rvd.id_visitante
                WHERE rvd.id_recepcion_visita = :id
                ORDER BY rvd.es_conductor DESC, rvd.id ASC
            ', ['id' => $visitaId]);

            foreach ($detalles as $d) {
                $d->url_foto_documento = $d->url_foto_documento ? json_decode($d->url_foto_documento, true) : null;
                if (isset($d->es_conductor)) {
                    $d->es_conductor = (int) $d->es_conductor === 1;
                }
            }

            $visitaPayload['vehiculos'] = $vehiculos;
            $visitaPayload['detalles'] = $detalles;
        }

        $programacion['visita'] = $visitaPayload;

        return $programacion;
    }

    /**
     * Crear una programación (recepcion_unidad con es_programacion = 1).
     *
     * La programación es una fila de `recepcion_unidad`: sus proveedores
     * quedan en `proveedor_recepcion` y sus guías (con archivos) en la
     * columna JSON `guias`. Al confirmar la llegada esa misma fila pasa a
     * ser la recepción, sin copiar nada.
     *
     * @param  array  $data  Datos básicos de la programación. Incluye
     *                       `proveedores` (ids de proveedor de la carga) y
     *                       `guias` (una entrada por proveedor, con sus
     *                       archivos ya subidos).
     */
    public static function crear_programacion(array $data): int
    {
        $proveedores = self::normalizar_ids($data['proveedores'] ?? []);
        $guias = RecepcionUnidadesData::normalizar_guias($data['guias'] ?? []);

        $insert = [
            'id_empleado_autoriza' => $data['id_empleado_autoriza'],
            'id_empresa_transporte' => $data['id_empresa_transporte'],
            'id_vehiculo' => $data['id_vehiculo'] ?? null,
            'id_tipo_vehiculo' => $data['id_tipo_vehiculo'] ?? null,
            'id_conductor' => $data['id_conductor'] ?? null,
            'id_sucursal' => $data['id_sucursal'] ?? null,
            'tipo_ingreso' => $data['tipo_ingreso'] ?? 'Recepción de Mineral',
            'fecha_estimada_llegada' => $data['fecha_estimada_llegada'] ?? null,
            'observacion' => $data['observacion'] ?? null,
            'es_programacion' => 1,
            'created_at' => now()->toDateTimeString(),
        ];

        if (! empty($guias)) {
            $insert['guias'] = json_encode($guias);
        }

        // Los campos que se registran en la programación quedan bloqueados
        // para edición posterior (esos datos los registró y autorizó otra
        // persona). Las guías de la programación también quedan bloqueadas
        // en la recepción que se genere al confirmar la llegada.
        $bloqueados = CamposBloqueadosHelper::calcular($insert);
        $bloqueados = array_merge($bloqueados, self::guias_bloqueadas($guias));
        $bloqueados = array_values(array_unique($bloqueados));
        if (! empty($bloqueados)) {
            $insert['campos_bloqueados'] = json_encode($bloqueados);
        }

        return DB::transaction(function () use ($insert, $proveedores) {
            $id = (int) DB::table('recepcion_unidad')->insertGetId($insert);

            if (! empty($proveedores)) {
                RecepcionUnidadesData::sync_proveedores_recepcion($id, $proveedores);
            }

            return $id;
        });
    }

    /**
     * Columnas que admiten edición en una programación NO confirmada.
     *
     * `tipo_ingreso` queda deliberadamente fuera: lo fija el proceso de la
     * unidad, no la agenda. Tampoco se tocan los campos del proceso
     * (autorización, estado, ticket de ingreso, confirmación).
     *
     * El proveedor de la carga y las guías ya NO son columnas de
     * `recepcion_unidad`: viven en `proveedor_recepcion` y en la columna
     * JSON `guias`, y se editan con los mismos endpoints que usa la
     * recepción (`/recepcion-unidades/{id}/proveedores` y
     * `/recepcion-unidades/{id}/guias-recepcion`).
     *
     * @var array<int, string>
     */
    public const COLUMNAS_EDITABLES = [
        'id_empresa_transporte',
        'id_vehiculo',
        'id_tipo_vehiculo',
        'id_conductor',
        'id_sucursal',
        'fecha_estimada_llegada',
        'observacion',
    ];

    /**
     * Etiquetas legibles de las columnas editables (para `log_cambios`).
     *
     * @var array<string, string>
     */
    public const ETIQUETAS_COLUMNAS = [
        'id_empresa_transporte' => 'Empresa de transporte',
        'id_vehiculo' => 'Vehículo',
        'id_tipo_vehiculo' => 'Tipo de vehículo',
        'id_conductor' => 'Conductor',
        'id_sucursal' => 'Sucursal',
        'fecha_estimada_llegada' => 'Fecha estimada de llegada',
        'observacion' => 'Observación',
    ];

    /**
     * Actualizar programación (solo permitido mientras NO esté confirmada).
     *
     * Acepta `guias` (array normalizado) y `proveedores` (ids), que se
     * guardan en la columna JSON `guias` y en `proveedor_recepcion`.
     * Los cambios se registran en `log_cambios` con motivo "Edición de programación".
     */
    public static function actualizar_programacion(int $id, array $data, int $idEmpleado): bool
    {
        $fila = DB::table('recepcion_unidad')
            ->where('id', $id)
            ->where('es_programacion', 1)
            ->where('es_recepcion_ficticia', 0)
            ->first();

        if ($fila === null || ! empty($fila->id_empleado_recepcion)) {
            return false;
        }

        $update = [];
        foreach (self::COLUMNAS_EDITABLES as $columna) {
            if (! array_key_exists($columna, $data)) {
                continue;
            }
            $valor = $data[$columna];
            $update[$columna] = ($valor === '' ? null : $valor);
        }

        $cambios = [];

        if (array_key_exists('guias', $data)) {
            $guias = RecepcionUnidadesData::normalizar_guias($data['guias']);
            $guiasAnteriores = RecepcionUnidadesData::normalizar_guias($fila->guias ?? null);
            $resumenAnterior = self::resumen_guias($guiasAnteriores);
            $resumenNuevo = self::resumen_guias($guias);
            if ($resumenAnterior !== $resumenNuevo) {
                $cambios[] = [
                    'campo_bd' => 'guias',
                    'campo' => 'Guías',
                    'valor_anterior' => $resumenAnterior,
                    'valor_nuevo' => $resumenNuevo,
                ];
            }
            $update['guias'] = ! empty($guias) ? json_encode($guias) : null;

            // Los bloqueos de las guías se recalculan: si la programación deja
            // de registrar una guía, esta vuelve a ser editable en la
            // recepción que se genere al confirmar.
            $bloqueados = CamposBloqueadosHelper::normalizar($fila->campos_bloqueados ?? null);
            $bloqueados = array_values(array_diff($bloqueados, ['guia_remitente', 'guia_transportista']));
            $bloqueados = array_values(array_unique(array_merge($bloqueados, self::guias_bloqueadas($guias))));
            $update['campos_bloqueados'] = json_encode($bloqueados);
        }

        foreach ($update as $columna => $valorNuevo) {
            // `guias` y `campos_bloqueados` se registran con su propio
            // resumen: no son cambios de columna independientes.
            if ($columna === 'guias' || $columna === 'campos_bloqueados') {
                continue;
            }
            $valorAnterior = $fila->{$columna} ?? null;
            if (self::mismo_valor($valorAnterior, $valorNuevo)) {
                continue;
            }
            $cambios[] = [
                'campo_bd' => $columna,
                'campo' => self::ETIQUETAS_COLUMNAS[$columna] ?? $columna,
                'valor_anterior' => self::valor_legible($valorAnterior),
                'valor_nuevo' => self::valor_legible($valorNuevo),
            ];
        }

        $proveedoresNuevos = null;
        if (array_key_exists('proveedores', $data)) {
            $proveedoresNuevos = self::normalizar_ids($data['proveedores']);
            $proveedoresAnteriores = RecepcionUnidadesData::get_proveedores_recepcion($id);
            foreach (self::diff_proveedores($proveedoresAnteriores, $proveedoresNuevos) as $cambio) {
                $cambios[] = $cambio;
            }
        }

        if (! empty($cambios)) {
            $logActual = $fila->log_cambios ?? null;
            if (is_string($logActual)) {
                $logActual = json_decode($logActual, true) ?? [];
            }
            if (! is_array($logActual)) {
                $logActual = [];
            }
            array_unshift($logActual, RES_CambiosLog::crear($idEmpleado, 'Edición de programación', $cambios));
            $update['log_cambios'] = json_encode($logActual);
        }

        if (empty($update) && $proveedoresNuevos === null) {
            return true;
        }

        $afectadas = 0;

        DB::transaction(function () use ($id, $update, $proveedoresNuevos, &$afectadas) {
            if (! empty($update)) {
                $afectadas = DB::table('recepcion_unidad')
                    ->where('id', $id)
                    ->where('es_programacion', 1)
                    ->whereNull('id_empleado_recepcion')
                    ->update($update);
            }

            if ($proveedoresNuevos !== null) {
                RecepcionUnidadesData::sync_proveedores_recepcion($id, $proveedoresNuevos);
            }
        });

        // `update()` devuelve 0 cuando los valores ya eran idénticos: eso NO es
        // un fallo. Solo se considera fallo si había cambios reales y la fila
        // no se pudo escribir (p.ej. se confirmó en paralelo).
        return $afectadas > 0 || empty($cambios);
    }

    /**
     * Comparar el valor anterior con el nuevo (null y vacío son equivalentes).
     *
     * Las fechas se comparan solo por su parte `YYYY-MM-DD`: la API devuelve
     * la fecha estimada con hora y el formulario la envía solo con el día.
     */
    private static function mismo_valor(mixed $anterior, mixed $nuevo): bool
    {
        if ($anterior === null || $anterior === '') {
            return $nuevo === null || $nuevo === '';
        }
        if ($nuevo === null || $nuevo === '') {
            return false;
        }

        $anteriorTexto = (string) $anterior;
        $nuevoTexto = (string) $nuevo;

        if (
            preg_match('/^\d{4}-\d{2}-\d{2}/', $anteriorTexto) === 1
            && preg_match('/^\d{4}-\d{2}-\d{2}/', $nuevoTexto) === 1
        ) {
            return substr($anteriorTexto, 0, 10) === substr($nuevoTexto, 0, 10);
        }

        return $anteriorTexto === $nuevoTexto;
    }

    /**
     * Valor legible para el `log_cambios`.
     */
    private static function valor_legible(mixed $valor): string
    {
        if ($valor === null || $valor === '') {
            return '— (vacío)';
        }

        return (string) $valor;
    }

    /**
     * Normalizar una lista de ids (proveedores) sin repetidos.
     *
     * @param  array<int, mixed>  $ids
     * @return array<int, int>
     */
    private static function normalizar_ids(array $ids): array
    {
        $normalizados = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0 && ! in_array($id, $normalizados, true)) {
                $normalizados[] = $id;
            }
        }

        return $normalizados;
    }

    /**
     * Campos de guía que quedan bloqueados por venir de una programación.
     *
     * @param  array<int, array<string, mixed>>  $guias
     * @return array<int, string>
     */
    private static function guias_bloqueadas(array $guias): array
    {
        $bloqueadas = [];

        foreach ($guias as $guia) {
            if (($guia['guia_remitente'] ?? '') !== '' && ! in_array('guia_remitente', $bloqueadas, true)) {
                $bloqueadas[] = 'guia_remitente';
            }
            if (($guia['guia_transportista'] ?? '') !== '' && ! in_array('guia_transportista', $bloqueadas, true)) {
                $bloqueadas[] = 'guia_transportista';
            }
        }

        return $bloqueadas;
    }

    /**
     * Resumen comparable de las guías (para el `log_cambios`).
     *
     * @param  array<int, array<string, mixed>>  $guias
     */
    private static function resumen_guias(array $guias): string
    {
        if (empty($guias)) {
            return '— (sin guías)';
        }

        $partes = [];
        foreach ($guias as $guia) {
            $remitente = trim((string) ($guia['guia_remitente'] ?? ''));
            $transportista = trim((string) ($guia['guia_transportista'] ?? ''));
            $archRemitente = $guia['guia_remitente_archivo']['nombre_original'] ?? null;
            $archTransportista = $guia['guia_transportista_archivo']['nombre_original'] ?? null;

            $partes[] = sprintf(
                'P%s | Remitente: %s (%s) | Transportista: %s (%s)',
                (string) ($guia['id_proveedor'] ?? '—'),
                $remitente !== '' ? $remitente : '—',
                ! empty($archRemitente) ? (string) $archRemitente : 'sin archivo',
                $transportista !== '' ? $transportista : '—',
                ! empty($archTransportista) ? (string) $archTransportista : 'sin archivo',
            );
        }

        return implode(' || ', $partes);
    }

    /**
     * Diferencias entre la lista de proveedores anterior y la nueva.
     *
     * @param  array<int, array{id_proveedor: int, razon_social: string, ruc: ?string}>  $anteriores
     * @param  array<int, int>  $nuevos
     * @return array<int, array{campo_bd: string, campo: string, valor_anterior: ?string, valor_nuevo: ?string}>
     */
    private static function diff_proveedores(array $anteriores, array $nuevos): array
    {
        $mapaAnterior = [];
        foreach ($anteriores as $proveedor) {
            $mapaAnterior[(int) $proveedor['id_proveedor']] = (string) $proveedor['razon_social'];
        }

        $razones = self::razones_sociales($nuevos);

        $cambios = [];
        foreach ($nuevos as $idProveedor) {
            if (! isset($mapaAnterior[$idProveedor])) {
                $cambios[] = [
                    'campo_bd' => 'proveedor_recepcion',
                    'campo' => 'Proveedor agregado',
                    'valor_anterior' => null,
                    'valor_nuevo' => $razones[$idProveedor] ?? null,
                ];
            }
        }
        foreach ($mapaAnterior as $idProveedor => $razonSocial) {
            if (! in_array($idProveedor, $nuevos, true)) {
                $cambios[] = [
                    'campo_bd' => 'proveedor_recepcion',
                    'campo' => 'Proveedor eliminado',
                    'valor_anterior' => $razonSocial,
                    'valor_nuevo' => null,
                ];
            }
        }

        return $cambios;
    }

    /**
     * Razón social de cada proveedor (para el historial de cambios).
     *
     * @param  array<int, int>  $ids
     * @return array<int, string>
     */
    private static function razones_sociales(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $razones = [];
        foreach (DB::table('proveedor')->whereIn('id', $ids)->pluck('razon_social', 'id') as $id => $razon) {
            $razones[(int) $id] = (string) $razon;
        }

        return $razones;
    }

    /**
     * Confirmar una programación.
     *
     * La programación ES una fila de `recepcion_unidad`: al confirmar, esa
     * misma fila pasa a ser la recepción, conservando sus proveedores
     * (`proveedor_recepcion`) y sus guías (`guias`).
     *
     * Acepta opcionalmente `observacion` y archivos de `evidencias` que se persisten en
     * `recepcion_unidad` con su entrada en `log_cambios` (motivo: "Confirmación inicial").
     */
    public static function confirmar_programacion(
        int $id,
        int $idEmpleadoRecepcion,
        array $overrides = [],
        ?string $observacion = null,
        array $archivosEvidencias = [],
    ): bool {
        $recepcion = RecepcionUnidadesData::get_recepcion_by_id($id);
        if (! $recepcion) {
            return false;
        }

        return DB::transaction(function () use ($recepcion, $id, $idEmpleadoRecepcion, $overrides, $observacion, $archivosEvidencias) {
            $ticketId = $recepcion['id_ticket_recepcion_unidades'] ?? null;
            if (empty($ticketId)) {
                $ticket = RecepcionUnidadesData::generar_ticket_recepcion_unidad();
                $overrides['id_ticket_recepcion_unidades'] = $ticket['id'];
            }

            $update = array_merge([
                'id_empleado_recepcion' => $idEmpleadoRecepcion,
                'fecha_hora_ingreso' => now()->toDateTimeString(),
                'estado' => EstadoVisita::EnPlanta->value,
                'estado_pesaje' => EstadoPesaje::SinPesar->value,
            ], $overrides);

            $ok = DB::table('recepcion_unidad')
                ->where('id', $id)
                ->where('es_programacion', 1)
                ->whereNull('id_empleado_recepcion')
                ->update($update) > 0;

            if (! $ok) {
                return false;
            }

            // Aplicar observación/evidencias (con log_cambios) si corresponde.
            $cambios = [];
            $motivo = 'Confirmación inicial';

            $observacionAnterior = $recepcion['observacion'] ?? null;
            if (($observacion ?? '') !== ($observacionAnterior ?? '')) {
                $cambios[] = [
                    'campo_bd' => 'observacion',
                    'campo' => 'Observación',
                    'valor_anterior' => $observacionAnterior !== null && $observacionAnterior !== '' ? $observacionAnterior : '— (vacío)',
                    'valor_nuevo' => $observacion !== null && $observacion !== '' ? $observacion : '— (vacío)',
                ];
                $update['observacion'] = $observacion;
            }

            $evidenciasAnteriores = $recepcion['evidencias'] ?? [];
            if (! is_array($evidenciasAnteriores)) {
                $evidenciasAnteriores = [];
            }
            $evidenciasActuales = $evidenciasAnteriores;
            if (! empty($archivosEvidencias)) {
                $nuevas = ArchivoHelper::guardarArchivos('recepcion-unidad', $archivosEvidencias);
                $evidenciasActuales = array_merge($evidenciasActuales, $nuevas);
            }
            $nombresAnt = array_values(array_filter(array_map(
                fn ($e) => is_array($e) ? ($e['nombre_original'] ?? null) : null,
                $evidenciasAnteriores,
            )));
            $nombresNue = array_values(array_filter(array_map(
                fn ($e) => is_array($e) ? ($e['nombre_original'] ?? null) : null,
                $evidenciasActuales,
            )));
            sort($nombresAnt);
            sort($nombresNue);
            if ($nombresAnt !== $nombresNue) {
                $cambios[] = [
                    'campo_bd' => 'evidencias',
                    'campo' => 'Evidencias',
                    'valor_anterior' => ! empty($nombresAnt) ? implode(', ', $nombresAnt) : '— (sin evidencias)',
                    'valor_nuevo' => ! empty($nombresNue) ? implode(', ', $nombresNue) : '— (sin evidencias)',
                ];
                $update['evidencias'] = json_encode($evidenciasActuales);
            }

            if (! empty($cambios)) {
                $logActual = $recepcion['log_cambios'] ?? [];
                if (! is_array($logActual)) {
                    $logActual = json_decode($logActual, true) ?? [];
                }
                $nuevoLog = RES_CambiosLog::crear($idEmpleadoRecepcion, $motivo, $cambios);
                array_unshift($logActual, $nuevoLog);
                $update['log_cambios'] = json_encode($logActual);
            }

            if (count($update) > 4) {
                DB::table('recepcion_unidad')->where('id', $id)->update($update);
            }

            return true;
        });
    }

    /**
     * Actualizar la observación y/o evidencias de una recepción ya confirmada.
     *
     * Compara antes/después, registra entrada en `log_cambios` con el motivo provisto
     * y devuelve la cantidad de entradas agregadas al log (0 si nada cambió).
     */
    public static function actualizar_observacion_evidencias(
        int $id,
        ?string $observacion,
        ?string $observacionSalida,
        array $evidenciasExistentes,
        array $archivosNuevos,
        int $idEmpleado,
        ?string $motivo = null,
    ): int {
        return DB::transaction(function () use ($id, $observacion, $observacionSalida, $evidenciasExistentes, $archivosNuevos, $idEmpleado, $motivo) {
            $recepcion = RecepcionUnidadesData::get_recepcion_by_id($id);
            if (! $recepcion) {
                return 0;
            }

            $cambios = [];

            $observacionAnterior = $recepcion['observacion'] ?? null;
            if (($observacion ?? '') !== ($observacionAnterior ?? '')) {
                $cambios[] = [
                    'campo_bd' => 'observacion',
                    'campo' => 'Observación',
                    'valor_anterior' => $observacionAnterior !== null && $observacionAnterior !== '' ? $observacionAnterior : '— (vacío)',
                    'valor_nuevo' => $observacion !== null && $observacion !== '' ? $observacion : '— (vacío)',
                ];
            }

            $observacionSalidaAnterior = $recepcion['observacion_salida'] ?? null;
            if (($observacionSalida ?? '') !== ($observacionSalidaAnterior ?? '')) {
                $cambios[] = [
                    'campo_bd' => 'observacion_salida',
                    'campo' => 'Observación Salida',
                    'valor_anterior' => $observacionSalidaAnterior !== null && $observacionSalidaAnterior !== '' ? $observacionSalidaAnterior : '— (vacío)',
                    'valor_nuevo' => $observacionSalida !== null && $observacionSalida !== '' ? $observacionSalida : '— (vacío)',
                ];
            }

            $evidenciasActuales = $evidenciasExistentes;
            if (! empty($archivosNuevos)) {
                $subidos = ArchivoHelper::guardarArchivos('recepcion-unidad', $archivosNuevos);
                $evidenciasActuales = array_merge($evidenciasActuales, $subidos);
            }

            $evidenciasAnteriores = $recepcion['evidencias'] ?? [];
            if (! is_array($evidenciasAnteriores)) {
                $evidenciasAnteriores = [];
            }
            $nombresAnt = array_values(array_filter(array_map(
                fn ($e) => is_array($e) ? ($e['nombre_original'] ?? null) : null,
                $evidenciasAnteriores,
            )));
            $nombresNue = array_values(array_filter(array_map(
                fn ($e) => is_array($e) ? ($e['nombre_original'] ?? null) : null,
                $evidenciasActuales,
            )));
            sort($nombresAnt);
            sort($nombresNue);
            if ($nombresAnt !== $nombresNue) {
                $cambios[] = [
                    'campo_bd' => 'evidencias',
                    'campo' => 'Evidencias',
                    'valor_anterior' => ! empty($nombresAnt) ? implode(', ', $nombresAnt) : '— (sin evidencias)',
                    'valor_nuevo' => ! empty($nombresNue) ? implode(', ', $nombresNue) : '— (sin evidencias)',
                ];
            }

            if (empty($cambios)) {
                return 0;
            }

            $update = [];
            if (in_array('observacion', array_column($cambios, 'campo_bd'), true)) {
                $update['observacion'] = $observacion;
            }
            if (in_array('observacion_salida', array_column($cambios, 'campo_bd'), true)) {
                $update['observacion_salida'] = $observacionSalida;
            }
            if (in_array('evidencias', array_column($cambios, 'campo_bd'), true)) {
                $update['evidencias'] = json_encode($evidenciasActuales);
            }

            $logActual = $recepcion['log_cambios'] ?? [];
            if (! is_array($logActual)) {
                $logActual = json_decode($logActual, true) ?? [];
            }
            $nuevoLog = RES_CambiosLog::crear($idEmpleado, $motivo, $cambios);
            array_unshift($logActual, $nuevoLog);
            $update['log_cambios'] = json_encode($logActual);

            DB::table('recepcion_unidad')->where('id', $id)->update($update);

            return count($cambios);
        });
    }
}
