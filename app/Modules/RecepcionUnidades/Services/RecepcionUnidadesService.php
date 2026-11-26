<?php

namespace App\Modules\RecepcionUnidades\Services;

use App\Models\RecepcionUnidad;
use App\Models\RecepcionVisita;
use App\Models\RecepcionVisitaDetalle;
use App\Modules\ProgramacionDespachos\Data\ProgramacionDespachosData;
use App\Modules\ProgramacionDespachos\Services\ProgramacionDespachosService;
use App\Modules\RecepcionUnidades\Data\RecepcionUnidadesData;
use App\Modules\RecepcionVisitas\Services\RecepcionVisitasService;
use App\Shared\Enums\_Generic\EstadoUnidad;
use App\Shared\Enums\_Generic\EstadoVisita;
use App\Shared\Helpers\ArchivoHelper;
use App\Shared\Helpers\CamposBloqueadosHelper;
use App\Shared\Responses\_Generic\RES_CambiosLog;
use App\Shared\Responses\ApiResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RecepcionUnidadesService
{
    /**
     * Campos que ya no son columnas de `recepcion_unidad`.
     *
     * El proveedor de la unidad vive ahora en `proveedor_recepcion` y las
     * guías en la columna JSON `guias`, así que se editan por sus propios
     * endpoints (`/proveedores` y `/guias`).
     *
     * @var array<int, string>
     */
    private const CAMPOS_RETIRADOS = [
        'id_proveedor_minero',
        'guia_remitente',
        'guia_transportista',
    ];

    /**
     * Obtener listado de recepciones filtradas.
     */
    public static function get_recepciones(array $filters): array
    {
        $data = RecepcionUnidadesData::get_recepciones($filters);

        return ApiResponse::success($data, 'Recepciones obtenidas correctamente');
    }

    /**
     * Obtener una recepción puntual (incluye sus lotes).
     */
    public static function get_recepcion(int $id): array
    {
        $recepcion = RecepcionUnidadesData::get_recepcion_by_id($id);
        if (! $recepcion) {
            return ApiResponse::error('No se encontró el registro de recepción.', 404);
        }

        $recepcion['lotes'] = RecepcionUnidadesData::get_lotes($id);

        return ApiResponse::success($recepcion, 'Recepción obtenida correctamente');
    }

    /**
     * Obtener los datos estructurados para el Ticket de Ingreso de Vehículos con Carga.
     *
     * `idProveedor` acota el ticket a UN proveedor de la carga y `idGuia` a
     * UNA guía de primer tramo concreta (que identifica al proveedor).
     */
    public static function get_ticket_ingreso(int $id, ?int $idGuia = null, ?int $idProveedor = null): array
    {
        $ticketInfo = RecepcionUnidadesData::get_ticket_ingreso_info($id, $idGuia, $idProveedor);
        if (! $ticketInfo) {
            return ApiResponse::error('No se encontró la recepción de unidad o no se pudo generar el ticket.', 404);
        }

        return ApiResponse::success($ticketInfo, 'Ticket de ingreso obtenido correctamente');
    }

    /**
     * Listar los tickets de ingreso disponibles de una recepción de unidad.
     *
     * Una unidad puede traer la carga de varios proveedores: cada uno tiene su
     * propio ticket de ingreso.
     */
    public static function get_tickets_ingreso(int $id): array
    {
        if (! RecepcionUnidad::find($id)) {
            return ApiResponse::error('No se encontró el registro de recepción.', 404);
        }

        $tickets = RecepcionUnidadesData::get_tickets_ingreso($id);

        return ApiResponse::success($tickets, 'Tickets de ingreso obtenidos correctamente');
    }

    /**
     * Obtener las guías asociadas a la recepción.
     */
    public static function get_guias_recepcion(int $id): array
    {
        $guias = RecepcionUnidadesData::get_guias_recepcion($id);

        return ApiResponse::success($guias, 'Guías de la recepción obtenidas correctamente');
    }

    /**
     * Guardar archivos de evidencias y crear el registro de recepción.
     *
     * @param  array  $data  Datos básicos. Claves opcionales para el contrato
     *                       de guías y proveedores:
     *                       - proveedores: int[] (ids de proveedor de la carga)
     *                       - guias: array (una entrada por proveedor)
     *                       - guia_remitente_file: UploadedFile|null
     *                       - guia_transportista_file: UploadedFile|null
     *                       - documentos_programacion_existentes: array con
     *                       los archivos ya adjuntos (formato legacy)
     */
    public static function crear_recepcion(array $data, array $archivos, ?array $visitaData = null): array
    {
        // Resolver el contrato de proveedores y guías del modelo nuevo.
        $data = self::resolver_contrato_guias($data);

        // Validar unicidad de guías por proveedor.
        foreach ($data['guias'] as $guia) {
            $errorGuia = RecepcionUnidadesData::validar_unicidad_guia_proveedor(
                (int) $guia['id_proveedor'],
                $guia['guia_remitente'] ?? null,
                $guia['guia_transportista'] ?? null,
                null,
            );

            if ($errorGuia !== null) {
                return ApiResponse::error($errorGuia, 409);
            }
        }

        // Guardar los archivos de evidencias físicas en storage/app/public/recepciones
        $evidenciasGuardadas = [];
        if (! empty($archivos)) {
            $evidenciasGuardadas = ArchivoHelper::guardarArchivos('recepciones', $archivos);
        }

        $data['evidencias'] = $evidenciasGuardadas;

        $id = RecepcionUnidadesData::crear_recepcion($data);

        // Solo crear recepción de visita si hay visitantes válidos o vehículos acompañantes
        $visitantesValidos = array_filter($visitaData['visitantes'] ?? [], function ($v) {
            $nombre = trim($v['nombre'] ?? '');
            $dni = trim($v['dni'] ?? '');

            return $nombre !== '' || $dni !== '' || ! empty($v['id_visitante']);
        });
        $hasVisitantes = ! empty($visitantesValidos);
        $hasVehiculos = ! empty($visitaData['vehiculos']);

        if ($visitaData && ! empty($visitaData['id_motivo_ingreso']) && ($hasVisitantes || $hasVehiculos)) {
            RecepcionVisitasService::crear_recepcion_para_programacion(
                $data['id_empleado_registro'],
                $id,
                (int) $visitaData['id_motivo_ingreso'],
                $visitaData['observacion'] ?? null,
                array_values($visitantesValidos),
                $visitaData['archivosPorIndice'] ?? [],
                $visitaData['vehiculos'] ?? [],
                $visitaData['archivosVehiculos'] ?? []
            );
        }

        $nuevaRecepcion = RecepcionUnidadesData::get_recepcion_by_id($id);

        // La unidad queda "En Planta" y "Sin Pesar": avisar a la balanza para
        // que la liste sin que el operador recargue la página.
        UnidadesParaPesarNotifier::notificar($id);

        return ApiResponse::success($nuevaRecepcion, 'Recepción de unidad registrada correctamente');
    }

    /**
     * Resolver el contrato de proveedores y guías de la recepción.
     *
     * Acepta el modelo nuevo (`proveedores` + `guias`) y, por compatibilidad,
     * el formulario de registro actual que manda un único proveedor y un
     * único par de guías planas (`id_proveedor_minero`, `guia_remitente`,
     * `guia_transportista`) junto con sus archivos.
     *
     * En recepción solo se capturan las guías; los archivos se definen en la
     * programación. Los archivos que llegue el formulario actual se adjuntan a
     * la guía del proveedor para no perderlos.
     */
    private static function resolver_contrato_guias(array $data): array
    {
        $guias = [];
        foreach (($data['guias'] ?? []) as $entrada) {
            if (is_array($entrada)) {
                $guias[] = $entrada;
            }
        }

        // Archivos nuevos por posición de la guía: cada proveedor aporta el
        // suyo (`guias_archivos[<índice>][guia_remitente|guia_transportista]`).
        $archivosPorIndice = is_array($data['guias_archivos'] ?? null)
            ? $data['guias_archivos']
            : [];
        $guias = self::resolver_archivos_guias($guias, $archivosPorIndice);

        $proveedores = [];
        foreach (($data['proveedores'] ?? []) as $idProveedor) {
            $idProveedor = (int) $idProveedor;
            if ($idProveedor > 0 && ! in_array($idProveedor, $proveedores, true)) {
                $proveedores[] = $idProveedor;
            }
        }

        $idProveedorLegacy = isset($data['id_proveedor_minero']) ? (int) $data['id_proveedor_minero'] : 0;
        if ($idProveedorLegacy > 0 && ! in_array($idProveedorLegacy, $proveedores, true)) {
            $proveedores[] = $idProveedorLegacy;
        }

        if (empty($guias) && $idProveedorLegacy > 0) {
            $guias[] = [
                'id_proveedor' => $idProveedorLegacy,
                'guia_remitente' => $data['guia_remitente'] ?? '',
                'guia_transportista' => $data['guia_transportista'] ?? '',
            ];
        }

        $guiaRemitenteFile = $data['guia_remitente_file'] ?? null;
        $guiaTransportistaFile = $data['guia_transportista_file'] ?? null;
        $existentes = is_array($data['documentos_programacion_existentes'] ?? null)
            ? $data['documentos_programacion_existentes']
            : null;

        $hayArchivoNuevo = ($guiaRemitenteFile instanceof UploadedFile && $guiaRemitenteFile->isValid())
            || ($guiaTransportistaFile instanceof UploadedFile && $guiaTransportistaFile->isValid());

        if (($hayArchivoNuevo || $existentes !== null) && ! empty($guias)) {
            $indice = count($guias) - 1;

            if ($existentes !== null) {
                $guias[$indice]['guia_remitente_archivo'] = $existentes['guia_remitente'] ?? null;
                $guias[$indice]['guia_transportista_archivo'] = $existentes['guia_transportista'] ?? null;
            }

            if ($guiaRemitenteFile instanceof UploadedFile && $guiaRemitenteFile->isValid()) {
                $subidos = ArchivoHelper::guardarArchivos('documentos-programacion', [$guiaRemitenteFile]);
                $guias[$indice]['guia_remitente_archivo'] = $subidos[0] ?? null;
            }
            if ($guiaTransportistaFile instanceof UploadedFile && $guiaTransportistaFile->isValid()) {
                $subidos = ArchivoHelper::guardarArchivos('documentos-programacion', [$guiaTransportistaFile]);
                $guias[$indice]['guia_transportista_archivo'] = $subidos[0] ?? null;
            }
        }

        unset(
            $data['guia_remitente_file'],
            $data['guia_transportista_file'],
            $data['documentos_programacion_existentes'],
            $data['guias_archivos'],
        );

        $data['proveedores'] = $proveedores;
        $data['guias'] = $guias;

        return $data;
    }

    /**
     * Proveedores de la carga segun el contrato de guias.
     *
     * La guia de la unidad (`id_proveedor = 0`) no aporta proveedor.
     *
     * @param  array<int, array<string, mixed>>  $guias
     * @return array<int, int>
     */
    private static function proveedores_de_guias(array $guias): array
    {
        $ids = [];
        foreach ($guias as $guia) {
            if (! is_array($guia)) {
                continue;
            }

            $idProveedor = (int) ($guia['id_proveedor'] ?? 0);
            if ($idProveedor > 0 && ! in_array($idProveedor, $ids, true)) {
                $ids[] = $idProveedor;
            }
        }

        return $ids;
    }

    /**
     * Alinear el contrato de guias con la lista de proveedores de la carga.
     *
     * - Cada proveedor de la carga tiene su entrada de guia (aunque vacia).
     * - Las guias de proveedores que ya no estan en la carga se descartan.
     * - La guia de la unidad (`id_proveedor = 0`) se conserva siempre.
     *
     * @param  array<int, array<string, mixed>>  $guias
     * @param  array<int, int>  $idsProveedor
     * @return array<int, array<string, mixed>>
     */
    private static function alinear_guias(array $guias, array $idsProveedor): array
    {
        $alineadas = [];
        $vistos = [];

        foreach ($guias as $guia) {
            if (! is_array($guia)) {
                continue;
            }

            $idProveedor = (int) ($guia['id_proveedor'] ?? 0);

            if ($idProveedor === 0) {
                $alineadas[] = $guia;

                continue;
            }

            if (! in_array($idProveedor, $idsProveedor, true)) {
                continue;
            }

            if (isset($vistos[$idProveedor])) {
                continue;
            }
            $vistos[$idProveedor] = true;

            $alineadas[] = $guia;
        }

        foreach ($idsProveedor as $idProveedor) {
            if (isset($vistos[$idProveedor])) {
                continue;
            }

            $alineadas[] = [
                'id_proveedor' => $idProveedor,
                'guia_remitente' => '',
                'guia_transportista' => '',
                'guia_remitente_archivo' => null,
                'guia_transportista_archivo' => null,
                'es_interno' => false,
            ];
        }

        return $alineadas;
    }

    /**
     * Listar los proveedores de una recepción de unidad.
     */
    public static function get_proveedores(int $id): array
    {
        if (! RecepcionUnidad::find($id)) {
            return ApiResponse::error('No se encontró el registro de recepción.', 404);
        }

        $proveedores = RecepcionUnidadesData::get_proveedores_recepcion($id);

        return ApiResponse::success($proveedores, 'Proveedores de la recepción obtenidos correctamente');
    }

    /**
     * Sincronizar la lista de proveedores de una recepción de unidad.
     *
     * Un proveedor que ya tiene cargas registradas en la unidad no se puede
     * quitar: el mineral pertenece a ese proveedor. Las guías de los
     * proveedores que sí se quitan se eliminan del contrato, para que no
     * queden guías huérfanas sin proveedor en la carga.
     *
     * @param  array<int, int>  $idsProveedor
     */
    public static function set_proveedores(int $id, array $idsProveedor, int $idEmpleado): array
    {
        $recepcion = RecepcionUnidad::find($id);
        if (! $recepcion) {
            return ApiResponse::error('No se encontró el registro de recepción.', 404);
        }

        $anteriores = RecepcionUnidadesData::get_proveedores_recepcion($id);
        $nuevosIds = [];
        foreach ($idsProveedor as $idProveedor) {
            $idProveedor = (int) $idProveedor;
            if ($idProveedor > 0 && ! in_array($idProveedor, $nuevosIds, true)) {
                $nuevosIds[] = $idProveedor;
            }
        }

        foreach ($anteriores as $proveedor) {
            $idProveedor = (int) $proveedor['id_proveedor'];
            if (in_array($idProveedor, $nuevosIds, true)) {
                continue;
            }

            $cargas = self::cargas_de_proveedor($id, $idProveedor);
            if (! empty($cargas)) {
                return ApiResponse::error(
                    'No se puede quitar '.$proveedor['razon_social'].' porque la unidad ya tiene '
                    .implode(' y ', $cargas).' con ese proveedor.',
                    422
                );
            }
        }

        try {
            $proveedores = DB::transaction(function () use ($id, $nuevosIds, $recepcion, $idEmpleado, $anteriores): array {
                $proveedores = RecepcionUnidadesData::sync_proveedores_recepcion($id, $nuevosIds);

                self::eliminar_guias_de_proveedores_quitados($recepcion, $anteriores, $nuevosIds, $idEmpleado);

                return $proveedores;
            });
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }

        $cambios = self::diff_proveedores($anteriores, $proveedores);
        if (! empty($cambios)) {
            self::registrar_log_cambios(
                $recepcion,
                $idEmpleado,
                'Edición de proveedores de la recepción',
                $cambios
            );
        }

        return ApiResponse::success($proveedores, 'Proveedores de la recepción actualizados correctamente');
    }

    /**
     * Cargas ya registradas en la unidad para un proveedor.
     *
     * @return array<int, string> Descripción de las cargas encontradas.
     */
    private static function cargas_de_proveedor(int $idRecepcion, int $idProveedor): array
    {
        $lotes = DB::table('lote_mineral')
            ->where('id_recepcion_unidad', $idRecepcion)
            ->where('id_proveedor_minero', $idProveedor)
            ->count();

        $particiones = DB::table('particion_lote_mineral as plm')
            ->join('lote_mineral as lm', 'lm.id', '=', 'plm.id_lote_mineral')
            ->where('plm.id_recepcion_unidad', $idRecepcion)
            ->where('lm.id_proveedor_minero', $idProveedor)
            ->count();

        $descripciones = [];
        if ($lotes > 0) {
            $descripciones[] = $lotes.($lotes === 1 ? ' lote' : ' lotes');
        }
        if ($particiones > 0) {
            $descripciones[] = $particiones.($particiones === 1 ? ' partición' : ' particiones');
        }

        return $descripciones;
    }

    /**
     * Eliminar del contrato de guías las entradas de los proveedores que se
     * quitaron de la carga.
     *
     * La guía de la unidad (`id_proveedor = 0`) se conserva siempre: no
     * pertenece a ningún proveedor de la carga.
     *
     * @param  array<int, array{id_proveedor: int, razon_social: string, ruc: ?string}>  $anteriores
     * @param  array<int, int>  $nuevosIds
     */
    private static function eliminar_guias_de_proveedores_quitados(
        RecepcionUnidad $recepcion,
        array $anteriores,
        array $nuevosIds,
        int $idEmpleado
    ): void {
        $guias = RecepcionUnidadesData::get_guias($recepcion->id);
        if (empty($guias)) {
            return;
        }

        $conservadas = [];
        $eliminadas = [];
        foreach ($guias as $guia) {
            $idProveedor = (int) $guia['id_proveedor'];
            if ($idProveedor === 0 || in_array($idProveedor, $nuevosIds, true)) {
                $conservadas[] = $guia;

                continue;
            }

            $eliminadas[] = $guia;
        }

        if (empty($eliminadas)) {
            return;
        }

        RecepcionUnidadesData::reemplazar_guias($recepcion->id, $conservadas);

        $razones = [];
        foreach ($anteriores as $proveedor) {
            $idProveedor = (int) $proveedor['id_proveedor'];
            if (in_array($idProveedor, $nuevosIds, true)) {
                continue;
            }
            foreach ($eliminadas as $guia) {
                if ((int) $guia['id_proveedor'] === $idProveedor) {
                    $razones[$idProveedor] = $proveedor['razon_social'];
                    break;
                }
            }
        }

        $cambios = [];
        foreach ($eliminadas as $guia) {
            $cambios[] = [
                'campo_bd' => 'guias',
                'campo' => 'Guía eliminada',
                'valor_anterior' => self::resumen_guia($guia),
                'valor_nuevo' => null,
            ];
        }

        self::registrar_log_cambios(
            $recepcion,
            $idEmpleado,
            'Guías retiradas al quitar proveedores de la carga',
            $cambios
        );
    }

    /**
     * Obtener las guías normalizadas de una recepción de unidad.
     */
    public static function get_guias(int $id): array
    {
        if (! RecepcionUnidad::find($id)) {
            return ApiResponse::error('No se encontró el registro de recepción.', 404);
        }

        $guias = RecepcionUnidadesData::get_guias($id);

        return ApiResponse::success($guias, 'Guías de la recepción obtenidas correctamente');
    }

    /**
     * Reemplazar el array de guías de una recepción de unidad.
     *
     * @param  array<int, mixed>  $guias
     * @param  array<int, mixed>  $archivosPorIndice  Archivos nuevos por
     *                                              posición de la guía
     *                                              (`guia_remitente`,
     *                                              `guia_transportista`).
     */
    public static function set_guias(int $id, array $guias, int $idEmpleado, array $archivosPorIndice = []): array
    {
        $recepcion = RecepcionUnidad::find($id);
        if (! $recepcion) {
            return ApiResponse::error('No se encontró el registro de recepción.', 404);
        }

        // Los archivos nuevos se adjuntan a su guía antes de validar: el
        // contrato guardado es el mismo que se envía.
        $guias = self::resolver_archivos_guias($guias, $archivosPorIndice);

        $anteriores = RecepcionUnidadesData::get_guias($id);

        try {
            $normalizadas = RecepcionUnidadesData::preparar_guias($guias);
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }

        // Las guías registradas en una PROGRAMACIÓN quedan bloqueadas: no se
        // pueden modificar desde la recepción. Una programación que todavía
        // no confirmó la llegada sí puede editar sus propias guías.
        $esProgramacionPendiente = (bool) $recepcion->es_programacion
            && empty($recepcion->id_empleado_recepcion);

        $errorBloqueo = $esProgramacionPendiente
            ? null
            : self::validar_guias_bloqueadas(
                $anteriores,
                $normalizadas,
                CamposBloqueadosHelper::normalizar($recepcion->campos_bloqueados),
            );
        if ($errorBloqueo !== null) {
            return ApiResponse::error($errorBloqueo, 422);
        }

        // Unicidad de guías por proveedor contra el resto de recepciones.
        // Se valida ANTES de escribir para no dejar guides inválidas.
        foreach ($normalizadas as $guia) {
            $errorGuia = RecepcionUnidadesData::validar_unicidad_guia_proveedor(
                (int) $guia['id_proveedor'],
                $guia['guia_remitente'],
                $guia['guia_transportista'],
                $id,
            );

            if ($errorGuia !== null) {
                return ApiResponse::error($errorGuia, 409);
            }
        }

        try {
            $normalizadas = RecepcionUnidadesData::reemplazar_guias($id, $guias);
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }

        $cambios = self::diff_guias($anteriores, $normalizadas);
        if (! empty($cambios)) {
            self::registrar_log_cambios(
                $recepcion,
                $idEmpleado,
                'Edición de guías de la recepción',
                $cambios
            );
        }

        return ApiResponse::success($normalizadas, 'Guías de la recepción actualizadas correctamente');
    }

    /**
     * Adjuntar a cada guía los archivos recién subidos.
     *
     * Los archivos llegan indexados por la posición de la guía dentro del
     * array (`guias_archivos[0][guia_remitente]`). Si no hay archivo nuevo
     * se conserva el que ya tenía guardado.
     *
     * @param  array<int, mixed>  $guias
     * @param  array<int, mixed>  $archivosPorIndice
     * @return array<int, mixed>
     */
    private static function resolver_archivos_guias(array $guias, array $archivosPorIndice): array
    {
        if (empty($archivosPorIndice)) {
            return $guias;
        }

        foreach ($guias as $indice => $guia) {
            if (! is_array($guia)) {
                continue;
            }

            $archivos = $archivosPorIndice[$indice] ?? null;
            if (! is_array($archivos)) {
                continue;
            }

            $guia['guia_remitente_archivo'] = self::resolver_archivo_guia(
                $guia['guia_remitente_archivo'] ?? null,
                $archivos['guia_remitente'] ?? null,
            );
            $guia['guia_transportista_archivo'] = self::resolver_archivo_guia(
                $guia['guia_transportista_archivo'] ?? null,
                $archivos['guia_transportista'] ?? null,
            );

            $guias[$indice] = $guia;
        }

        return $guias;
    }

    /**
     * Resolver el archivo final de una guía: el ya guardado o el recién
     * subido (que se persiste en el disco público).
     */
    private static function resolver_archivo_guia(mixed $existente, mixed $nuevo): mixed
    {
        if ($nuevo instanceof UploadedFile && $nuevo->isValid()) {
            $subidos = ArchivoHelper::guardarArchivos('guias-recepcion', [$nuevo]);

            return $subidos[0] ?? null;
        }

        return $existente;
    }

    /**
     * Verificar que las guías bloqueadas por una programación no cambien.
     *
     * @param  array<int, array<string, mixed>>  $anteriores
     * @param  array<int, array<string, mixed>>  $nuevas
     * @param  array<int, string>  $bloqueados
     */
    private static function validar_guias_bloqueadas(array $anteriores, array $nuevas, array $bloqueados): ?string
    {
        if (empty($bloqueados)) {
            return null;
        }

        $mapaAnterior = [];
        foreach ($anteriores as $guia) {
            $mapaAnterior[(int) $guia['id_proveedor']] = $guia;
        }

        foreach ($nuevas as $guia) {
            $anterior = $mapaAnterior[(int) $guia['id_proveedor']] ?? null;
            if ($anterior === null) {
                continue;
            }

            if (
                in_array('guia_remitente', $bloqueados, true)
                && (string) ($anterior['guia_remitente'] ?? '') !== (string) ($guia['guia_remitente'] ?? '')
            ) {
                return 'La guía remitente se registró en la programación y no se puede modificar.';
            }

            if (
                in_array('guia_transportista', $bloqueados, true)
                && (string) ($anterior['guia_transportista'] ?? '') !== (string) ($guia['guia_transportista'] ?? '')
            ) {
                return 'La guía transportista se registró en la programación y no se puede modificar.';
            }
        }

        return null;
    }

    /**
     * Diferencias entre la lista de proveedores anterior y la nueva.
     *
     * @param  array<int, array{id_proveedor: int, razon_social: string, ruc: ?string}>  $anteriores
     * @param  array<int, array{id_proveedor: int, razon_social: string, ruc: ?string}>  $nuevos
     * @return array<int, array{campo_bd: ?string, campo: ?string, valor_anterior: mixed, valor_nuevo: mixed}>
     */
    private static function diff_proveedores(array $anteriores, array $nuevos): array
    {
        $mapaAnterior = [];
        foreach ($anteriores as $proveedor) {
            $mapaAnterior[$proveedor['id_proveedor']] = $proveedor['razon_social'];
        }

        $mapaNuevo = [];
        foreach ($nuevos as $proveedor) {
            $mapaNuevo[$proveedor['id_proveedor']] = $proveedor['razon_social'];
        }

        $agregados = array_diff_key($mapaNuevo, $mapaAnterior);
        $eliminados = array_diff_key($mapaAnterior, $mapaNuevo);

        $cambios = [];
        foreach ($agregados as $idProveedor => $razonSocial) {
            $cambios[] = [
                'campo_bd' => 'proveedor_recepcion',
                'campo' => 'Proveedor agregado',
                'valor_anterior' => null,
                'valor_nuevo' => $razonSocial,
            ];
        }
        foreach ($eliminados as $idProveedor => $razonSocial) {
            $cambios[] = [
                'campo_bd' => 'proveedor_recepcion',
                'campo' => 'Proveedor eliminado',
                'valor_anterior' => $razonSocial,
                'valor_nuevo' => null,
            ];
        }

        return $cambios;
    }

    /**
     * Diferencias entre el array de guías anterior y el nuevo.
     *
     * @param  array<int, array<string, mixed>>  $anteriores
     * @param  array<int, array<string, mixed>>  $nuevas
     * @param  string  $campoBd  Columna affected en el log de cambios
     *                          (`guias` para el ingreso, `guias_salida` para
     *                          la salida).
     * @return array<int, array{campo_bd: ?string, campo: ?string, valor_anterior: mixed, valor_nuevo: mixed}>
     */
    private static function diff_guias(array $anteriores, array $nuevas, string $campoBd = 'guias'): array
    {
        $mapaAnterior = [];
        foreach ($anteriores as $guia) {
            $mapaAnterior[(int) $guia['id_proveedor']] = $guia;
        }

        $mapaNuevo = [];
        foreach ($nuevas as $guia) {
            $mapaNuevo[(int) $guia['id_proveedor']] = $guia;
        }

        $cambios = [];
        foreach ($mapaNuevo as $idProveedor => $guia) {
            $anterior = $mapaAnterior[$idProveedor] ?? null;
            if ($anterior === null) {
                $cambios[] = [
                    'campo_bd' => $campoBd,
                    'campo' => 'Guía agregada',
                    'valor_anterior' => null,
                    'valor_nuevo' => self::resumen_guia($guia),
                ];

                continue;
            }

            if ($anterior['guia_remitente'] !== $guia['guia_remitente']) {
                $cambios[] = [
                    'campo_bd' => $campoBd.'.guia_remitente',
                    'campo' => 'Guía remitente',
                    'valor_anterior' => $anterior['guia_remitente'],
                    'valor_nuevo' => $guia['guia_remitente'],
                ];
            }
            if ($anterior['guia_transportista'] !== $guia['guia_transportista']) {
                $cambios[] = [
                    'campo_bd' => $campoBd.'.guia_transportista',
                    'campo' => 'Guía transportista',
                    'valor_anterior' => $anterior['guia_transportista'],
                    'valor_nuevo' => $guia['guia_transportista'],
                ];
            }
        }

        foreach ($mapaAnterior as $idProveedor => $guia) {
            if (! isset($mapaNuevo[$idProveedor])) {
                $cambios[] = [
                    'campo_bd' => $campoBd,
                    'campo' => 'Guía eliminada',
                    'valor_anterior' => self::resumen_guia($guia),
                    'valor_nuevo' => null,
                ];
            }
        }

        return $cambios;
    }

    /**
     * Resumen legible de una guía para el log de cambios.
     *
     * @param  array<string, mixed>  $guia
     */
    private static function resumen_guia(array $guia): string
    {
        $remitente = (string) ($guia['guia_remitente'] ?? '');
        $transportista = (string) ($guia['guia_transportista'] ?? '');

        return 'Remitente: '.($remitente !== '' ? $remitente : '—')
            .' | Transportista: '.($transportista !== '' ? $transportista : '—');
    }

    /**
     * Registrar un cambio en el `log_cambios` de la recepción.
     *
     * @param  array<int, array{campo_bd: ?string, campo: ?string, valor_anterior: mixed, valor_nuevo: mixed}>  $cambios
     */
    private static function registrar_log_cambios(
        RecepcionUnidad $recepcion,
        int $idEmpleado,
        string $motivo,
        array $cambios
    ): void {
        $log = $recepcion->log_cambios ?? [];
        if (is_string($log)) {
            $log = json_decode($log, true) ?? [];
        }
        if (! is_array($log)) {
            $log = [];
        }

        $log[] = RES_CambiosLog::crear($idEmpleado, $motivo, $cambios);

        DB::table('recepcion_unidad')
            ->where('id', $recepcion->id)
            ->update(['log_cambios' => json_encode($log)]);
    }

    /**
     * Reemplazar el array de guías de SALIDA de una recepción de unidad.
     *
     * Las guías de salida son un contrato aparte del de ingreso: se registran
     * cuando la unidad sale de planta.
     *
     * @param  array<int, mixed>  $guias
     * @param  array<int, mixed>  $archivosPorIndice  Archivos nuevos por
     *                                              posición de la guía.
     */
    public static function set_guias_salida(int $id, array $guias, int $idEmpleado, array $archivosPorIndice = []): array
    {
        $recepcion = RecepcionUnidad::find($id);
        if (! $recepcion) {
            return ApiResponse::error('No se encontró el registro de recepción.', 404);
        }

        $anteriores = RecepcionUnidadesData::get_guias_salida($id);

        try {
            $guias = self::resolver_archivos_guias($guias, $archivosPorIndice);
            $normalizadas = RecepcionUnidadesData::preparar_guias($guias);
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }

        try {
            $normalizadas = RecepcionUnidadesData::reemplazar_guias_salida($id, $guias);
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }

        $cambios = self::diff_guias($anteriores, $normalizadas, 'guias_salida');
        if (! empty($cambios)) {
            self::registrar_log_cambios(
                $recepcion,
                $idEmpleado,
                'Edición de guías de salida de la recepción',
                $cambios
            );
        }

        return ApiResponse::success($normalizadas, 'Guías de salida actualizadas correctamente');
    }

    /**
     * Editar campos puntuales de una recepción de unidad.
     *
     * Los campos que se registraron en una PROGRAMACIÓN (de recepción o de
     * despacho) están bloqueados: se rechazan con un mensaje claro.
     * Cada cambio queda registrado en `log_cambios`.
     *
     * @param  array<string, mixed>  $campos  Claves admitidas: id_vehiculo,
     *                                        id_tipo_vehiculo, id_vehiculo_carreta,
     *                                        id_empresa_transporte, id_conductor.
     *                                        El proveedor y las guías se editan
     *                                        por sus propios endpoints.
     */
    public static function actualizar_campos(int $id, array $campos, int $idEmpleado, ?string $motivo = null): array
    {
        $recepcion = RecepcionUnidad::find($id);
        if (! $recepcion) {
            return ApiResponse::error('No se encontró el registro de recepción.', 404);
        }

        if (empty($campos)) {
            return ApiResponse::error('No se envió ningún campo a actualizar.', 422);
        }

        $bloqueados = CamposBloqueadosHelper::normalizar($recepcion->campos_bloqueados);

        // Una programación sin confirmar todavía no tiene llegada registrada:
        // sus datos no se pueden editar hasta confirmarla.
        $llegadaPendiente = (bool) $recepcion->es_programacion
            && empty($recepcion->id_empleado_recepcion);

        if ($llegadaPendiente) {
            return ApiResponse::error(
                'No se puede editar: primero debe confirmar la llegada de la unidad.',
                422
            );
        }

        // Validación de campos desconocidos y de campos bloqueados.
        foreach ($campos as $campo => $valor) {
            if (in_array($campo, self::CAMPOS_RETIRADOS, true)) {
                return ApiResponse::error(
                    'El campo "'.CamposBloqueadosHelper::etiqueta($campo).'" ya no se edita aquí: '
                    .'usa los endpoints de proveedores y guías de la recepción.',
                    422
                );
            }

            if (! in_array($campo, CamposBloqueadosHelper::CAMPOS, true)) {
                return ApiResponse::error('El campo "'.$campo.'" no se puede editar.', 422);
            }

            if (in_array($campo, $bloqueados, true)) {
                return ApiResponse::error(
                    'El campo "'.CamposBloqueadosHelper::etiqueta($campo).'" fue registrado en la programación y no se puede editar.',
                    422
                );
            }
        }

        // Validación de existencia (FKs lógicas, sin constraints en BD).
        $tablas = [
            'id_vehiculo' => 'vehiculo',
            'id_vehiculo_carreta' => 'vehiculo',
            'id_tipo_vehiculo' => 'tipo_vehiculo',
            'id_empresa_transporte' => 'empresa_transporte',
            'id_conductor' => 'conductor',
        ];

        foreach ($campos as $campo => $valor) {
            if ($valor === null || $valor === '') {
                continue;
            }

            // Las fechas deben ser reales: se valida el formato y que el día
            // exista (no un 31 de febrero).
            if (in_array($campo, CamposBloqueadosHelper::CAMPOS_FECHA, true)) {
                $fecha = \Carbon\Carbon::parse((string) $valor);
                if (! $fecha->isValid()) {
                    return ApiResponse::error(
                        'El valor indicado para "'.CamposBloqueadosHelper::etiqueta($campo).'" no es una fecha válida.',
                        422
                    );
                }
                continue;
            }

            $tabla = $tablas[$campo] ?? null;
            if ($tabla === null) {
                continue;
            }

            $existe = DB::table($tabla)->where('id', (int) $valor)->exists();
            if (! $existe) {
                return ApiResponse::error(
                    'El valor indicado para "'.CamposBloqueadosHelper::etiqueta($campo).'" no existe.',
                    422
                );
            }
        }

        try {
            DB::transaction(function () use ($recepcion, $campos, $idEmpleado, $motivo) {
                $cambios = [];

                foreach ($campos as $campo => $valor) {
                    $valorAnterior = $recepcion->{$campo};
                    $esTexto = in_array($campo, CamposBloqueadosHelper::CAMPOS_TEXTO, true);
                    $esFecha = in_array($campo, CamposBloqueadosHelper::CAMPOS_FECHA, true);

                    if ($valor === null || $valor === '') {
                        $valorNuevo = null;
                    } elseif ($esFecha) {
                        $valorNuevo = \Carbon\Carbon::parse((string) $valor)->toDateTimeString();
                    } elseif ($esTexto) {
                        $valorNuevo = trim((string) $valor);
                    } else {
                        // El resto son ids referenciados.
                        $valorNuevo = (int) $valor;
                    }

                    $sinCambio = $esTexto || $esFecha
                        ? (string) ($valorAnterior ?? '') === (string) ($valorNuevo ?? '')
                        : (int) ($valorAnterior ?? 0) === (int) ($valorNuevo ?? 0);

                    if ($sinCambio) {
                        continue;
                    }

                    $cambios[] = [
                        'campo_bd' => $campo,
                        'campo' => CamposBloqueadosHelper::etiqueta($campo),
                        // En el historial se muestran los NOMBRES (placa, razón
                        // social, conductor), no los ids crudos.
                        'valor_anterior' => self::nombre_legible($campo, $valorAnterior),
                        'valor_nuevo' => self::nombre_legible($campo, $valorNuevo),
                    ];

                    $recepcion->{$campo} = $valorNuevo;

                    // El estado de la unidad acompaña a la salida: si se
                    // registra una salida queda fuera de planta y, si se
                    // quita, vuelve a estar en planta.
                    if ($campo === 'fecha_hora_salida') {
                        $recepcion->estado = $valorNuevo !== null
                            ? EstadoUnidad::FueraDePlanta->value
                            : EstadoUnidad::EnPlanta->value;
                    }
                }

                if (empty($cambios)) {
                    return;
                }

                $recepcion->save();

                $log = $recepcion->log_cambios ?? [];
                if (! is_array($log)) {
                    $log = [];
                }
                $log[] = RES_CambiosLog::crear(
                    $idEmpleado,
                    $motivo ?: 'Edición de datos de la recepción de unidad',
                    $cambios
                );
                $recepcion->log_cambios = $log;
                $recepcion->save();
            });
        } catch (\Throwable $e) {
            return ApiResponse::error('Error al actualizar la recepción: '.$e->getMessage(), 500);
        }

        $actualizada = RecepcionUnidadesData::get_recepcion_by_id($id);

        return ApiResponse::success($actualizada, 'Recepción actualizada correctamente.');
    }

    /**
     * Nombre legible de un campo referenciado (para el historial de cambios).
     *
     * En vez del id crudo se devuelve la placa, la razón social o el nombre
     * del conductor, según el campo.
     */
    private static function nombre_legible(string $campo, mixed $valor): string
    {
        if ($valor === null || $valor === '') {
            return '— (vacío)';
        }

        // Las guías ya son texto legible (serie-número).
        if (in_array($campo, CamposBloqueadosHelper::CAMPOS_TEXTO, true)) {
            return (string) $valor;
        }

        // Las fechas se muestran tal cual (ya vienen en formato DATETIME).
        if (in_array($campo, CamposBloqueadosHelper::CAMPOS_FECHA, true)) {
            return (string) $valor;
        }

        $id = (int) $valor;
        $nombre = null;

        if ($campo === 'id_vehiculo' || $campo === 'id_vehiculo_carreta') {
            $nombre = DB::table('vehiculo')->where('id', $id)->value('placa');
        } elseif ($campo === 'id_tipo_vehiculo') {
            $nombre = DB::table('tipo_vehiculo')->where('id', $id)->value('nombre');
        } elseif ($campo === 'id_empresa_transporte') {
            $nombre = DB::table('empresa_transporte')->where('id', $id)->value('razon_social');
        } elseif ($campo === 'id_conductor') {
            $conductor = DB::table('conductor')
                ->where('id', $id)
                ->first(['nombre', 'apellido', 'dni']);

            if ($conductor) {
                $nombre = trim($conductor->nombre.' '.$conductor->apellido);
                if ($conductor->dni) {
                    $nombre .= ' ('.$conductor->dni.')';
                }
            }
        } elseif ($campo === 'id_proveedor_minero') {
            $nombre = DB::table('proveedor')->where('id', $id)->value('razon_social');
        }

        return ($nombre !== null && $nombre !== '') ? (string) $nombre : '#'.$id;
    }

    /**
     * Registrar la salida de una unidad.
     *
     * @param  array<int, mixed>  $guiasSalida  Guías de salida con el mismo
     *                                           contrato que las de ingreso
     *                                           (una entrada por proveedor).
     * @param  array<int, mixed>  $guiasArchivos  Archivos nuevos por posición
     *                                               de la guía de salida.
     */
    public static function registrar_salida(int $id, string $estadoSalida, ?string $observacionSalida, array $evidencias, int $idEmpleadoOperador, array $guiasSalida = [], array $guiasArchivos = []): array
    {
        $recepcion = RecepcionUnidad::find($id);
        if (! $recepcion) {
            return ApiResponse::error('No se encontró el registro de recepción.');
        }

        // Las guías de salida se validan ANTES de tocar la recepción: si el
        // contrato es inválido no debe quedar una salida a medio registrar.
        $guiasSalidaNormalizadas = [];
        if (! empty($guiasSalida)) {
            try {
                $guiasSalida = self::resolver_archivos_guias($guiasSalida, $guiasArchivos);
                $guiasSalidaNormalizadas = RecepcionUnidadesData::preparar_guias($guiasSalida);
            } catch (\InvalidArgumentException $e) {
                return ApiResponse::error($e->getMessage(), 422);
            }
        }

        $guiasSalidaAnteriores = RecepcionUnidadesData::get_guias_salida($id);

        $nowStr = now()->toDateTimeString();

        $uploaded = [];
        $uploadedUrls = [];
        if (! empty($evidencias)) {
            $uploaded = ArchivoHelper::guardarArchivos('evidencias/salida', $evidencias);
            if (! empty($uploaded)) {
                $uploadedUrls = array_map(fn ($f) => $f['url'], $uploaded);
            }
        }

        if (! empty($uploaded)) {
            $rawEv = $recepcion->evidencias;
            $existingEv = is_array($rawEv)
                ? $rawEv
                : (is_string($rawEv) ? (json_decode($rawEv, true) ?? []) : []);

            $normalizedExisting = array_map(function ($item) {
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
            }, $existingEv);

            $recepcion->evidencias = array_merge($normalizedExisting, $uploaded);
        }

        $recepcion->estado_salida = $estadoSalida;
        $recepcion->observacion_salida = $observacionSalida;
        $recepcion->fecha_hora_salida = $nowStr;
        $recepcion->estado = EstadoUnidad::FueraDePlanta->value;
        $recepcion->save();

        // Guías de salida: se guardan junto con el resto de la salida.
        if (! empty($guiasSalidaNormalizadas)) {
            RecepcionUnidadesData::reemplazar_guias_salida($id, $guiasSalidaNormalizadas);

            $cambiosGuiasSalida = self::diff_guias(
                $guiasSalidaAnteriores,
                $guiasSalidaNormalizadas,
                'guias_salida'
            );
            if (! empty($cambiosGuiasSalida)) {
                self::registrar_log_cambios(
                    $recepcion,
                    $idEmpleadoOperador,
                    'Registro de salida de unidad',
                    $cambiosGuiasSalida
                );
            }
        }

        // Registrar la salida en la visita y sus detalles vinculados a esta recepción de unidad
        $visitas = RecepcionVisita::where('id_recepcion_unidad', $id)->get();
        foreach ($visitas as $visita) {
            $visita->fecha_hora_salida = $nowStr;
            $visita->observacion_salida = $observacionSalida;
            if (! empty($uploadedUrls)) {
                $rawVisitaEv = $visita->evidencias_salida;
                $existingVisitaEv = is_array($rawVisitaEv)
                    ? $rawVisitaEv
                    : (is_string($rawVisitaEv) ? (json_decode($rawVisitaEv, true) ?? []) : []);
                $mergedVisitaEv = array_values(array_unique(array_merge($existingVisitaEv, $uploadedUrls)));
                $visita->evidencias_salida = json_encode($mergedVisitaEv);
            }
            $visita->estado = EstadoVisita::FueraDePlanta->value;
            $visita->save();

            $updateDetalleData = [
                'fecha_hora_salida' => $nowStr,
                'observacion_salida' => $observacionSalida,
                'estado' => EstadoVisita::FueraDePlanta->value,
            ];

            RecepcionVisitaDetalle::where('id_recepcion_visita', $visita->id)->update($updateDetalleData);
        }

        $distribucionId = ProgramacionDespachosData::get_distribucion_id_for_recepcion_unidad($id);
        if ($distribucionId !== null) {
            $result = ProgramacionDespachosService::registrar_salida(
                $distribucionId,
                $idEmpleadoOperador,
                $observacionSalida
            );
            if (! ($result['success'] ?? false)) {
                Log::warning('Auto-registrar salida de distribución {id} desde recepción unidad {ru} falló: {msg}', [
                    'id' => $distribucionId,
                    'ru' => $id,
                    'msg' => $result['message'] ?? 'unknown',
                ]);
            }
        }

        $updated = RecepcionUnidadesData::get_recepcion_by_id($id);

        return ApiResponse::success($updated, 'Salida de unidad registrada correctamente');
    }

    /**
     * Listar lotes de una recepción de unidad.
     */
    public static function get_lotes(int $id): array
    {
        $data = RecepcionUnidadesData::get_lotes($id);

        return ApiResponse::success($data, 'Lotes obtenidos correctamente');
    }

    /**
     * Generar un nuevo lote para la recepción indicada.
     */
    public static function crear_lote(int $id, int $idEmpleado): array
    {
        $recepcion = RecepcionUnidad::find($id);
        if (! $recepcion) {
            return ApiResponse::error('No se encontró el registro de recepción.', 404);
        }

        $lote = RecepcionUnidadesData::crear_lote($id, $idEmpleado);

        return ApiResponse::success($lote, 'Lote generado correctamente');
    }

    /**
     * Eliminar un lote.
     */
    public static function eliminar_lote(int $loteId): array
    {
        $deleted = RecepcionUnidadesData::eliminar_lote($loteId);
        if (! $deleted) {
            return ApiResponse::error('No se encontró el lote a eliminar.', 404);
        }

        return ApiResponse::success(null, 'Lote eliminado correctamente');
    }
}
