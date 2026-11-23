<?php

namespace App\Modules\GuiasPrimerTramo\Services;

use App\Data\LotesMineralDisponiblesData;
use App\Modules\GuiasPrimerTramo\Data\GuiasPrimerTramoData;
use App\Shared\Enums\_Generic\EstadoBase;
use App\Shared\Enums\_Generic\EstadoGuiaPrimerTramo;
use App\Shared\Helpers\ArchivoHelper;
use App\Shared\Helpers\CamposBloqueadosHelper;
use App\Shared\Responses\_Generic\RES_CambiosLog;
use App\Shared\Responses\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GuiasPrimerTramoService
{
    /**
     * Listar guías filtradas por sucursal.
     */
    public static function get_guias(array $filters): array
    {
        if (empty($filters['id_sucursal'])) {
            return ApiResponse::error('Debe seleccionar una sucursal.');
        }

        $data = GuiasPrimerTramoData::get_guias($filters);

        return ApiResponse::success($data, 'Guías de primer tramo obtenidas correctamente.');
    }

    /**
     * Obtener metadatos para los filtros.
     */
    public static function get_filtros_metadata(int $idSucursal): array
    {
        $data = GuiasPrimerTramoData::get_filtros_metadata($idSucursal);

        return ApiResponse::success($data, 'Metadatos de filtros obtenidos correctamente.');
    }

    /**
     * Obtener una guía por id.
     */
    public static function get_guia_by_id(int $id): array
    {
        $guia = GuiasPrimerTramoData::get_guia_by_id($id);
        if (! $guia) {
            return ApiResponse::error('No se encontró la guía de primer tramo.');
        }

        return ApiResponse::success($guia, 'Guía de primer tramo obtenida correctamente.');
    }

    /**
     * Unidades con items de mineral pendientes de guía.
     *
     * @return array<int, array{id: int, placa: ?string, fecha_hora_ingreso: ?string}>
     */
    public static function get_unidades_disponibles(?int $idSucursal = null): array
    {
        $data = GuiasPrimerTramoData::get_unidades_disponibles($idSucursal);

        return ApiResponse::success($data, 'Unidades disponibles obtenidas correctamente.');
    }

    /**
     * Proveedores de la carga de una unidad.
     *
     * @return array<int, array{id_proveedor: int, razon_social: string, ruc: ?string}>
     */
    public static function get_proveedores_unidad(int $idUnidad): array
    {
        $data = GuiasPrimerTramoData::get_proveedores_unidad($idUnidad);

        return ApiResponse::success($data, 'Proveedores de la unidad obtenidos correctamente.');
    }

    /**
     * Construye el JSON `documentos` a partir de los archivos subidos y los previos.
     *
     * @param  array{guia_remitente: ?\Illuminate\Http\UploadedFile, guia_transportista: ?\Illuminate\Http\UploadedFile}  $archivos
     * @param  array  $previos  documentos previos {guia_remitente, guia_transportista}
     * @return array JSON listo para almacenar.
     */
    private static function build_documentos(array $archivos, array $previos, bool $sin_guia_transportista): array
    {
        $doc = [
            'guia_remitente' => $previos['guia_remitente'] ?? null,
            'nota_no_conformidad' => $previos['nota_no_conformidad'] ?? null,
        ];

        if ($sin_guia_transportista) {
            $doc['guia_transportista'] = null;
        } else {
            $doc['guia_transportista'] = $previos['guia_transportista'] ?? null;
        }

        if (isset($archivos['guia_remitente']) && $archivos['guia_remitente'] !== null) {
            $saved = ArchivoHelper::guardarArchivos('guias-primer-tramo', [$archivos['guia_remitente']]);
            $doc['guia_remitente'] = $saved[0] ?? null;
        }

        if (! $sin_guia_transportista && isset($archivos['guia_transportista']) && $archivos['guia_transportista'] !== null) {
            $saved = ArchivoHelper::guardarArchivos('guias-primer-tramo', [$archivos['guia_transportista']]);
            $doc['guia_transportista'] = $saved[0] ?? null;
        }

        if (isset($archivos['nota_no_conformidad']) && $archivos['nota_no_conformidad'] !== null) {
            $saved = ArchivoHelper::guardarArchivos('guias-primer-tramo', [$archivos['nota_no_conformidad']]);
            $doc['nota_no_conformidad'] = $saved[0] ?? null;
        }

        return $doc;
    }

    /**
     * Valida duplicados de una guia de primer tramo contra el resto de guias
     * activas. Devuelve tres flags independientes para que el frontend pueda
     * mostrar advertencias separadas por campo:
     *
     *   - `existe_combinacion`:    la combinacion exacta
     *                              (guia_remitente + transportista/sin_transportista)
     *                              ya esta usada por OTRA guia activa.
     *   - `existe_remitente`:      existe OTRA guia activa con el mismo
     *                              `guia_remitente` (cualquier transportista).
     *   - `existe_transportista`:  existe OTRA guia activa con el mismo
     *                              `guia_transportista` (cualquier remitente).
     *                              No aplica si `sinGuiaTransportista=true`.
     *
     * Solo se comparan los inputs (texto); los archivos NO se validan para
     * evitar falsos positivos por metadata.
     *
     * @param  int|null  $idExcluir  ID de la guia a excluir del chequeo (en updates).
     * @return array{
     *     existe: bool,
     *     existe_combinacion: bool,
     *     existe_remitente: bool,
     *     existe_transportista: bool,
     *     id_guia_combinacion: ?int,
     *     id_guia_remitente: ?int,
     *     id_guia_transportista: ?int,
     *     messages: array<string, string>
     * }|null  Null si guia_remitente viene vacio (no hay nada que validar).
     */
    public static function validar_duplicado_guia_activa(
        string $guiaRemitente,
        ?string $guiaTransportista,
        bool $sinGuiaTransportista,
        ?int $idExcluir = null,
    ): ?array {
        $guiaRemitenteTrim = trim($guiaRemitente);
        if ($guiaRemitenteTrim === '') {
            return null;
        }

        $baseActivo = function ($q) {
            $q->where('estado', EstadoBase::Activo->value);
        };

        $aplicarExcluir = function ($q) use ($idExcluir) {
            if ($idExcluir !== null) {
                $q->where('id', '<>', $idExcluir);
            }
        };

        // --- 1) Combinacion exacta ---
        $queryCombinacion = DB::table('guia_primer_tramo')
            ->where($baseActivo)
            ->where('guia_remitente', $guiaRemitenteTrim);
        if ($sinGuiaTransportista) {
            $queryCombinacion->where(function ($q) {
                $q->whereNull('guia_transportista')
                    ->orWhere('guia_transportista', '');
            });
        } elseif ($guiaTransportista === null || trim($guiaTransportista) === '') {
            $queryCombinacion->where(function ($q) {
                $q->whereNull('guia_transportista')
                    ->orWhere('guia_transportista', '');
            });
        } else {
            $queryCombinacion->where('guia_transportista', $guiaTransportista);
        }
        $aplicarExcluir($queryCombinacion);
        $combinacionHit = $queryCombinacion->first();

        // --- 2) Solo por guia_remitente (otra guia activa con mismo remitente) ---
        $queryRemitente = DB::table('guia_primer_tramo')
            ->where($baseActivo)
            ->where('guia_remitente', $guiaRemitenteTrim);
        $aplicarExcluir($queryRemitente);
        $remitenteHit = $queryRemitente->first();

        // --- 3) Solo por guia_transportista (otra guia activa con mismo transportista) ---
        $transportistaHit = null;
        if (! $sinGuiaTransportista && $guiaTransportista !== null && trim($guiaTransportista) !== '') {
            $queryTransportista = DB::table('guia_primer_tramo')
                ->where($baseActivo)
                ->where('guia_transportista', trim($guiaTransportista));
            $aplicarExcluir($queryTransportista);
            $transportistaHit = $queryTransportista->first();
        }

        $messages = [];

        if ($combinacionHit) {
            $trans = $sinGuiaTransportista || $guiaTransportista === null || trim($guiaTransportista) === ''
                ? 'sin guía transportista'
                : "guía transportista '{$guiaTransportista}'";
            $messages['combinacion'] = "Ya existe una guía activa con la misma guía remitente '{$guiaRemitenteTrim}' y {$trans}.";
        }

        if ($remitenteHit) {
            $messages['remitente'] = "Ya existe otra guía activa con el mismo número de guía remitente '{$guiaRemitenteTrim}'.";
        }

        if ($transportistaHit) {
            $messages['transportista'] = "Ya existe otra guía activa con el mismo número de guía transportista '{$guiaTransportista}'.";
        }

        return [
            'existe' => count($messages) > 0,
            'existe_combinacion' => $combinacionHit !== null,
            'existe_remitente' => $remitenteHit !== null,
            'existe_transportista' => $transportistaHit !== null,
            'id_guia_combinacion' => $combinacionHit ? (int) $combinacionHit->id : null,
            'id_guia_remitente' => $remitenteHit ? (int) $remitenteHit->id : null,
            'id_guia_transportista' => $transportistaHit ? (int) $transportistaHit->id : null,
            'messages' => $messages,
        ];
    }

    /**
     * Endpoint publico de validacion de duplicados. Reutiliza
     * {@see self::validar_duplicado_guia_activa()} para chequeo previo al submit.
     *
     * @param  array  $params  {id_sucursal: int, guia_remitente: string, guia_transportista?: ?string, sin_guia_transportista: bool, id_excluir?: ?int}
     */
    public static function validar_duplicado(array $params): array
    {
        if (empty($params['id_sucursal'])) {
            return ApiResponse::error('Debe especificar la sucursal.');
        }

        $guiaRemitente = trim((string) ($params['guia_remitente'] ?? ''));
        $sinGuiaTransportista = ! empty($params['sin_guia_transportista']);
        $guiaTransportista = $sinGuiaTransportista
            ? null
            : (isset($params['guia_transportista']) && $params['guia_transportista'] !== null && trim((string) $params['guia_transportista']) !== ''
                ? trim((string) $params['guia_transportista'])
                : null);
        $idExcluir = isset($params['id_excluir']) && $params['id_excluir'] !== null
            ? (int) $params['id_excluir']
            : null;

        $resultado = self::validar_duplicado_guia_activa(
            $guiaRemitente,
            $guiaTransportista,
            $sinGuiaTransportista,
            $idExcluir,
        );

        // guia_remitente vacio -> no hay nada que validar, devolver OK sin duplicado.
        $data = $resultado ?? [
            'existe' => false,
            'existe_combinacion' => false,
            'existe_remitente' => false,
            'existe_transportista' => false,
            'id_guia_combinacion' => null,
            'id_guia_remitente' => null,
            'id_guia_transportista' => null,
            'messages' => [],
        ];

        // Si hay cualquier conflicto, incluimos la primera guia existente para
        // que el frontend pueda enlazarla o mostrar contexto si lo necesita.
        $firstExistingId = $data['id_guia_combinacion']
            ?? $data['id_guia_remitente']
            ?? $data['id_guia_transportista']
            ?? null;
        if ($firstExistingId !== null) {
            $guiaExistente = GuiasPrimerTramoData::get_guia_by_id($firstExistingId);
            $data['guia'] = $guiaExistente;
        }

        return ApiResponse::success($data, $data['existe'] ? 'Se detectaron conflictos con guías existentes.' : 'No se encontraron duplicados.');
    }

    /**
     * Crear una nueva guía de primer tramo con sus items.
     *
     * @param  array  $data  Cabecera validada.
     * @param  array  $items  Cada item: {id_lote_mineral?: int, id_particion_lote_mineral?: int} (excluyentes).
     * @param  array{guia_remitente: ?\Illuminate\Http\UploadedFile, guia_transportista: ?\Illuminate\Http\UploadedFile}  $archivos
     */
    /**
     * Completar la guía de la unidad con la guía de su carga.
     *
     * La guía de primer tramo del lote es el documento que el proveedor emite
     * para el mineral: es la fuente más exacta de la guía de remisión. Cuando
     * la unidad ingresó a planta sin guía (el operador la dejó vacía al
     * confirmar la llegada) y después se registra la guía de su carga, la de
     * la unidad se completa. Si la unidad NO tiene entrada para ese proveedor,
     * la entrada se CREA con los datos (textos y archivos) de la guía de
     * primer tramo.
     *
     * Reglas conservadoras:
     * - Solo completa campos VACÍOS: nunca pisa una guía ya registrada.
     * - El proveedor tiene que ser de la CARGA de la unidad (o la guía que se
     *   está guardando tiene que ser de esa unidad y ese proveedor). NO se
     *   exige que esté en `proveedor_recepcion`: esa tabla la captura el
     *   operador al ingresar la unidad y puede quedar vacía aunque la unidad
     *   sí traiga mineral.
     * - Con MÁS de una guía activa del mismo proveedor en la unidad no se sabe
     *   a cuál corresponde la entrada: no se sincroniza y lo resuelve el
     *   operador.
     * - Solo si el campo no está bloqueado por la programación.
     * - Solo si la llegada ya está registrada: una programación pendiente
     *   conserva sus guías tal como las capturó la agenda.
     *
     * @param  array  $documentos  Documentos de la guía de primer tramo
     *                             {guia_remitente, guia_transportista}.
     */
    private static function sincronizar_guia_unidad(
        int $idRecepcionUnidad,
        int $idProveedor,
        ?string $guiaRemitente,
        ?string $guiaTransportista,
        ?int $idEmpleado,
        ?int $idGuia = null,
        array $documentos = []
    ): void {
        if ($idRecepcionUnidad <= 0 || $idProveedor <= 0) {
            return;
        }

        $recepcion = DB::table('recepcion_unidad')->where('id', $idRecepcionUnidad)->first();
        if (! $recepcion || empty($recepcion->id_empleado_recepcion)) {
            return;
        }

        // El proveedor tiene que ser de la carga de la unidad. La carga se
        // resuelve por mineral (lotes + particiones con su UNIDAD RESUELTA):
        // el puente `proveedor_recepcion` lo captura el operador al ingresar
        // la unidad y puede quedar vacía aunque la unidad sí traiga mineral.
        $esProveedorDeLaCarga = false;
        foreach (LotesMineralDisponiblesData::get_proveedores_carga_unidad($idRecepcionUnidad) as $proveedor) {
            if ((int) $proveedor['id_proveedor'] === $idProveedor) {
                $esProveedorDeLaCarga = true;
                break;
            }
        }

        if (! $esProveedorDeLaCarga && ! self::guia_pertenece_a_la_unidad($idGuia, $idRecepcionUnidad, $idProveedor)) {
            return;
        }

        $bloqueados = CamposBloqueadosHelper::normalizar($recepcion->campos_bloqueados ?? null);

        // Se conserva el array tal como está guardado (con sus claves) para no
        // perder datos de las entradas de otros proveedores.
        $guias = is_string($recepcion->guias ?? null)
            ? (json_decode((string) $recepcion->guias, true) ?: [])
            : (array) ($recepcion->guias ?? []);

        $indiceProveedor = null;
        foreach ($guias as $indice => $guia) {
            if (! is_array($guia)) {
                continue;
            }
            if ((int) ($guia['id_proveedor'] ?? 0) === $idProveedor) {
                $indiceProveedor = $indice;
                break;
            }
        }

        // Si la entrada ya existe y hay OTRA guía activa del mismo proveedor en
        // la unidad, no se sabe a cuál corresponde la entrada: no se sincroniza.
        if ($indiceProveedor !== null && self::tiene_otras_guias_del_proveedor($idRecepcionUnidad, $idProveedor, $idGuia)) {
            return;
        }

        $remitente = $guiaRemitente !== null ? trim($guiaRemitente) : '';
        $transportista = $guiaTransportista !== null ? trim($guiaTransportista) : '';
        $archivoRemitente = self::archivo_de_documento($documentos, 'guia_remitente');
        $archivoTransportista = self::archivo_de_documento($documentos, 'guia_transportista');

        $cambios = [];

        if ($indiceProveedor === null) {
            // --- La unidad NO tiene entrada para este proveedor: se CREA ---
            if (in_array('guia_remitente', $bloqueados, true)) {
                $remitente = '';
                $archivoRemitente = null;
            }
            if (in_array('guia_transportista', $bloqueados, true)) {
                $transportista = '';
                $archivoTransportista = null;
            }

            if ($remitente === '' && $transportista === '' && $archivoRemitente === null && $archivoTransportista === null) {
                return;
            }

            $guias[] = [
                'id_proveedor' => $idProveedor,
                'guia_remitente' => $remitente,
                'guia_transportista' => $transportista,
                'guia_remitente_archivo' => $archivoRemitente,
                'guia_transportista_archivo' => $archivoTransportista,
            ];

            if ($remitente !== '') {
                $cambios[] = [
                    'campo_bd' => 'guias',
                    'campo' => 'Guía de remisión de la unidad',
                    'valor_anterior' => null,
                    'valor_nuevo' => $remitente,
                ];
            }
            if ($transportista !== '') {
                $cambios[] = [
                    'campo_bd' => 'guias',
                    'campo' => 'Guía de transporte de la unidad',
                    'valor_anterior' => null,
                    'valor_nuevo' => $transportista,
                ];
            }
            if ($archivoRemitente !== null) {
                $cambios[] = [
                    'campo_bd' => 'guias',
                    'campo' => 'Archivo de la guía de remisión de la unidad',
                    'valor_anterior' => null,
                    'valor_nuevo' => self::nombre_archivo($archivoRemitente),
                ];
            }
            if ($archivoTransportista !== null) {
                $cambios[] = [
                    'campo_bd' => 'guias',
                    'campo' => 'Archivo de la guía de transporte de la unidad',
                    'valor_anterior' => null,
                    'valor_nuevo' => self::nombre_archivo($archivoTransportista),
                ];
            }
        } else {
            // --- La entrada existe: solo se completan los campos VACÍOS ---
            $guiaActual = $guias[$indiceProveedor];

            $remitenteActual = trim((string) ($guiaActual['guia_remitente'] ?? ''));
            if (
                $remitenteActual === ''
                && $remitente !== ''
                && ! in_array('guia_remitente', $bloqueados, true)
            ) {
                $guias[$indiceProveedor]['guia_remitente'] = $remitente;
                $cambios[] = [
                    'campo_bd' => 'guias',
                    'campo' => 'Guía de remisión de la unidad',
                    'valor_anterior' => null,
                    'valor_nuevo' => $remitente,
                ];
            }

            $transportistaActual = trim((string) ($guiaActual['guia_transportista'] ?? ''));
            if (
                $transportistaActual === ''
                && $transportista !== ''
                && ! in_array('guia_transportista', $bloqueados, true)
            ) {
                $guias[$indiceProveedor]['guia_transportista'] = $transportista;
                $cambios[] = [
                    'campo_bd' => 'guias',
                    'campo' => 'Guía de transporte de la unidad',
                    'valor_anterior' => null,
                    'valor_nuevo' => $transportista,
                ];
            }

            if (
                empty($guiaActual['guia_remitente_archivo'])
                && $archivoRemitente !== null
                && ! in_array('guia_remitente', $bloqueados, true)
            ) {
                $guias[$indiceProveedor]['guia_remitente_archivo'] = $archivoRemitente;
                $cambios[] = [
                    'campo_bd' => 'guias',
                    'campo' => 'Archivo de la guía de remisión de la unidad',
                    'valor_anterior' => null,
                    'valor_nuevo' => self::nombre_archivo($archivoRemitente),
                ];
            }

            if (
                empty($guiaActual['guia_transportista_archivo'])
                && $archivoTransportista !== null
                && ! in_array('guia_transportista', $bloqueados, true)
            ) {
                $guias[$indiceProveedor]['guia_transportista_archivo'] = $archivoTransportista;
                $cambios[] = [
                    'campo_bd' => 'guias',
                    'campo' => 'Archivo de la guía de transporte de la unidad',
                    'valor_anterior' => null,
                    'valor_nuevo' => self::nombre_archivo($archivoTransportista),
                ];
            }
        }

        if (empty($cambios)) {
            return;
        }

        $log = is_string($recepcion->log_cambios ?? null)
            ? (json_decode((string) $recepcion->log_cambios, true) ?: [])
            : (array) ($recepcion->log_cambios ?? []);

        $log[] = RES_CambiosLog::crear(
            $idEmpleado ?? 0,
            'Guías de la unidad sincronizadas desde la guía de primer tramo de la carga',
            $cambios
        );

        DB::table('recepcion_unidad')
            ->where('id', $idRecepcionUnidad)
            ->update([
                'guias' => json_encode(array_values($guias)),
                'log_cambios' => json_encode($log),
            ]);
    }

    /**
     * ¿La guía de primer tramo pertenece a esa unidad y a ese proveedor?
     */
    private static function guia_pertenece_a_la_unidad(?int $idGuia, int $idRecepcionUnidad, int $idProveedor): bool
    {
        if ($idGuia === null || $idGuia <= 0) {
            return false;
        }

        return DB::table('guia_primer_tramo')
            ->where('id', $idGuia)
            ->where('id_recepcion_unidad', $idRecepcionUnidad)
            ->where('id_proveedor', $idProveedor)
            ->where('estado', EstadoBase::Activo->value)
            ->exists();
    }

    /**
     * ¿Hay otra guía activa (distinta a la que se está sincronizando) del
     * mismo proveedor dentro de la unidad?
     */
    private static function tiene_otras_guias_del_proveedor(int $idRecepcionUnidad, int $idProveedor, ?int $idGuia): bool
    {
        $guias = GuiasPrimerTramoData::get_guias_activas_unidad_proveedor($idRecepcionUnidad, $idProveedor, $idGuia);

        return ! empty($guias);
    }

    /**
     * Archivo de la guía de primer tramo en la forma `IArchivo`
     * ({url, path_relativo, nombre_original, extension}).
     *
     * @param  array  $documentos  Documentos de la guía de primer tramo.
     */
    private static function archivo_de_documento(array $documentos, string $clave): ?array
    {
        $archivo = $documentos[$clave] ?? null;
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
     * Nombre legible de un archivo `IArchivo` (para el log de cambios).
     *
     * @param  array{url: string, path_relativo: string, nombre_original: ?string, extension: ?string}  $archivo
     */
    private static function nombre_archivo(array $archivo): string
    {
        if (! empty($archivo['nombre_original'])) {
            return (string) $archivo['nombre_original'];
        }

        if (! empty($archivo['path_relativo'])) {
            return (string) $archivo['path_relativo'];
        }

        return (string) $archivo['url'];
    }

    public static function crear_guia(array $data, array $items, array $archivos, ?Request $request = null): array
    {
        if (empty($items)) {
            return ApiResponse::error('Debe agregar al menos un item a la guía.');
        }

        $sinGuiaTransportista = ! empty($data['sin_guia_transportista']);
        $guiaRemitente = trim((string) ($data['guia_remitente'] ?? ''));
        $guiaTransportista = $sinGuiaTransportista
            ? null
            : (isset($data['guia_transportista']) && $data['guia_transportista'] !== null && trim((string) $data['guia_transportista']) !== ''
                ? trim((string) $data['guia_transportista'])
                : null);

        $resultadoDuplicado = self::validar_duplicado_guia_activa(
            $guiaRemitente,
            $guiaTransportista,
            $sinGuiaTransportista,
        );
        if ($resultadoDuplicado !== null && $resultadoDuplicado['existe']) {
            $msgPrincipal = $resultadoDuplicado['messages']['combinacion']
                ?? $resultadoDuplicado['messages']['remitente']
                ?? $resultadoDuplicado['messages']['transportista']
                ?? 'Ya existe una guía activa con los mismos datos.';

            return ApiResponse::error($msgPrincipal, [
                'codigo' => 'GUIA_DUPLICADA',
                'detalles' => $resultadoDuplicado['messages'],
            ]);
        }

        try {
            DB::beginTransaction();

            $idEmpleadoRegistro = null;
            if ($request) {
                $authUser = $request->attributes->get('auth_user');
                if ($authUser && ! empty($authUser->id_empleado)) {
                    $idEmpleadoRegistro = (int) $authUser->id_empleado;
                }
            }

            $documentos = self::build_documentos($archivos, [], $sinGuiaTransportista);

            $valoresNuevos = [
                'id_sucursal' => (int) $data['id_sucursal'],
                'id_proveedor' => (int) $data['id_proveedor'],
                'id_concesion' => (int) $data['id_concesion'],
                'id_conductor' => (int) $data['id_conductor'],
                'id_vehiculo' => (int) $data['id_vehiculo'],
                'id_empresa_transporte' => isset($data['id_empresa_transporte']) && $data['id_empresa_transporte'] !== null
                    ? (int) $data['id_empresa_transporte']
                    : null,
                'id_vehiculo_carreta' => isset($data['id_vehiculo_carreta']) && $data['id_vehiculo_carreta'] !== null
                    ? (int) $data['id_vehiculo_carreta']
                    : null,
                'id_empresa_transporte_carreta' => isset($data['id_empresa_transporte_carreta']) && $data['id_empresa_transporte_carreta'] !== null
                    ? (int) $data['id_empresa_transporte_carreta']
                    : null,
                'motivo_traslado' => $data['motivo_traslado'],
                'condicion_ingreso' => $data['condicion_ingreso'] ?? null,
                'fecha_inicio_traslado' => $data['fecha_inicio_traslado'] ?? null,
                'fecha_emision' => $data['fecha_emision'] ?? null,
                'fecha_en_planta' => $data['fecha_en_planta'] ?? null,
                'guia_remitente' => $data['guia_remitente'] ?? null,
                'guia_transportista' => $sinGuiaTransportista ? null : ($data['guia_transportista'] ?? null),
                'sin_guia_transportista' => $sinGuiaTransportista,
                'documentos' => json_encode($documentos),
                'id_recepcion_unidad' => isset($data['id_recepcion_unidad']) && $data['id_recepcion_unidad'] !== null
                    ? (int) $data['id_recepcion_unidad']
                    : null,
                'con_no_conformidad' => filter_var($data['con_no_conformidad'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'peso_guia_remision' => isset($data['peso_guia_remision']) && $data['peso_guia_remision'] !== null
                    ? round((float) $data['peso_guia_remision'], 2)
                    : 0.00,
                'peso_vehicular_total' => isset($data['peso_vehicular_total']) && $data['peso_vehicular_total'] !== null
                    ? round((float) $data['peso_vehicular_total'], 2)
                    : 0.00,
                'id_empleado_registro' => $idEmpleadoRegistro,
                'estado' => EstadoGuiaPrimerTramo::Activo->value,
                'created_at' => now()->toDateTimeString(),
            ];

            $guiaId = DB::table('guia_primer_tramo')->insertGetId($valoresNuevos);

            $now = now()->toDateTimeString();
            $rows = [];
            foreach ($items as $item) {
                $rows[] = [
                    'id_guia_primer_tramo' => $guiaId,
                    'id_lote_mineral' => isset($item['id_lote_mineral']) && $item['id_lote_mineral'] !== null
                        ? (int) $item['id_lote_mineral']
                        : null,
                    'id_particion_lote_mineral' => isset($item['id_particion_lote_mineral']) && $item['id_particion_lote_mineral'] !== null
                        ? (int) $item['id_particion_lote_mineral']
                        : null,
                    'peso_inicial' => isset($item['peso_inicial']) && $item['peso_inicial'] !== null
                        ? round((float) $item['peso_inicial'], 2)
                        : null,
                    'peso_final' => isset($item['peso_final']) && $item['peso_final'] !== null
                        ? round((float) $item['peso_final'], 2)
                        : null,
                    'peso_neto' => isset($item['peso_neto']) && $item['peso_neto'] !== null
                        ? round((float) $item['peso_neto'], 2)
                        : null,
                    'created_at' => $now,
                ];
            }
            DB::table('lote_guia')->insert($rows);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            return ApiResponse::error('Error al registrar la guía: '.$e->getMessage());
        }

        $guiaCreada = GuiasPrimerTramoData::get_guia_by_id($guiaId);

        self::sincronizar_guia_unidad(
            (int) ($valoresNuevos['id_recepcion_unidad'] ?? 0),
            (int) ($valoresNuevos['id_proveedor'] ?? 0),
            $guiaRemitente !== '' ? $guiaRemitente : null,
            $guiaTransportista,
            $idEmpleadoRegistro,
            $guiaId,
            $documentos
        );

        return ApiResponse::success($guiaCreada, 'Guía de primer tramo registrada correctamente.');
    }

    /**
     * Actualizar una guía de primer tramo con sus items.
     */
    public static function actualizar_guia(int $id, array $data, array $items, array $archivos, ?Request $request = null): array
    {
        if (empty($items)) {
            return ApiResponse::error('Debe agregar al menos un item a la guía.');
        }

        $guiaPrevio = DB::table('guia_primer_tramo')->where('id', $id)->first();
        if (! $guiaPrevio) {
            return ApiResponse::error('No se encontró la guía de primer tramo.');
        }

        $sinGuiaTransportista = ! empty($data['sin_guia_transportista']);
        $guiaRemitente = trim((string) ($data['guia_remitente'] ?? ''));
        $guiaTransportista = $sinGuiaTransportista
            ? null
            : (isset($data['guia_transportista']) && $data['guia_transportista'] !== null && trim((string) $data['guia_transportista']) !== ''
                ? trim((string) $data['guia_transportista'])
                : null);

        $resultadoDuplicado = self::validar_duplicado_guia_activa(
            $guiaRemitente,
            $guiaTransportista,
            $sinGuiaTransportista,
            $id,
        );
        if ($resultadoDuplicado !== null && $resultadoDuplicado['existe']) {
            $msgPrincipal = $resultadoDuplicado['messages']['combinacion']
                ?? $resultadoDuplicado['messages']['remitente']
                ?? $resultadoDuplicado['messages']['transportista']
                ?? 'Ya existe una guía activa con los mismos datos.';

            return ApiResponse::error($msgPrincipal, [
                'codigo' => 'GUIA_DUPLICADA',
                'detalles' => $resultadoDuplicado['messages'],
            ]);
        }

        try {
            DB::beginTransaction();

            $previosDocumentos = isset($guiaPrevio->documentos) ? json_decode($guiaPrevio->documentos, true) ?? [] : [];
            $documentos = self::build_documentos(
                $archivos,
                is_array($previosDocumentos) ? $previosDocumentos : [],
                $sinGuiaTransportista
            );

            $nuevosValoresCab = [
                'id_sucursal' => (int) $data['id_sucursal'],
                'id_proveedor' => (int) $data['id_proveedor'],
                'id_concesion' => (int) $data['id_concesion'],
                'id_conductor' => (int) $data['id_conductor'],
                'id_vehiculo' => (int) $data['id_vehiculo'],
                'id_empresa_transporte' => isset($data['id_empresa_transporte']) && $data['id_empresa_transporte'] !== null
                    ? (int) $data['id_empresa_transporte']
                    : null,
                'id_vehiculo_carreta' => isset($data['id_vehiculo_carreta']) && $data['id_vehiculo_carreta'] !== null
                    ? (int) $data['id_vehiculo_carreta']
                    : null,
                'id_empresa_transporte_carreta' => isset($data['id_empresa_transporte_carreta']) && $data['id_empresa_transporte_carreta'] !== null
                    ? (int) $data['id_empresa_transporte_carreta']
                    : null,
                'motivo_traslado' => $data['motivo_traslado'],
                'condicion_ingreso' => $data['condicion_ingreso'] ?? null,
                'fecha_inicio_traslado' => $data['fecha_inicio_traslado'] ?? null,
                'fecha_emision' => $data['fecha_emision'] ?? null,
                'fecha_en_planta' => $data['fecha_en_planta'] ?? null,
                'guia_remitente' => $data['guia_remitente'] ?? null,
                'guia_transportista' => $sinGuiaTransportista ? null : ($data['guia_transportista'] ?? null),
                'sin_guia_transportista' => $sinGuiaTransportista,
                'id_recepcion_unidad' => isset($data['id_recepcion_unidad']) && $data['id_recepcion_unidad'] !== null
                    ? (int) $data['id_recepcion_unidad']
                    : null,
                'con_no_conformidad' => filter_var($data['con_no_conformidad'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'peso_guia_remision' => isset($data['peso_guia_remision']) && $data['peso_guia_remision'] !== null
                    ? round((float) $data['peso_guia_remision'], 2)
                    : 0.00,
                'peso_vehicular_total' => isset($data['peso_vehicular_total']) && $data['peso_vehicular_total'] !== null
                    ? round((float) $data['peso_vehicular_total'], 2)
                    : 0.00,
                'documentos' => $documentos,
            ];

            // --- AUDITORÍA DE CAMBIOS ---
            $cambios = [];
            $camposAuditar = [
                'id_sucursal' => [
                    'nombre' => 'Sucursal',
                    'tipo' => 'int',
                    'resolver' => function ($id) {
                        if (! $id) {
                            return null;
                        }
                        $s = DB::table('sucursal')->where('id', $id)->first();

                        return $s ? $s->nombre : "ID #$id";
                    },
                ],
                'id_proveedor' => [
                    'nombre' => 'Proveedor',
                    'tipo' => 'int',
                    'resolver' => function ($id) {
                        if (! $id) {
                            return null;
                        }
                        $p = DB::table('proveedor')->where('id', $id)->first();

                        return $p ? $p->razon_social : "ID #$id";
                    },
                ],
                'id_concesion' => [
                    'nombre' => 'Concesión',
                    'tipo' => 'int',
                    'resolver' => function ($id) {
                        if (! $id) {
                            return null;
                        }
                        $c = DB::table('concesion')->where('id', $id)->first();

                        return $c ? $c->nombre : "ID #$id";
                    },
                ],
                'id_conductor' => [
                    'nombre' => 'Conductor',
                    'tipo' => 'int',
                    'resolver' => function ($id) {
                        if (! $id) {
                            return null;
                        }
                        $c = DB::table('conductor')->where('id', $id)->first();

                        return $c ? trim($c->nombre.' '.$c->apellido) : "ID #$id";
                    },
                ],
                'id_vehiculo' => [
                    'nombre' => 'Vehículo tractor',
                    'tipo' => 'int',
                    'resolver' => function ($id) {
                        if (! $id) {
                            return null;
                        }
                        $v = DB::table('vehiculo')->where('id', $id)->first();
                        if ($v) {
                            return $v->placa;
                        }

                        return "ID #$id";
                    },
                ],
                'id_empresa_transporte' => [
                    'nombre' => 'Empresa transporte',
                    'tipo' => 'int',
                    'resolver' => function ($id) {
                        if (! $id) {
                            return null;
                        }
                        $et = DB::table('empresa_transporte')->where('id', $id)->first();

                        return $et ? $et->razon_social : "ID #$id";
                    },
                ],
                'id_vehiculo_carreta' => [
                    'nombre' => 'Carreta',
                    'tipo' => 'int',
                    'resolver' => function ($id) {
                        if (! $id) {
                            return null;
                        }
                        $v = DB::table('vehiculo')->where('id', $id)->first();
                        if ($v) {
                            return $v->placa;
                        }

                        return "ID #$id";
                    },
                ],
                'id_empresa_transporte_carreta' => [
                    'nombre' => 'Empresa transp. carreta',
                    'tipo' => 'int',
                    'resolver' => function ($id) {
                        if (! $id) {
                            return null;
                        }
                        $et = DB::table('empresa_transporte')->where('id', $id)->first();

                        return $et ? $et->razon_social : "ID #$id";
                    },
                ],
                'motivo_traslado' => ['nombre' => 'Motivo de traslado', 'tipo' => 'string'],
                'condicion_ingreso' => ['nombre' => 'Condición de ingreso', 'tipo' => 'string'],
                'fecha_inicio_traslado' => ['nombre' => 'Fecha inicio traslado', 'tipo' => 'string'],
                'fecha_emision' => ['nombre' => 'Fecha de emisión', 'tipo' => 'string'],
                'fecha_en_planta' => ['nombre' => 'Fecha en planta', 'tipo' => 'string'],
                'guia_remitente' => ['nombre' => 'Guía remitente', 'tipo' => 'string'],
                'guia_transportista' => ['nombre' => 'Guía transportista', 'tipo' => 'string'],
                'sin_guia_transportista' => ['nombre' => 'Sin guía transportista', 'tipo' => 'bool'],
                'con_no_conformidad' => ['nombre' => 'Con no conformidad', 'tipo' => 'bool'],
                'peso_guia_remision' => ['nombre' => 'Peso guía de remisión', 'tipo' => 'string'],
                'peso_vehicular_total' => ['nombre' => 'Peso vehicular total', 'tipo' => 'string'],
            ];

            foreach ($camposAuditar as $campoBd => $meta) {
                $valAnt = $guiaPrevio->$campoBd ?? null;
                $valNue = array_key_exists($campoBd, $data) ? $data[$campoBd] : null;

                if ($meta['tipo'] === 'int') {
                    $valAnt = $valAnt !== null ? (int) $valAnt : null;
                    $valNue = ($valNue !== null && $valNue !== '') ? (int) $valNue : null;
                } elseif ($meta['tipo'] === 'bool') {
                    $valAnt = ! empty($valAnt);
                    $valNue = ! empty($valNue);
                } else {
                    $valAnt = $valAnt !== null ? trim((string) $valAnt) : '';
                    $valNue = $valNue !== null ? trim((string) $valNue) : '';
                }

                if ($valAnt !== $valNue) {
                    $valAntLabel = isset($meta['resolver']) ? $meta['resolver']($valAnt) : $valAnt;
                    $valNueLabel = isset($meta['resolver']) ? $meta['resolver']($valNue) : $valNue;

                    $cambios[] = [
                        'campo_bd' => $campoBd,
                        'campo' => $meta['nombre'],
                        'valor_anterior' => $valAntLabel,
                        'valor_nuevo' => $valNueLabel,
                    ];
                }
            }

            // Comparar items (lotes o particiones) asociados.
            $vAntItems = DB::table('lote_guia')->where('id_guia_primer_tramo', $id)->get();
            $oldItemsKey = [];
            foreach ($vAntItems as $ol) {
                if ($ol->id_particion_lote_mineral !== null) {
                    $key = 'PART:'.$ol->id_particion_lote_mineral;
                    $plm = DB::table('particion_lote_mineral')->where('id', $ol->id_particion_lote_mineral)->first();
                    $label = $plm ? ($plm->correlativo ?? "Partición #{$ol->id_particion_lote_mineral}") : "Partición #{$ol->id_particion_lote_mineral}";
                } else {
                    $key = 'LOTE:'.$ol->id_lote_mineral;
                    $lm = DB::table('lote_mineral')->where('id', $ol->id_lote_mineral)->first();
                    $label = $lm ? ($lm->correlativo ?? "Lote #{$ol->id_lote_mineral}") : "Lote #{$ol->id_lote_mineral}";
                }
                $oldItemsKey[$key] = $label;
            }

            $newItemsKey = [];
            foreach ($items as $nl) {
                $idL = $nl['id_lote_mineral'] ?? null;
                $idP = $nl['id_particion_lote_mineral'] ?? null;
                if ($idP !== null && $idP !== '') {
                    $key = 'PART:'.(int) $idP;
                    $plm = DB::table('particion_lote_mineral')->where('id', (int) $idP)->first();
                    $label = $plm ? ($plm->correlativo ?? "Partición #{$idP}") : "Partición #{$idP}";
                } else {
                    $key = 'LOTE:'.(int) $idL;
                    $lm = DB::table('lote_mineral')->where('id', (int) $idL)->first();
                    $label = $lm ? ($lm->correlativo ?? "Lote #{$idL}") : "Lote #{$idL}";
                }
                $newItemsKey[$key] = $label;
            }

            foreach (array_diff_key($newItemsKey, $oldItemsKey) as $key => $label) {
                $cambios[] = [
                    'campo_bd' => 'item_asociado',
                    'campo' => 'Item asociado',
                    'valor_anterior' => '—',
                    'valor_nuevo' => $label,
                ];
            }

            foreach (array_diff_key($oldItemsKey, $newItemsKey) as $key => $label) {
                $cambios[] = [
                    'campo_bd' => 'item_desasociado',
                    'campo' => 'Item desasociado',
                    'valor_anterior' => $label,
                    'valor_nuevo' => '—',
                ];
            }

            // --- AUDITORÍA DE DOCUMENTOS ---
            // Compara los archivos previos contra el `$documentos` recién
            // construido por build_documentos(). Solo registra cuando hay un
            // cambio real (alta, reemplazo, eliminación). El cambio del bool
            // `sin_guia_transportista` ya cubre la transición de transportista.
            $docFields = [
                'guia_remitente' => 'Documento guía remitente',
                'guia_transportista' => 'Documento guía transportista',
                'nota_no_conformidad' => 'Documento nota de no conformidad',
            ];
            foreach ($docFields as $docKey => $docLabel) {
                $previoDoc = is_array($previosDocumentos) ? ($previosDocumentos[$docKey] ?? null) : null;
                $nuevoDoc = $documentos[$docKey] ?? null;

                $archivoSubido = $archivos[$docKey] ?? null;
                $previoTenia = is_array($previoDoc) && ! empty($previoDoc['nombre_original']);
                $nuevoTiene = is_array($nuevoDoc) && ! empty($nuevoDoc['nombre_original']);

                if ($archivoSubido !== null) {
                    // El operador subió un archivo en este submit.
                    $nuevoLabel = $nuevoDoc['nombre_original'] ?? 'archivo';
                    if ($previoTenia) {
                        $previoLabel = $previoDoc['nombre_original'];
                        if ($previoLabel !== $nuevoLabel) {
                            $cambios[] = [
                                'campo_bd' => "documento_{$docKey}",
                                'campo' => $docLabel,
                                'valor_anterior' => $previoLabel,
                                'valor_nuevo' => $nuevoLabel,
                            ];
                        }
                    } else {
                        $cambios[] = [
                            'campo_bd' => "documento_{$docKey}",
                            'campo' => $docLabel,
                            'valor_anterior' => '—',
                            'valor_nuevo' => $nuevoLabel,
                        ];
                    }
                } elseif ($previoTenia && ! $nuevoTiene) {
                    // No se subió archivo nuevo y el previo existía pero ya
                    // no está en el resultado. Cubre el caso de borrado
                    // explícito (cuando el operador elimina desde el picker).
                    $cambios[] = [
                        'campo_bd' => "documento_{$docKey}",
                        'campo' => $docLabel,
                        'valor_anterior' => $previoDoc['nombre_original'],
                        'valor_nuevo' => '—',
                    ];
                }
            }

            // --- AUDITORÍA DE PESOS DOCUMENTARIOS EN LOTE_GUIA ---
            // Compara los pesos documentarios enviados para cada item contra
            // los valores actuales en lote_guia si ya existía.
            foreach ($items as $item) {
                $idLoteMineral = isset($item['id_lote_mineral']) && $item['id_lote_mineral'] !== null
                    ? (int) $item['id_lote_mineral']
                    : null;
                $idParticion = isset($item['id_particion_lote_mineral']) && $item['id_particion_lote_mineral'] !== null
                    ? (int) $item['id_particion_lote_mineral']
                    : null;

                $existente = DB::table('lote_guia')
                    ->where('id_guia_primer_tramo', $id)
                    ->where(function ($q) use ($idLoteMineral, $idParticion) {
                        if ($idParticion !== null) {
                            $q->where('id_particion_lote_mineral', $idParticion);
                        } else {
                            $q->where('id_lote_mineral', $idLoteMineral)
                                ->whereNull('id_particion_lote_mineral');
                        }
                    })
                    ->first();

                if (! $existente) {
                    continue;
                }

                $correlativo = 'Item';
                if ($idParticion !== null) {
                    $part = DB::table('particion_lote_mineral')->where('id', $idParticion)->first();
                    $correlativo = $part->correlativo ?? "Partición #{$idParticion}";
                } elseif ($idLoteMineral !== null) {
                    $lote = DB::table('lote_mineral')->where('id', $idLoteMineral)->first();
                    $correlativo = $lote->correlativo ?? "Lote #{$idLoteMineral}";
                }
                $prefijo = "{$correlativo} — ";

                $pesoChecks = [
                    'peso_inicial' => 'Peso inicial (guía)',
                    'peso_final' => 'Peso final (guía)',
                    'peso_neto' => 'Peso neto (guía)',
                ];

                foreach ($pesoChecks as $field => $label) {
                    $valAnt = $existente->$field !== null ? (float) $existente->$field : 0.0;
                    $valNue = isset($item[$field]) && $item[$field] !== null ? round((float) $item[$field], 2) : 0.0;
                    if (abs($valAnt - $valNue) > 0.01) {
                        $cambios[] = [
                            'campo_bd' => "lote_guia.{$field}",
                            'campo' => $prefijo.$label,
                            'valor_anterior' => round($valAnt, 2),
                            'valor_nuevo' => $valNue,
                        ];
                    }
                }
            }

            // Registrar auditoría si hubo algún cambio
            $logActual = isset($guiaPrevio->log_cambios) ? json_decode($guiaPrevio->log_cambios, true) ?? [] : [];
            $idEmpleado = null;
            if ($request) {
                $authUser = $request->attributes->get('auth_user');
                if ($authUser && ! empty($authUser->id_empleado)) {
                    $idEmpleado = (int) $authUser->id_empleado;
                }
            }
            if (! empty($cambios)) {
                $nuevoLog = RES_CambiosLog::crear($idEmpleado ?? 0, $data['motivo'] ?? null, $cambios);
                array_unshift($logActual, $nuevoLog);
            }

            DB::table('guia_primer_tramo')->where('id', $id)->update([
                'id_sucursal' => $nuevosValoresCab['id_sucursal'],
                'id_proveedor' => $nuevosValoresCab['id_proveedor'],
                'id_concesion' => $nuevosValoresCab['id_concesion'],
                'id_conductor' => $nuevosValoresCab['id_conductor'],
                'id_vehiculo' => $nuevosValoresCab['id_vehiculo'],
                'id_empresa_transporte' => $nuevosValoresCab['id_empresa_transporte'],
                'id_vehiculo_carreta' => $nuevosValoresCab['id_vehiculo_carreta'],
                'id_empresa_transporte_carreta' => $nuevosValoresCab['id_empresa_transporte_carreta'],
                'motivo_traslado' => $nuevosValoresCab['motivo_traslado'],
                'condicion_ingreso' => $nuevosValoresCab['condicion_ingreso'],
                'fecha_inicio_traslado' => $nuevosValoresCab['fecha_inicio_traslado'],
                'fecha_emision' => $nuevosValoresCab['fecha_emision'],
                'fecha_en_planta' => $nuevosValoresCab['fecha_en_planta'],
                'guia_remitente' => $nuevosValoresCab['guia_remitente'],
                'guia_transportista' => $nuevosValoresCab['guia_transportista'],
                'sin_guia_transportista' => $nuevosValoresCab['sin_guia_transportista'],
                'id_recepcion_unidad' => $nuevosValoresCab['id_recepcion_unidad'],
                'con_no_conformidad' => $nuevosValoresCab['con_no_conformidad'],
                'peso_guia_remision' => $nuevosValoresCab['peso_guia_remision'],
                'peso_vehicular_total' => $nuevosValoresCab['peso_vehicular_total'],
                'documentos' => json_encode($documentos),
                'log_cambios' => json_encode($logActual),
            ]);

            // Sincronizar items (lote o partición) y sus pesos documentarios
            $now = now()->toDateTimeString();
            foreach ($items as $item) {
                $idLoteMineral = isset($item['id_lote_mineral']) && $item['id_lote_mineral'] !== null
                    ? (int) $item['id_lote_mineral']
                    : null;
                $idParticion = isset($item['id_particion_lote_mineral']) && $item['id_particion_lote_mineral'] !== null
                    ? (int) $item['id_particion_lote_mineral']
                    : null;

                $pesoInicial = isset($item['peso_inicial']) && $item['peso_inicial'] !== null ? round((float) $item['peso_inicial'], 2) : 0.00;
                $pesoFinal = isset($item['peso_final']) && $item['peso_final'] !== null ? round((float) $item['peso_final'], 2) : 0.00;
                $pesoNeto = isset($item['peso_neto']) && $item['peso_neto'] !== null ? round((float) $item['peso_neto'], 2) : 0.00;

                $existente = DB::table('lote_guia')
                    ->where('id_guia_primer_tramo', $id)
                    ->where(function ($q) use ($idLoteMineral, $idParticion) {
                        if ($idParticion !== null) {
                            $q->where('id_particion_lote_mineral', $idParticion);
                        } else {
                            $q->where('id_lote_mineral', $idLoteMineral)
                                ->whereNull('id_particion_lote_mineral');
                        }
                    })
                    ->first();

                if (! $existente) {
                    DB::table('lote_guia')->insert([
                        'id_guia_primer_tramo' => $id,
                        'id_lote_mineral' => $idLoteMineral,
                        'id_particion_lote_mineral' => $idParticion,
                        'peso_inicial' => $pesoInicial,
                        'peso_final' => $pesoFinal,
                        'peso_neto' => $pesoNeto,
                        'log_cambios' => null,
                        'created_at' => $now,
                    ]);
                } else {
                    $cambiosItem = [];
                    $valAntIni = $existente->peso_inicial !== null ? round((float) $existente->peso_inicial, 2) : 0.00;
                    $valAntFin = $existente->peso_final !== null ? round((float) $existente->peso_final, 2) : 0.00;
                    $valAntNet = $existente->peso_neto !== null ? round((float) $existente->peso_neto, 2) : 0.00;

                    if (abs($valAntIni - $pesoInicial) > 0.01) {
                        $cambiosItem[] = [
                            'campo_bd' => 'peso_inicial',
                            'campo' => 'Peso inicial (guía)',
                            'valor_anterior' => $valAntIni,
                            'valor_nuevo' => $pesoInicial,
                        ];
                    }
                    if (abs($valAntFin - $pesoFinal) > 0.01) {
                        $cambiosItem[] = [
                            'campo_bd' => 'peso_final',
                            'campo' => 'Peso final (guía)',
                            'valor_anterior' => $valAntFin,
                            'valor_nuevo' => $pesoFinal,
                        ];
                    }
                    if (abs($valAntNet - $pesoNeto) > 0.01) {
                        $cambiosItem[] = [
                            'campo_bd' => 'peso_neto',
                            'campo' => 'Peso neto (guía)',
                            'valor_anterior' => $valAntNet,
                            'valor_nuevo' => $pesoNeto,
                        ];
                    }

                    $updateLoteGuia = [
                        'peso_inicial' => $pesoInicial,
                        'peso_final' => $pesoFinal,
                        'peso_neto' => $pesoNeto,
                    ];

                    if (! empty($cambiosItem)) {
                        $logItemActual = isset($existente->log_cambios) ? json_decode($existente->log_cambios, true) ?? [] : [];
                        $nuevoLogItem = RES_CambiosLog::crear($idEmpleado ?? 0, $data['motivo'] ?? null, $cambiosItem);
                        array_unshift($logItemActual, $nuevoLogItem);
                        $updateLoteGuia['log_cambios'] = json_encode($logItemActual);
                    }

                    DB::table('lote_guia')
                        ->where('id', $existente->id)
                        ->update($updateLoteGuia);
                }
            }

            // Eliminar items que ya no están en la nueva lista
            $pairsActuales = [];
            foreach ($items as $item) {
                $idLoteMineral = isset($item['id_lote_mineral']) && $item['id_lote_mineral'] !== null
                    ? (int) $item['id_lote_mineral']
                    : null;
                $idParticion = isset($item['id_particion_lote_mineral']) && $item['id_particion_lote_mineral'] !== null
                    ? (int) $item['id_particion_lote_mineral']
                    : null;
                $pairsActuales[] = ['lote' => $idLoteMineral, 'part' => $idParticion];
            }

            $existentes = DB::table('lote_guia')->where('id_guia_primer_tramo', $id)->get();
            foreach ($existentes as $ex) {
                $match = false;
                foreach ($pairsActuales as $p) {
                    if ($p['part'] !== null) {
                        if ((int) $ex->id_particion_lote_mineral === $p['part']) {
                            $match = true;
                            break;
                        }
                    } else {
                        if ((int) $ex->id_lote_mineral === $p['lote'] && $ex->id_particion_lote_mineral === null) {
                            $match = true;
                            break;
                        }
                    }
                }
                if (! $match) {
                    DB::table('lote_guia')->where('id', $ex->id)->delete();
                }
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            return ApiResponse::error('Error al actualizar la guía: '.$e->getMessage());
        }

        $guiaActualizada = GuiasPrimerTramoData::get_guia_by_id($id);

        self::sincronizar_guia_unidad(
            (int) ($nuevosValoresCab['id_recepcion_unidad'] ?? 0),
            (int) ($nuevosValoresCab['id_proveedor'] ?? 0),
            $guiaRemitente !== '' ? $guiaRemitente : null,
            $guiaTransportista,
            $idEmpleado ?? null,
            $id,
            $documentos
        );

        return ApiResponse::success($guiaActualizada, 'Guía de primer tramo actualizada correctamente.');
    }

    /**
     * Anular una guía de primer tramo (cambiar estado a Inactivo).
     */
    public static function anular_guia(int $id, ?Request $request = null): array
    {
        try {
            DB::beginTransaction();

            $guia = DB::table('guia_primer_tramo')->where('id', $id)->first();
            if (! $guia) {
                DB::rollBack();

                return ApiResponse::error('No se encontró la guía de primer tramo.');
            }

            DB::table('guia_primer_tramo')->where('id', $id)->update([
                'estado' => EstadoGuiaPrimerTramo::Anulado->value,
            ]);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            return ApiResponse::error('Error al anular la guía: '.$e->getMessage());
        }

        $guiaAnulada = GuiasPrimerTramoData::get_guia_by_id($id);

        return ApiResponse::success($guiaAnulada, 'Guía de primer tramo anulada correctamente.');
    }
}
