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
     */
    public static function get_ticket_ingreso(int $id, ?int $idGuia = null): array
    {
        $ticketInfo = RecepcionUnidadesData::get_ticket_ingreso_info($id, $idGuia);
        if (! $ticketInfo) {
            return ApiResponse::error('No se encontró la recepción de unidad o no se pudo generar el ticket.', 404);
        }

        return ApiResponse::success($ticketInfo, 'Ticket de ingreso obtenido correctamente');
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
     * @param  array  $data  Datos básicos. Claves opcionales para archivos de guías:
     *                       - guia_remitente_file: UploadedFile|null
     *                       - guia_transportista_file: UploadedFile|null
     *                       - documentos_programacion_existentes: array con estado actual
     */
    public static function crear_recepcion(array $data, array $archivos, ?array $visitaData = null): array
    {
        // Resolver documentos_programacion a partir de archivos subidos + existentes.
        $data = self::resolver_documentos_programacion(
            $data,
            $data['guia_remitente_file'] ?? null,
            $data['guia_transportista_file'] ?? null,
            is_array($data['documentos_programacion_existentes'] ?? null) ? $data['documentos_programacion_existentes'] : null,
        );

        // Validar unicidad de guías por proveedor.
        $erroresUnicidad = RecepcionUnidadesData::validar_unicidad_guia_proveedor(
            $data['id_proveedor_minero'] ?? null,
            $data['guia_remitente'] ?? null,
            $data['guia_transportista'] ?? null,
            null,
        );
        if ($erroresUnicidad !== null) {
            return ApiResponse::error($erroresUnicidad, 409);
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
     * Resolver el estado final de documentos_programacion mezclando archivos existentes y nuevos.
     */
    private static function resolver_documentos_programacion(
        array $data,
        ?UploadedFile $guiaRemitente,
        ?UploadedFile $guiaTransportista,
        ?array $existentes,
    ): array {
        $existentes = $existentes ?? [];
        $documentos = [
            'guia_remitente' => $existentes['guia_remitente'] ?? null,
            'guia_transportista' => $existentes['guia_transportista'] ?? null,
        ];

        if ($guiaRemitente instanceof UploadedFile && $guiaRemitente->isValid()) {
            $subidos = ArchivoHelper::guardarArchivos('documentos-programacion', [$guiaRemitente]);
            $documentos['guia_remitente'] = $subidos[0] ?? null;
        }
        if ($guiaTransportista instanceof UploadedFile && $guiaTransportista->isValid()) {
            $subidos = ArchivoHelper::guardarArchivos('documentos-programacion', [$guiaTransportista]);
            $documentos['guia_transportista'] = $subidos[0] ?? null;
        }

        $data['documentos_programacion'] = ($documentos['guia_remitente'] || $documentos['guia_transportista'])
            ? $documentos
            : null;

        unset(
            $data['guia_remitente_file'],
            $data['guia_transportista_file'],
            $data['documentos_programacion_existentes'],
        );

        return $data;
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
     *                                        id_empresa_transporte, id_conductor,
     *                                        id_proveedor_minero
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

        // Validación de campos desconocidos y de campos bloqueados.
        foreach ($campos as $campo => $valor) {
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
            'id_proveedor_minero' => 'proveedor',
        ];

        foreach ($campos as $campo => $valor) {
            if ($valor === null || $valor === '') {
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
                    $valorNuevo = ($valor === null || $valor === '') ? null : (int) $valor;

                    if ((int) ($valorAnterior ?? 0) === (int) ($valorNuevo ?? 0)) {
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
     */
    public static function registrar_salida(int $id, string $estadoSalida, ?string $observacionSalida, array $evidencias, int $idEmpleadoOperador): array
    {
        $recepcion = RecepcionUnidad::find($id);
        if (! $recepcion) {
            return ApiResponse::error('No se encontró el registro de recepción.');
        }

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
