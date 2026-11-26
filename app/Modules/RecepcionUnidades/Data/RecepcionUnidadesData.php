<?php

namespace App\Modules\RecepcionUnidades\Data;

use App\Data\LotesMineralDisponiblesData;
use App\Models\LoteMineral;
use App\Models\RecepcionUnidad;
use App\Shared\Enums\_Generic\EstadoBase;
use App\Shared\Enums\_Generic\EstadoPesaje;
use App\Shared\Enums\_Generic\EstadoVisita;
use App\Shared\Enums\_Generic\Periodo;
use App\Shared\Helpers\CamposBloqueadosHelper;
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
            ru.id_empleado_autoriza,
            CONCAT(emp_aut.nombre, " ", emp_aut.apellido) AS empleado_autoriza_nombre,
            ru.id_empleado_recepcion,
            CONCAT(emp_rec.nombre, " ", emp_rec.apellido) AS empleado_recepcion_nombre,
            ru.es_programacion,
            ru.id_distribucion,
            ru.fecha_estimada_llegada,
            ru.guias,
            ru.estado_ingreso,
            ru.guias_salida,
            ru.es_recepcion_ficticia,
            ru.id_ticket_recepcion_unidades,
            ru.campos_bloqueados,
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

        // Orden del listado:
        //  1. Las unidades que SIGUEN en planta (estado "En Planta").
        //  2. Las que todavía no llegaron (programaciones pendientes).
        //  3. Las que ya salieron ("Fuera de Planta"), al final.
        // Dentro de cada grupo, de la más reciente a la más antigua.
        $sql .= ' ORDER BY
            CASE ru.estado
                WHEN \'En Planta\' THEN 0
                WHEN \'Fuera de Planta\' THEN 2
                ELSE 1
            END ASC,
            COALESCE(ru.fecha_hora_ingreso, ru.fecha_estimada_llegada, ru.created_at) DESC;';

        $results = DB::select($sql, $params);

        // Decodificar la columna JSON de evidencias manualmente para que coincida con lo esperado por Eloquent
        $proveedoresPorRecepcion = self::get_proveedores_por_recepcion(array_map(
            static fn ($item) => (int) $item->id,
            $results
        ));

        foreach ($results as $item) {
            if (isset($item->evidencias)) {
                $item->evidencias = self::normalizar_evidencias($item->evidencias);
            }
            if (isset($item->log_cambios)) {
                $item->log_cambios = isset($item->log_cambios) ? (is_array($item->log_cambios) ? $item->log_cambios : (json_decode($item->log_cambios, true) ?? [])) : [];
            }
            self::hidratar_contrato_guias($item, $proveedoresPorRecepcion[(int) $item->id] ?? []);
            $item->guias_salida = self::normalizar_guias($item->guias_salida ?? null);
            $item->campos_bloqueados = CamposBloqueadosHelper::normalizar($item->campos_bloqueados ?? null);
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
            ru.id_empleado_autoriza,
            CONCAT(emp_aut.nombre, " ", emp_aut.apellido) AS empleado_autoriza_nombre,
            ru.id_empleado_recepcion,
            CONCAT(emp_rec.nombre, " ", emp_rec.apellido) AS empleado_recepcion_nombre,
            ru.es_programacion,
            ru.id_distribucion,
            ru.fecha_estimada_llegada,
            ru.guias,
            ru.estado_ingreso,
            ru.guias_salida,
            ru.es_recepcion_ficticia,
            ru.id_ticket_recepcion_unidades,
            ru.campos_bloqueados,
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
            self::hidratar_contrato_guias($item, self::get_proveedores_por_recepcion([(int) $item->id])[(int) $item->id] ?? []);
            $item->guias_salida = self::normalizar_guias($item->guias_salida ?? null);
            $item->campos_bloqueados = CamposBloqueadosHelper::normalizar($item->campos_bloqueados ?? null);
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
     * Obtener los datos de guías (documentos y textos) de una recepción.
     * Usado por gui-primer-tramo para autocompletar inputs de archivo al
     * registrar una guía cuando los items seleccionados pertenecen a una sola
     * recepción.
     *
     * Los datos salen del array JSON `guias` (una entrada por proveedor).
     */
    public static function get_archivos_guias_recepcion(int $idRecepcion): ?object
    {
        $row = DB::table('recepcion_unidad')
            ->where('id', $idRecepcion)
            ->first([
                'id',
                'guias',
                'id_vehiculo',
                'id_vehiculo_carreta',
                'id_conductor',
                'id_empresa_transporte',
            ]);

        if (! $row) {
            return null;
        }

        $guias = self::normalizar_guias($row->guias ?? null);
        $proveedores = self::get_proveedores_recepcion((int) $row->id);
        $guiaPrincipal = self::guia_principal($guias, $proveedores[0]['id_proveedor'] ?? null);

        $row->guias = $guias;
        $row->proveedores = $proveedores;
        $row->guia_remitente = $guiaPrincipal['guia_remitente'] ?? null;
        $row->guia_transportista = $guiaPrincipal['guia_transportista'] ?? null;
        $row->documentos = [
            'guia_remitente' => $guiaPrincipal['guia_remitente_archivo'] ?? null,
            'guia_transportista' => $guiaPrincipal['guia_transportista_archivo'] ?? null,
        ];

        return $row;
    }

    /**
     * Normalizar el array JSON `guias` de una recepción.
     *
     * Cada entrada corresponde a un proveedor de la carga:
     * [
     *   {
     *     "id_proveedor": 1,
     *     "guia_remitente": "",
     *     "guia_transportista": "",
     *     "guia_remitente_archivo": null,
     *     "guia_transportista_archivo": null
     *   }
     * ]
     *
     * Los archivos se devuelven con la forma `IArchivo` del proyecto
     * ({ url, path_relativo, nombre_original, extension }) o `null`. Se tolera
     * que se haya guardado como string suelto (una ruta): en ese caso se
     * normaliza al objeto IArchivo con `url` = asset('storage/'.$ruta).
     *
     * `id_proveedor = 0` significa "guía de la unidad, sin proveedor
     * asignado": se usa cuando la recepción se crea directamente en planta
     * (el proveedor se conoce después, con el registro de las cargas).
     *
     * @return array<int, array{id_proveedor: int, guia_remitente: string, guia_transportista: string, guia_remitente_archivo: ?array, guia_transportista_archivo: ?array, es_interno: bool}>
     */
    public static function normalizar_guias(mixed $valor): array
    {
        if (empty($valor)) {
            return [];
        }

        $arr = is_string($valor) ? (json_decode($valor, true) ?? []) : (array) $valor;
        if (! is_array($arr)) {
            return [];
        }

        $guias = [];
        foreach ($arr as $entrada) {
            if (! is_array($entrada)) {
                continue;
            }

            $idProveedor = isset($entrada['id_proveedor']) ? (int) $entrada['id_proveedor'] : 0;
            if ($idProveedor < 0) {
                continue;
            }

            $guias[] = [
                'id_proveedor' => $idProveedor,
                'guia_remitente' => isset($entrada['guia_remitente']) && $entrada['guia_remitente'] !== null
                    ? (string) $entrada['guia_remitente']
                    : '',
                'guia_transportista' => isset($entrada['guia_transportista']) && $entrada['guia_transportista'] !== null
                    ? (string) $entrada['guia_transportista']
                    : '',
                'guia_remitente_archivo' => self::normalizar_archivo_guia($entrada['guia_remitente_archivo'] ?? null),
                'guia_transportista_archivo' => self::normalizar_archivo_guia($entrada['guia_transportista_archivo'] ?? null),
                'es_interno' => (bool) ($entrada['es_interno'] ?? false),
            ];
        }

        return $guias;
    }

    /**
     * Normalizar un archivo de guía a la forma `IArchivo`.
     *
     * Acepta el objeto `IArchivo` ya guardado o un string con la ruta
     * relativa del archivo (formato tolerado por si alguien lo guardó así).
     *
     * @return array{url: string, path_relativo: string, nombre_original: ?string, extension: ?string}|null
     */
    private static function normalizar_archivo_guia(mixed $archivo): ?array
    {
        if (empty($archivo)) {
            return null;
        }

        if (is_string($archivo)) {
            $ruta = trim($archivo);
            if ($ruta === '') {
                return null;
            }

            $esUrlAbsoluta = str_starts_with($ruta, 'http://') || str_starts_with($ruta, 'https://');

            return [
                'url' => $esUrlAbsoluta ? $ruta : asset('storage/'.$ruta),
                'path_relativo' => $ruta,
                'nombre_original' => null,
                'extension' => null,
            ];
        }

        if (! is_array($archivo)) {
            return null;
        }

        $url = isset($archivo['url']) ? (string) $archivo['url'] : '';
        if ($url === '') {
            return null;
        }

        return [
            'url' => $url,
            'path_relativo' => isset($archivo['path_relativo']) ? (string) $archivo['path_relativo'] : '',
            'nombre_original' => isset($archivo['nombre_original']) ? (string) $archivo['nombre_original'] : null,
            'extension' => isset($archivo['extension']) ? (string) $archivo['extension'] : null,
        ];
    }

    /**
     * Normalizar la columna JSON documentos_programacion.
     *
     * @deprecated La columna `documentos_programacion` ya no existe en
     *             `recepcion_unidad`: los archivos viven dentro de cada
     *             entrada del array `guias`. Se conserva solo para los módulos
     *             que aún la referencian mientras se adaptan al nuevo modelo.
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
     * Proveedores de varias recepciones en una sola consulta.
     *
     * @param  array<int, int>  $idsRecepcion
     * @return array<int, array<int, array{id_proveedor: int, razon_social: string, ruc: ?string}>>
     */
    public static function get_proveedores_por_recepcion(array $idsRecepcion): array
    {
        $ids = [];
        foreach ($idsRecepcion as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        if (empty($ids)) {
            return [];
        }

        $placeholders = [];
        $params = [];
        foreach (array_values($ids) as $index => $id) {
            $nombre = 'id_rec_'.$index;
            $placeholders[] = ':'.$nombre;
            $params[$nombre] = $id;
        }

        $sql = '
        SELECT
            pr.id_recepcion_unidad,
            pr.id_proveedor_minero,
            p.razon_social,
            p.ruc
        FROM
            proveedor_recepcion pr
        LEFT JOIN proveedor p ON p.id = pr.id_proveedor_minero
        WHERE pr.id_recepcion_unidad IN ('.implode(', ', $placeholders).')
        ORDER BY pr.id_recepcion_unidad ASC, pr.id ASC
        ';

        $rows = DB::select($sql, $params);

        $porRecepcion = [];
        foreach ($rows as $row) {
            $porRecepcion[(int) $row->id_recepcion_unidad][] = [
                'id_proveedor' => (int) $row->id_proveedor_minero,
                'razon_social' => $row->razon_social !== null ? (string) $row->razon_social : '',
                'ruc' => $row->ruc !== null ? (string) $row->ruc : null,
            ];
        }

        return $porRecepcion;
    }

    /**
     * Proveedores de una recepción (en el orden en que se registraron).
     *
     * @return array<int, array{id_proveedor: int, razon_social: string, ruc: ?string}>
     */
    public static function get_proveedores_recepcion(int $idRecepcion): array
    {
        return self::get_proveedores_por_recepcion([$idRecepcion])[$idRecepcion] ?? [];
    }

    /**
     * Sincronizar la lista de proveedores de una recepción.
     *
     * Reemplaza por completo las filas de `proveedor_recepcion`: las que se
     * quedan sin listar se eliminan y las nuevas se insertan al final.
     *
     * @param  array<int, int>  $idsProveedor
     * @return array<int, array{id_proveedor: int, razon_social: string, ruc: ?string}>
     *
     * @throws \InvalidArgumentException si algún proveedor no existe o viene duplicado.
     */
    public static function sync_proveedores_recepcion(int $idRecepcion, array $idsProveedor): array
    {
        $ids = [];
        foreach ($idsProveedor as $id) {
            $id = (int) $id;
            if ($id <= 0) {
                continue;
            }
            if (in_array($id, $ids, true)) {
                throw new \InvalidArgumentException('La lista de proveedores contiene el proveedor '.$id.' más de una vez.');
            }
            $ids[] = $id;
        }

        if (! empty($ids)) {
            $existentes = DB::table('proveedor')
                ->whereIn('id', $ids)
                ->pluck('id')
                ->map(static fn ($id) => (int) $id)
                ->all();

            $faltantes = array_values(array_diff($ids, $existentes));
            if (! empty($faltantes)) {
                throw new \InvalidArgumentException('Los siguientes proveedores no existen: '.implode(', ', $faltantes).'.');
            }
        }

        DB::transaction(function () use ($idRecepcion, $ids) {
            DB::table('proveedor_recepcion')
                ->where('id_recepcion_unidad', $idRecepcion)
                ->delete();

            if (empty($ids)) {
                return;
            }

            $filas = [];
            foreach ($ids as $id) {
                $filas[] = [
                    'id_recepcion_unidad' => $idRecepcion,
                    'id_proveedor_minero' => $id,
                ];
            }

            DB::table('proveedor_recepcion')->insert($filas);
        });

        return self::get_proveedores_recepcion($idRecepcion);
    }

    /**
     * Guías normalizadas de una recepción.
     *
     * @return array<int, array{id_proveedor: int, guia_remitente: string, guia_transportista: string, guia_remitente_archivo: ?array, guia_transportista_archivo: ?array, es_interno: bool}>
     */
    public static function get_guias(int $idRecepcion): array
    {
        $valor = DB::table('recepcion_unidad')
            ->where('id', $idRecepcion)
            ->value('guias');

        return self::normalizar_guias($valor);
    }

    /**
     * Validar y normalizar el array de guías recibido, SIN persistirlo.
     *
     * Valida que cada entrada apunte a un proveedor existente y que no haya
     * dos entradas para el mismo proveedor. `id_proveedor = 0` se acepta
     * como "guía de la unidad sin proveedor asignado".
     *
     * @param  array<int, mixed>  $guias
     * @return array<int, array{id_proveedor: int, guia_remitente: string, guia_transportista: string, guia_remitente_archivo: ?array, guia_transportista_archivo: ?array, es_interno: bool}>
     *
     * @throws \InvalidArgumentException si el contrato de las guías no es válido.
     */
    public static function preparar_guias(array $guias): array
    {
        $normalizadas = [];
        $vistos = [];

        foreach ($guias as $entrada) {
            if (! is_array($entrada)) {
                throw new \InvalidArgumentException('Cada guía debe ser un objeto con los datos de su proveedor.');
            }

            // Una guía interna la emite la propia empresa: no lleva proveedor,
            // así que varias pueden convivir (no colisionan entre sí).
            $esInterno = (bool) ($entrada['es_interno'] ?? false);

            $idProveedor = isset($entrada['id_proveedor']) ? (int) $entrada['id_proveedor'] : 0;
            if ($idProveedor < 0) {
                throw new \InvalidArgumentException('Cada guía debe indicar el proveedor al que corresponde (id_proveedor).');
            }

            if (! $esInterno && isset($vistos[$idProveedor])) {
                throw new \InvalidArgumentException('Hay más de una entrada de guía para el mismo proveedor (id_proveedor '.$idProveedor.').');
            }
            if (! $esInterno) {
                $vistos[$idProveedor] = true;
            }

            $normalizadas[] = [
                'id_proveedor' => $idProveedor,
                'guia_remitente' => isset($entrada['guia_remitente']) && $entrada['guia_remitente'] !== null
                    ? trim((string) $entrada['guia_remitente'])
                    : '',
                'guia_transportista' => isset($entrada['guia_transportista']) && $entrada['guia_transportista'] !== null
                    ? trim((string) $entrada['guia_transportista'])
                    : '',
                'guia_remitente_archivo' => self::normalizar_archivo_guia($entrada['guia_remitente_archivo'] ?? null),
                'guia_transportista_archivo' => self::normalizar_archivo_guia($entrada['guia_transportista_archivo'] ?? null),
                // Guía interna: la emite la propia empresa, sin proveedor.
                'es_interno' => (bool) ($entrada['es_interno'] ?? false),
            ];
        }

        $idsProveedor = array_values(array_filter(
            array_column($normalizadas, 'id_proveedor'),
            static fn (int $id): bool => $id > 0
        ));

        if (! empty($idsProveedor)) {
            $existentes = DB::table('proveedor')
                ->whereIn('id', $idsProveedor)
                ->pluck('id')
                ->map(static fn ($id) => (int) $id)
                ->all();

            $faltantes = array_values(array_diff($idsProveedor, $existentes));
            if (! empty($faltantes)) {
                throw new \InvalidArgumentException('Los siguientes proveedores no existen: '.implode(', ', $faltantes).'.');
            }
        }

        return $normalizadas;
    }

    /**
     * Reemplazar por completo el array `guias` de una recepción.
     *
     * Valida que cada entrada apunte a un proveedor existente y que no haya
     * dos entradas para el mismo proveedor.
     *
     * @param  array<int, mixed>  $guias
     * @return array<int, array{id_proveedor: int, guia_remitente: string, guia_transportista: string, guia_remitente_archivo: ?array, guia_transportista_archivo: ?array, es_interno: bool}>
     *
     * @throws \InvalidArgumentException si el contrato de las guías no es válido.
     */
    public static function reemplazar_guias(int $idRecepcion, array $guias): array
    {
        $normalizadas = self::preparar_guias($guias);

        DB::table('recepcion_unidad')
            ->where('id', $idRecepcion)
            ->update(['guias' => json_encode($normalizadas)]);

        return $normalizadas;
    }

    /**
     * Guías de SALIDA normalizadas de una recepción.
     *
     * Mismo contrato que las guías de ingreso (`guias`), pero en la columna
     * `guias_salida`: se registran al sacar la unidad de planta.
     *
     * @return array<int, array{id_proveedor: int, guia_remitente: string, guia_transportista: string, guia_remitente_archivo: ?array, guia_transportista_archivo: ?array, es_interno: bool}>
     */
    public static function get_guias_salida(int $idRecepcion): array
    {
        $valor = DB::table('recepcion_unidad')
            ->where('id', $idRecepcion)
            ->value('guias_salida');

        return self::normalizar_guias($valor);
    }

    /**
     * Reemplazar por completo el array `guias_salida` de una recepción.
     *
     * Valida con el mismo contrato que las guías de ingreso: cada entrada
     * apunta a un proveedor existente y no hay dos entradas para el mismo
     * proveedor.
     *
     * @param  array<int, mixed>  $guias
     * @return array<int, array{id_proveedor: int, guia_remitente: string, guia_transportista: string, guia_remitente_archivo: ?array, guia_transportista_archivo: ?array, es_interno: bool}>
     *
     * @throws \InvalidArgumentException si el contrato de las guías no es válido.
     */
    public static function reemplazar_guias_salida(int $idRecepcion, array $guias): array
    {
        $normalizadas = self::preparar_guias($guias);

        DB::table('recepcion_unidad')
            ->where('id', $idRecepcion)
            ->update(['guias_salida' => json_encode($normalizadas)]);

        return $normalizadas;
    }

    /**
     * Hidratar el contrato de guías y proveedores de una recepción.
     *
     * Expone `guias` y `proveedores` (el modelo nuevo) y mantiene los campos
     * legacy (`id_proveedor_minero`, `proveedor_razon_social`, `proveedor_ruc`,
     * `guia_remitente`, `guia_transportista`, `documentos_programacion`)
     * derivados del proveedor principal y de su guía, para no romper a los
     * consumidores que todavía los usan.
     *
     * @param  array<int, array{id_proveedor: int, razon_social: string, ruc: ?string}>  $proveedores
     */
    private static function hidratar_contrato_guias(object $item, array $proveedores): void
    {
        $guias = self::normalizar_guias($item->guias ?? null);

        $item->guias = $guias;
        $item->proveedores = array_values($proveedores);

        $principal = $item->proveedores[0] ?? null;
        $item->id_proveedor_minero = $principal !== null ? $principal['id_proveedor'] : null;
        $item->proveedor_razon_social = $principal !== null ? $principal['razon_social'] : null;
        $item->proveedor_ruc = $principal !== null ? $principal['ruc'] : null;

        $guia = self::guia_principal($guias, $item->id_proveedor_minero);
        $item->guia_remitente = ($guia['guia_remitente'] ?? '') !== '' ? $guia['guia_remitente'] : null;
        $item->guia_transportista = ($guia['guia_transportista'] ?? '') !== '' ? $guia['guia_transportista'] : null;
        $item->documentos_programacion = [
            'guia_remitente' => $guia['guia_remitente_archivo'] ?? null,
            'guia_transportista' => $guia['guia_transportista_archivo'] ?? null,
        ];
    }

    /**
     * Guía principal de una recepción: la del proveedor principal si existe,
     * o la primera entrada del array.
     *
     * @param  array<int, array{id_proveedor: int, guia_remitente: string, guia_transportista: string, guia_remitente_archivo: ?array, guia_transportista_archivo: ?array, es_interno: bool}>  $guias
     * @return array{id_proveedor: int, guia_remitente: string, guia_transportista: string, guia_remitente_archivo: ?array, guia_transportista_archivo: ?array, es_interno: bool}|null
     */
    public static function guia_principal(array $guias, ?int $idProveedor): ?array
    {
        if (empty($guias)) {
            return null;
        }

        if ($idProveedor !== null) {
            foreach ($guias as $guia) {
                if ((int) $guia['id_proveedor'] === $idProveedor) {
                    return $guia;
                }
            }
        }

        return $guias[0];
    }

    /**
     * Guía principal de una recepción a partir del JSON crudo de `guias`.
     *
     * Si se indica el proveedor se busca su entrada; si no hay, se devuelve la
     * primera del array.
     *
     * @return array{id_proveedor: int, guia_remitente: string, guia_transportista: string, guia_remitente_archivo: ?array, guia_transportista_archivo: ?array, es_interno: bool}|null
     */
    public static function guia_principal_de_json(mixed $valorGuias, ?int $idProveedor = null): ?array
    {
        return self::guia_principal(self::normalizar_guias($valorGuias), $idProveedor);
    }

    /**
     * Validar que el string de guía (remitente o transportista) no esté repetido para el mismo proveedor.
     *
     * Fuentes de verdad comparadas:
     *  1. `guia_primer_tramo`: guía ya registrada en el ciclo de guías (la
     *     fuente real; su duplicado también lo valida el módulo de guías de
     *     primer tramo).
     *  2. `recepcion_unidad.guias`: números capturados en la programación de
     *     otras recepciones del mismo proveedor.
     *
     * Se excluye la propia recepción indicada por $excluirId (para updates).
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
            $valor = trim($valor);

            if (self::existe_guia_registrada_proveedor($idProveedor, $column, $valor, $excluirId)) {
                $etiqueta = $column === 'guia_remitente' ? 'remitente' : 'transportista';

                return "Ya existe una recepción del mismo proveedor con la misma guía {$etiqueta}.";
            }

            if (self::existe_guia_en_otra_recepcion($idProveedor, $column, $valor, $excluirId)) {
                $etiqueta = $column === 'guia_remitente' ? 'remitente' : 'transportista';

                return "Ya existe una recepción del mismo proveedor con la misma guía {$etiqueta}.";
            }
        }

        return null;
    }

    /**
     * ¿Existe la guía en `guia_primer_tramo` para ese proveedor?
     *
     * Las guías ya registradas en la propia recepción se excluyen: son las
     * que se están editando, no un conflicto.
     */
    private static function existe_guia_registrada_proveedor(
        int $idProveedor,
        string $column,
        string $valor,
        ?int $excluirId,
    ): bool {
        $sql = '
        SELECT gui.id
        FROM guia_primer_tramo gui
        WHERE gui.estado = :estado_activo
          AND gui.id_proveedor = :id_proveedor
          AND gui.'.$column.' = :valor
        ';

        $params = [
            'estado_activo' => EstadoBase::Activo->value,
            'id_proveedor' => $idProveedor,
            'valor' => $valor,
        ];

        if ($excluirId !== null) {
            $sql .= ' AND (gui.id_recepcion_unidad IS NULL OR gui.id_recepcion_unidad <> :excluir)';
            $params['excluir'] = $excluirId;
        }

        return DB::selectOne($sql, $params) !== null;
    }

    /**
     * ¿Existe la guía en el array `guias` de otra recepción del mismo proveedor?
     *
     * Se recorre el JSON con JSON_TABLE (MySQL 8) para comparar entrada por
     * entrada, ya que una recepción puede tener varias guías.
     */
    private static function existe_guia_en_otra_recepcion(
        int $idProveedor,
        string $column,
        string $valor,
        ?int $excluirId,
    ): bool {
        $sql = '
        SELECT ru.id
        FROM recepcion_unidad ru
        INNER JOIN JSON_TABLE(
            COALESCE(ru.guias, JSON_ARRAY()),
            \'$[*]\' COLUMNS (
                idx FOR ORDINALITY,
                id_proveedor INT PATH \'$.id_proveedor\' NULL ON EMPTY,
                guia_remitente VARCHAR(64) PATH \'$.guia_remitente\' NULL ON EMPTY NULL ON ERROR,
                guia_transportista VARCHAR(64) PATH \'$.guia_transportista\' NULL ON EMPTY NULL ON ERROR
            )
        ) g ON 1 = 1
        WHERE ru.es_recepcion_ficticia = 0
          AND g.id_proveedor = :id_proveedor
          AND g.'.$column.' = :valor
        ';

        $params = [
            'id_proveedor' => $idProveedor,
            'valor' => $valor,
        ];

        if ($excluirId !== null) {
            $sql .= ' AND ru.id <> :excluir';
            $params['excluir'] = $excluirId;
        }

        return DB::selectOne($sql, $params) !== null;
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
     *
     * Los proveedores se guardan en `proveedor_recepcion` y las guías por
     * proveedor en la columna JSON `guias`.
     */
    public static function crear_recepcion(array $data): int
    {
        $ticket = self::generar_ticket_recepcion_unidad();

        $guias = [];
        foreach (($data['guias'] ?? []) as $guia) {
            if (is_array($guia)) {
                $guias[] = $guia;
            }
        }

        $recepcion = RecepcionUnidad::create([
            'id_empleado_recepcion' => $data['id_empleado_registro'],
            'id_vehiculo' => $data['id_vehiculo'] ?? null,
            'id_vehiculo_carreta' => $data['id_vehiculo_carreta'] ?? null,
            'id_empresa_transporte' => $data['id_empresa_transporte'],
            'id_tipo_vehiculo' => $data['id_tipo_vehiculo'],
            'id_conductor' => $data['id_conductor'],
            'tipo_ingreso' => $data['tipo_ingreso'] ?? 'Recepción de Mineral',
            'fecha_hora_ingreso' => now()->toDateTimeString(),
            'evidencias' => $data['evidencias'] ?? [],
            'observacion' => $data['observacion'] ?? null,
            'estado' => EstadoVisita::EnPlanta->value,
            'id_sucursal' => $data['id_sucursal'],
            'estado_pesaje' => EstadoPesaje::SinPesar->value,
            'guias' => empty($guias) ? null : $guias,
            'estado_ingreso' => $data['estado_ingreso'] ?? null,
            'id_ticket_recepcion_unidades' => $ticket['id'],
        ]);

        $proveedores = [];
        foreach (($data['proveedores'] ?? []) as $idProveedor) {
            $idProveedor = (int) $idProveedor;
            if ($idProveedor > 0 && ! in_array($idProveedor, $proveedores, true)) {
                $proveedores[] = $idProveedor;
            }
        }

        if (! empty($proveedores)) {
            self::sync_proveedores_recepcion((int) $recepcion->id, $proveedores);
        }

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
            longitudCeros: 3,
            reseteo: Periodo::Anual,
            estado: EstadoBase::Activo->value,
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
            // Todos los lotes nacen pendientes de muestreo.
            'con_codigo_temporal' => true,
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
     *
     * El ticket se puede acotar a UN proveedor de la carga (`$idProveedor`) y/o
     * a UNA guía de primer tramo concreta (`$idGuia`). La guía identifica al
     * proveedor: si viene una guía válida (existe y está `Activo`), su
     * `id_proveedor` manda sobre el `$idProveedor` indicado. Si la guía no
     * existe o no está `Activo`, se ignora y se sigue con `$idProveedor`.
     *
     * La pertenencia del proveedor a la unidad se valida contra la CARGA de
     * la unidad (lotes + particiones con su unidad resuelta), NO contra la
     * tabla puente `proveedor_recepcion`: esa tabla la captura el operador
     * al ingresar la unidad y puede quedar vacía aunque la unidad sí traiga
     * mineral.
     *
     * Sin `$idProveedor` el comportamiento es el de siempre: el proveedor
     * principal de la unidad (el del puente; si la unidad no tiene puente,
     * el primero de su carga) y la primera guía de primer tramo.
     */
    public static function get_ticket_ingreso_info(int $idRecepcionUnidad, ?int $idGuia = null, ?int $idProveedor = null): ?array
    {
        $ticket = self::asegurar_ticket_recepcion_unidad($idRecepcionUnidad);
        if (! $ticket) {
            return null;
        }

        // Guía de primer tramo concreta (si viene): identifica al proveedor.
        $guia = null;
        if ($idGuia !== null) {
            $guia = DB::table('guia_primer_tramo')
                ->where('id', $idGuia)
                ->where('estado', EstadoBase::Activo->value)
                ->first();
        }

        // Proveedor del ticket. Sólo se acota cuando el caller lo pide: sin
        // `$idProveedor` el ticket sigue saliendo con el proveedor principal.
        $idProveedorTicket = null;
        if ($idProveedor !== null) {
            $idProveedorTicket = $idProveedor;

            if ($guia !== null && isset($guia->id_proveedor)) {
                $idProveedorTicket = (int) $guia->id_proveedor;
            }
        }

        // --- Proveedor del ticket ---
        // La CARGA de la unidad es la fuente de verdad de a qué proveedor
        // pertenece el mineral que trajo el vehículo.
        $proveedoresCarga = LotesMineralDisponiblesData::get_proveedores_carga_unidad($idRecepcionUnidad);

        $proveedorTicket = null;
        if ($idProveedorTicket !== null) {
            // El proveedor pedido tiene que ser de la carga de esta unidad.
            foreach ($proveedoresCarga as $proveedor) {
                if ((int) $proveedor['id_proveedor'] === $idProveedorTicket) {
                    $proveedorTicket = $proveedor;
                    break;
                }
            }

            if ($proveedorTicket === null) {
                return null;
            }
        } else {
            // Proveedor principal: el que registró el operador en el puente de
            // la unidad y, si la unidad no tiene puente, el primero de su carga.
            $proveedoresPuente = self::get_proveedores_recepcion($idRecepcionUnidad);
            $proveedorTicket = $proveedoresPuente[0] ?? ($proveedoresCarga[0] ?? null);
        }

        $params = [
            'id' => $idRecepcionUnidad,
            'id_proveedor_ps' => $proveedorTicket !== null ? (int) $proveedorTicket['id_proveedor'] : null,
        ];

        // El lote se toma con MIN(id) sobre los lotes de ESE proveedor.
        if ($idProveedorTicket !== null) {
            $params['id_proveedor_lm'] = $idProveedorTicket;

            $sqlLote = <<<'SQL'
                SELECT id_recepcion_unidad, MIN(id) AS id_primer_lote
                FROM lote_mineral
                WHERE id_proveedor_minero = :id_proveedor_lm
                GROUP BY id_recepcion_unidad
            SQL;
        } else {
            $sqlLote = <<<'SQL'
                SELECT id_recepcion_unidad, MIN(id) AS id_primer_lote
                FROM lote_mineral
                GROUP BY id_recepcion_unidad
            SQL;
        }

        $sql = <<<SQL
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
                CONCAT(COALESCE(c.nombre, ''), ' ', COALESCE(c.apellido, '')) AS conductor_nombre,
                c.numero_licencia                    AS conductor_licencia,

                -- Guias, fechas, producto y observaciones
                ru.guias                             AS guias,
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
            -- El proveedor del ticket ya está resuelto (uno solo): se une
            -- directamente para NO multiplicar las filas de la consulta.
            LEFT JOIN proveedor pr ON pr.id = :id_proveedor_ps
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
                {$sqlLote}
            ) lm_first ON lm_first.id_recepcion_unidad = ru.id
            LEFT JOIN lote_mineral lm ON lm.id = lm_first.id_primer_lote
            WHERE ru.id = :id
            LIMIT 1
        SQL;

        $row = DB::selectOne($sql, $params);
        if (! $row) {
            return null;
        }

        // Guías del array JSON: las del proveedor del ticket (el principal si
        // el ticket no está acotado a un proveedor).
        $guias = self::normalizar_guias($row->guias ?? null);
        $guiaPrincipal = self::guia_principal($guias, $row->id_proveedor !== null ? (int) $row->id_proveedor : null);
        $row->guia_remitente = $guiaPrincipal['guia_remitente'] ?? null;
        $row->guia_transportista = $guiaPrincipal['guia_transportista'] ?? null;

        // Guía de primer tramo del ticket:
        //  - la concreta ($idGuia), si se pidió;
        //  - la de ESE proveedor, si el ticket está acotado a un proveedor;
        //  - la primera de la unidad (comportamiento actual).
        if ($idGuia === null) {
            if ($idProveedorTicket !== null) {
                $guia = self::primera_guia_de_proveedor($idRecepcionUnidad, $idProveedorTicket);
            } else {
                $guiasUnidad = self::get_guias_recepcion($idRecepcionUnidad);
                $guia = $guiasUnidad[0] ?? null;
            }
        }

        return self::hydrate_ticket_ingreso($row, $guia);
    }

    /**
     * Tickets de ingreso disponibles de una recepción de unidad.
     *
     * Una fila por (proveedor, guía de primer tramo): si un proveedor tiene dos
     * guías de primer tramo, devuelve dos filas; si no tiene ninguna, devuelve
     * una sola fila con `id_guia_primer_tramo` en null y los números de guía
     * capturados en el array JSON `guias` de ese proveedor.
     *
     * Los proveedores son los de la CARGA de la unidad (lotes + particiones
     * con su unidad resuelta), NO los de la tabla puente
     * `proveedor_recepcion`: el puente lo captura el operador al ingresar la
     * unidad y puede quedar vacía aunque la unidad sí traiga mineral.
     *
     * @return array<int, array{id_proveedor: int, razon_social: string, ruc: ?string, id_guia_primer_tramo: ?int, guia_remitente: ?string, guia_transportista: ?string, peso_guia_remision: float, peso_vehicular_total: float, con_no_conformidad: bool}>
     */
    public static function get_tickets_ingreso(int $idRecepcionUnidad): array
    {
        $proveedores = LotesMineralDisponiblesData::get_proveedores_carga_unidad($idRecepcionUnidad);
        if (empty($proveedores)) {
            return [];
        }

        $guiasUnidad = self::get_guias_recepcion($idRecepcionUnidad);
        $guiasJson = self::get_guias($idRecepcionUnidad);

        $tickets = [];

        foreach ($proveedores as $proveedor) {
            $idProveedor = (int) $proveedor['id_proveedor'];

            $guiasProveedor = [];
            foreach ($guiasUnidad as $guiaUnidad) {
                if ((int) ($guiaUnidad->id_proveedor ?? 0) === $idProveedor) {
                    $guiasProveedor[] = $guiaUnidad;
                }
            }

            if (! empty($guiasProveedor)) {
                foreach ($guiasProveedor as $guia) {
                    $tickets[] = [
                        'id_proveedor' => $idProveedor,
                        'razon_social' => (string) $proveedor['razon_social'],
                        'ruc' => $proveedor['ruc'] !== null ? (string) $proveedor['ruc'] : null,
                        'id_guia_primer_tramo' => (int) $guia->id,
                        'guia_remitente' => self::guia_texto($guia->guia_remitente ?? null),
                        'guia_transportista' => self::guia_texto($guia->guia_transportista ?? null),
                        'peso_guia_remision' => (float) ($guia->peso_guia_remision ?? 0),
                        'peso_vehicular_total' => (float) ($guia->peso_vehicular_total ?? 0),
                        'con_no_conformidad' => (bool) ($guia->con_no_conformidad ?? false),
                    ];
                }

                continue;
            }

            // El proveedor no tiene guía de primer tramo: se listan los
            // números capturados en el array JSON `guias` de la unidad.
            $guiaJson = self::guia_json_de_proveedor($guiasJson, $idProveedor);

            $tickets[] = [
                'id_proveedor' => $idProveedor,
                'razon_social' => (string) $proveedor['razon_social'],
                'ruc' => $proveedor['ruc'] !== null ? (string) $proveedor['ruc'] : null,
                'id_guia_primer_tramo' => null,
                'guia_remitente' => self::guia_texto($guiaJson['guia_remitente'] ?? null),
                'guia_transportista' => self::guia_texto($guiaJson['guia_transportista'] ?? null),
                'peso_guia_remision' => 0.0,
                'peso_vehicular_total' => 0.0,
                'con_no_conformidad' => false,
            ];
        }

        return $tickets;
    }

    /**
     * Primera guía de primer tramo de un proveedor dentro de la unidad.
     */
    private static function primera_guia_de_proveedor(int $idRecepcionUnidad, int $idProveedor): ?object
    {
        foreach (self::get_guias_recepcion($idRecepcionUnidad) as $guia) {
            if ((int) ($guia->id_proveedor ?? 0) === $idProveedor) {
                return $guia;
            }
        }

        return null;
    }

    /**
     * Entrada del array JSON `guias` que pertenece a un proveedor concreto.
     *
     * A diferencia de `guia_principal()`, no cae a la primera entrada del
     * array cuando el proveedor no tiene guías: aquí se exige la coincidencia.
     *
     * @param  array<int, array{id_proveedor: int, guia_remitente: string, guia_transportista: string, guia_remitente_archivo: ?array, guia_transportista_archivo: ?array, es_interno: bool}>  $guias
     * @return array{id_proveedor: int, guia_remitente: string, guia_transportista: string, guia_remitente_archivo: ?array, guia_transportista_archivo: ?array, es_interno: bool}|null
     */
    private static function guia_json_de_proveedor(array $guias, int $idProveedor): ?array
    {
        foreach ($guias as $guia) {
            if ((int) $guia['id_proveedor'] === $idProveedor) {
                return $guia;
            }
        }

        return null;
    }

    /**
     * Normalizar un número de guía: vacío o null se/devuelven como null.
     */
    private static function guia_texto(mixed $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        $texto = trim((string) $valor);

        return $texto === '' ? null : $texto;
    }

    /**
     * Obtener listado de guías de primer tramo asociadas a la recepción.
     *
     * Una guía pertenece a la unidad si su `id_recepcion_unidad` es esa unidad
     * o si sus ítems (lote o partición) pertenecen a esa unidad. Para las
     * PARTICIONES se usa la UNIDAD RESUELTA (la del LOTE PADRE si la recepción
     * propia de la partición es una copia exacta de la del padre): así una
     * partición pesada en el mismo viaje que su padre NO aparece en la
     * recepción ficticia de la partición.
     */
    public static function get_guias_recepcion(int $idRecepcionUnidad): array
    {
        $joinUnidadParticion = LotesMineralDisponiblesData::join_unidad_particion_resuelta('plm', 'unp', true);

        $sql = '
            SELECT DISTINCT
                gpt.id,
                gpt.id_proveedor,
                gpt.guia_remitente,
                gpt.guia_transportista,
                gpt.sin_guia_transportista,
                gpt.con_no_conformidad,
                gpt.peso_guia_remision,
                gpt.peso_vehicular_total,
                gpt.fecha_emision,
                gpt.created_at
            FROM guia_primer_tramo gpt
            LEFT JOIN lote_guia lg ON lg.id_guia_primer_tramo = gpt.id
            LEFT JOIN lote_mineral lm ON lm.id = lg.id_lote_mineral
            LEFT JOIN particion_lote_mineral plm ON plm.id = lg.id_particion_lote_mineral
            '.$joinUnidadParticion.'
            WHERE (gpt.id_recepcion_unidad = :id_rec1 OR lm.id_recepcion_unidad = :id_rec2 OR unp.id_unidad_resuelta = :id_rec3)
              AND gpt.estado = \'Activo\'
            ORDER BY gpt.id ASC
        ';

        $rows = DB::select($sql, [
            'id_rec1' => $idRecepcionUnidad,
            'id_rec2' => $idRecepcionUnidad,
            'id_rec3' => $idRecepcionUnidad,
        ]);

        foreach ($rows as $r) {
            $r->id = (int) $r->id;
            $r->id_proveedor = (int) $r->id_proveedor;
            $r->sin_guia_transportista = (bool) $r->sin_guia_transportista;
            $r->con_no_conformidad = (bool) $r->con_no_conformidad;
            $r->peso_guia_remision = $r->peso_guia_remision !== null ? (float) $r->peso_guia_remision : 0.0;
            $r->peso_vehicular_total = $r->peso_vehicular_total !== null ? (float) $r->peso_vehicular_total : 0.0;
        }

        return $rows;
    }

    /**
     * Convierte el row crudo al shape que requiere el PDF Ticket de Ingreso.
     */
    private static function hydrate_ticket_ingreso(object $row, mixed $guia = null): array
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

        // Pesos documentarios de la guía (en TM)
        $pesoGuiaTm = null;
        $pesoVehicularTotalTm = null;

        if ($guia) {
            $pGuia = isset($guia->peso_guia_remision) ? (float) $guia->peso_guia_remision : 0.0;
            $pVeh = isset($guia->peso_vehicular_total) ? (float) $guia->peso_vehicular_total : 0.0;

            if ($pGuia > 0) {
                $pesoGuiaTm = $pGuia > 200 ? round($pGuia / 1000, 3) : round($pGuia, 3);
            }
            if ($pVeh > 0) {
                $pesoVehicularTotalTm = $pVeh > 200 ? round($pVeh / 1000, 3) : round($pVeh, 3);
            }

            if (! empty($guia->guia_remitente)) {
                $row->guia_remitente = $guia->guia_remitente;
            }
            if (! empty($guia->guia_transportista)) {
                $row->guia_transportista = $guia->guia_transportista;
            }
        }

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
