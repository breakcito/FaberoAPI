<?php

namespace App\Modules\CierreLeyes\Services;

use App\Modules\CierreLeyes\Data\CierreLeyesData;
use App\Shared\Helpers\ArchivoHelper;
use App\Shared\Responses\ApiResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class CierreLeyesService
{
    /**
     * Obtener los lotes sugeridos pendientes de análisis.
     */
    public static function get_lotes_sugeridos(): array
    {
        $data = CierreLeyesData::get_lotes_sugeridos();

        return ApiResponse::success($data, 'Lotes sugeridos obtenidos correctamente');
    }

    /**
     * Obtener el listado de lotes para el cierre de leyes con sus grupos y análisis.
     */
    public static function get_lotes_cierre(?string $estado = null, ?string $fechaInicio = null, ?string $fechaFin = null, ?int $id = null): array
    {
        $filtros = [];

        if ($estado !== null && $estado !== '' && $estado !== 'Todos') {
            $filtros['estados'] = [$estado];
        }

        if ($fechaInicio) {
            $filtros['fecha_inicio'] = $fechaInicio;
        }

        if ($fechaFin) {
            $filtros['fecha_fin'] = $fechaFin;
        }

        $lotes = CierreLeyesData::get_lotes_cierre($id, $filtros);

        return ApiResponse::success($lotes, 'Listado de lotes obtenido correctamente');
    }

    /**
     * Crear un grupo de análisis para un lote
     */
    public static function crear_grupo(array $data, int $idEmpleado, array $archivos = []): array
    {
        if (empty($data['id_lote_mineral']) && empty($data['id_muestra_externa'])) {
            return ApiResponse::error('Debe especificar un lote o una muestra externa.');
        }

        $idLote = ! empty($data['id_lote_mineral']) ? (int) $data['id_lote_mineral'] : null;
        $nombre = ! empty($data['nombre']) ? trim($data['nombre']) : 'Grupo 1';

        DB::beginTransaction();
        try {
            $idGrupo = CierreLeyesData::crear_grupo([
                'id_lote_mineral' => $idLote,
                'id_muestra_externa' => ! empty($data['id_muestra_externa']) ? (int) $data['id_muestra_externa'] : 0,
                'id_empleado_registro' => $idEmpleado,
                'nombre' => $nombre,
            ]);

            // Si se indicaron datos de análisis (laboratorio, ensayo, etc.), creamos el análisis inicial
            if (! empty($data['id_laboratorio']) || ! empty($data['asumido_por']) || ! empty($data['numero_ensayo'])) {
                $docs = [];
                if (! empty($archivos)) {
                    $docs = ArchivoHelper::guardarArchivos('cierre_leyes', $archivos);
                }

                CierreLeyesData::agregar_analisis_a_grupo($idGrupo, [
                    'id_laboratorio' => ! empty($data['id_laboratorio']) ? (int) $data['id_laboratorio'] : null,
                    'asumido_por' => ! empty($data['asumido_por']) ? trim($data['asumido_por']) : 'Proveedor',
                    'numero_ensayo' => ! empty($data['numero_ensayo']) ? (int) $data['numero_ensayo'] : 1,
                    'tipo_origen' => ! empty($data['tipo_origen']) ? trim($data['tipo_origen']) : 'Interno',
                    'documentos' => $docs,
                    'sin_lote' => empty($idLote),
                ], $idEmpleado);
            }

            DB::commit();

            return ApiResponse::success(['id' => $idGrupo], 'Grupo de análisis creado correctamente');
        } catch (\Exception $e) {
            DB::rollBack();

            return ApiResponse::error('Error al crear el grupo de análisis: '.$e->getMessage());
        }
    }

    /**
     * Agregar un análisis a un grupo existente
     */
    public static function agregar_analisis_a_grupo(int $idGrupo, array $data, int $idEmpleado, array $archivos = []): array
    {
        $grupo = DB::table('grupo_analisis')->where('id', $idGrupo)->first();
        if ($grupo && ($grupo->tiene_propuesta_oro || $grupo->tiene_propuesta_plata)) {
            return ApiResponse::error('No se puede agregar análisis a un grupo que ya se encuentra en una propuesta de valorización.');
        }

        DB::beginTransaction();
        try {
            $docs = [];
            if (! empty($archivos)) {
                $docs = ArchivoHelper::guardarArchivos('cierre_leyes', $archivos);
            }

            $idAnalisis = CierreLeyesData::agregar_analisis_a_grupo($idGrupo, [
                'id_laboratorio' => ! empty($data['id_laboratorio']) ? (int) $data['id_laboratorio'] : null,
                'asumido_por' => ! empty($data['asumido_por']) ? trim($data['asumido_por']) : 'Proveedor',
                'numero_ensayo' => ! empty($data['numero_ensayo']) ? (int) $data['numero_ensayo'] : 1,
                'tipo_origen' => ! empty($data['tipo_origen']) ? trim($data['tipo_origen']) : 'Interno',
                'documentos' => $docs,
                'sin_lote' => ! empty($data['sin_lote']),
            ], $idEmpleado);

            DB::commit();

            return ApiResponse::success(['id' => $idAnalisis], 'Análisis agregado correctamente al grupo');
        } catch (\Exception $e) {
            DB::rollBack();

            return ApiResponse::error('Error al agregar análisis al grupo: '.$e->getMessage());
        }
    }

    /**
     * Guardar valores de leyes en una fila de análisis
     */
    public static function guardar_valores_analisis(int $idAnalisis, array $valores, int $idEmpleado, ?string $motivo = null): array
    {
        try {
            $analisis = DB::table('analisis_mineral')->where('id', $idAnalisis)->first();
            if ($analisis && $analisis->id_grupo_analisis) {
                $grupo = DB::table('grupo_analisis')->where('id', $analisis->id_grupo_analisis)->first();
                if ($grupo) {
                    if ($grupo->tiene_propuesta_oro && (isset($valores['ley_oro']) || isset($valores['ley_oro_confirmada']))) {
                        return ApiResponse::error('No se puede modificar las leyes de oro porque el grupo se encuentra en una propuesta de valorización.');
                    }
                    if ($grupo->tiene_propuesta_plata && (isset($valores['ley_plata']) || isset($valores['ley_plata_confirmada']))) {
                        return ApiResponse::error('No se puede modificar las leyes de plata porque el grupo se encuentra en una propuesta de valorización.');
                    }
                }
            }

            $ok = CierreLeyesData::guardar_valores_analisis(
                $idAnalisis,
                $valores,
                $idEmpleado,
                $motivo ?? 'Actualización de leyes de análisis'
            );

            if (! $ok) {
                return ApiResponse::error('No se pudo encontrar el análisis para actualizar');
            }

            return ApiResponse::success(null, 'Valores de leyes guardados correctamente');
        } catch (\Exception $e) {
            return ApiResponse::error('Error al guardar valores: '.$e->getMessage());
        }
    }

    /**
     * Guardar leyes de cierre finales en el grupo
     */
    public static function guardar_leyes_cierre_grupo(int $idGrupo, array $leyes, int $idEmpleado, ?string $motivo = null): array
    {
        try {
            $grupo = DB::table('grupo_analisis')->where('id', $idGrupo)->first();
            if ($grupo) {
                if ($grupo->tiene_propuesta_oro && isset($leyes['ley_oro_cierre'])) {
                    return ApiResponse::error('No se puede modificar la ley de cierre de oro porque el grupo se encuentra en una propuesta de valorización.');
                }
                if ($grupo->tiene_propuesta_plata && isset($leyes['ley_plata_cierre'])) {
                    return ApiResponse::error('No se puede modificar la ley de cierre de plata porque el grupo se encuentra en una propuesta de valorización.');
                }
            }

            $ok = CierreLeyesData::guardar_leyes_cierre_grupo(
                $idGrupo,
                $leyes,
                $idEmpleado,
                $motivo ?? 'Cierre de leyes finales'
            );

            if (! $ok) {
                return ApiResponse::error('No se pudo encontrar el grupo para actualizar');
            }

            return ApiResponse::success(null, 'Leyes de cierre guardadas correctamente en el grupo');
        } catch (\Exception $e) {
            return ApiResponse::error('Error al guardar leyes de cierre: '.$e->getMessage());
        }
    }

    /**
     * Subir documentos a una fila de análisis
     */
    public static function subir_documentos_analisis(int $idAnalisis, array $archivos): array
    {
        if (empty($archivos)) {
            return ApiResponse::error('No se adjuntaron archivos para subir');
        }

        try {
            $nuevosDocs = ArchivoHelper::guardarArchivos('cierre_leyes', $archivos);
            $docsFinales = CierreLeyesData::adjuntar_documentos_analisis($idAnalisis, $nuevosDocs);

            if ($docsFinales === false) {
                return ApiResponse::error('No se pudo adjuntar documentos al análisis especificado');
            }

            return ApiResponse::success($docsFinales, 'Documentos adjuntados correctamente');
        } catch (\Exception $e) {
            return ApiResponse::error('Error al subir documentos: '.$e->getMessage());
        }
    }

    /**
     * Eliminar análisis
     */
    public static function eliminar_analisis(int $idAnalisis): array
    {
        try {
            $analisis = DB::table('analisis_mineral')->where('id', $idAnalisis)->first();
            if ($analisis && $analisis->id_grupo_analisis) {
                $grupo = DB::table('grupo_analisis')->where('id', $analisis->id_grupo_analisis)->first();
                if ($grupo && ($grupo->tiene_propuesta_oro || $grupo->tiene_propuesta_plata)) {
                    return ApiResponse::error('No se puede eliminar un ensayo de un grupo que se encuentra en una propuesta de valorización.');
                }
            }

            $ok = CierreLeyesData::eliminar_analisis($idAnalisis);
            if (! $ok) {
                return ApiResponse::error('Análisis no encontrado');
            }

            return ApiResponse::success(null, 'Análisis eliminado correctamente');
        } catch (\Exception $e) {
            return ApiResponse::error('Error al eliminar análisis: '.$e->getMessage());
        }
    }

    /**
     * Confirmar cierre del lote
     */
    public static function confirmar_lote(int $idLote, bool $conValorComercial, int $idEmpleado): array
    {
        try {
            $ok = CierreLeyesData::confirmar_lote($idLote, $conValorComercial, $idEmpleado);
            if (! $ok) {
                return ApiResponse::error('Lote no encontrado');
            }

            return ApiResponse::success(null, 'Lote cerrado correctamente');
        } catch (\Exception $e) {
            return ApiResponse::error('Error al confirmar lote: '.$e->getMessage());
        }
    }

    /**
     * Muestras externas
     */
    public static function get_muestras_externas(): array
    {
        $muestras = CierreLeyesData::get_muestras_externas();

        return ApiResponse::success($muestras, 'Muestras externas obtenidas correctamente');
    }

    /**
     * Iniciar muestra externa
     */
    public static function iniciar_muestra_externa(int $idEmpleado, array $data): array
    {
        DB::beginTransaction();
        try {
            $codigoCliente = ! empty($data['codigo_cliente'])
                ? trim((string) $data['codigo_cliente'])
                : (! empty($data['nombre']) ? trim((string) $data['nombre']) : null);

            $idProveedor = ! empty($data['id_proveedor']) ? (int) $data['id_proveedor'] : null;
            $fecha = ! empty($data['fecha']) ? $data['fecha'] : now();

            $sigNumero = (int) (DB::table('muestra_externa')->max('numero_correlativo') ?? 0) + 1;
            $correlativo = 'ME-' . str_pad((string) $sigNumero, 4, '0', STR_PAD_LEFT);

            // Guardar en tabla muestra_externa (NO en grupo_analisis)
            $idMuestra = DB::table('muestra_externa')->insertGetId([
                'id_empleado_registro' => $idEmpleado,
                'id_proveedor_minero' => $idProveedor,
                'codigo_cliente' => $codigoCliente,
                'correlativo' => $correlativo,
                'numero_correlativo' => $sigNumero,
                'fecha_hora_ingreso' => $fecha,
                'created_at' => now(),
            ]);

            // Guardar documentos si fueron subidos
            $docsJson = [];
            if (! empty($data['documentos']) && is_array($data['documentos'])) {
                /** @var UploadedFile[] $archivosSubidos */
                $archivosSubidos = array_filter($data['documentos'], fn ($f) => $f instanceof UploadedFile);
                if (count($archivosSubidos) > 0) {
                    $docsJson = ArchivoHelper::guardarArchivos('analisis_mineral', $archivosSubidos);
                }
            }

            // Si se especificó laboratorio o número de ensayo o documentos, crear primer análisis asociado a la muestra externa (sin grupo)
            if (! empty($data['id_laboratorio']) || ! empty($data['asumido_por']) || ! empty($docsJson)) {
                DB::table('analisis_mineral')->insert([
                    'id_grupo_analisis' => null,
                    'id_muestra_externa' => $idMuestra,
                    'id_laboratorio' => ! empty($data['id_laboratorio']) ? (int) $data['id_laboratorio'] : null,
                    'asumido_por' => ! empty($data['asumido_por']) ? trim((string) $data['asumido_por']) : 'Proveedor',
                    'numero_ensayo' => ! empty($data['numero_ensayo']) ? (int) $data['numero_ensayo'] : 1,
                    'tipo_origen' => 'Externo',
                    'sin_lote' => 1,
                    'documentos' => json_encode($docsJson),
                    'ley_oro' => 0.000,
                    'ley_plata' => 0.000,
                    'ley_humedad' => 0.000,
                    'ley_recuperacion' => 0.000,
                    'ley_oro_confirmada' => 0,
                    'ley_plata_confirmada' => 0,
                    'id_empleado_registro' => $idEmpleado,
                    'log_cambios' => json_encode([]),
                    'created_at' => now(),
                ]);
            }

            DB::commit();

            return ApiResponse::success([
                'id' => $idMuestra,
                'id_muestra_externa' => $idMuestra,
            ], 'Muestra externa creada correctamente');
        } catch (\Exception $e) {
            DB::rollBack();

            return ApiResponse::error('Error al crear muestra externa: '.$e->getMessage());
        }
    }

    /**
     * Agregar análisis directamente a una muestra externa
     */
    public static function agregar_analisis_a_muestra_externa(int $idMuestraExterna, array $data, int $idEmpleado, array $archivos = []): array
    {
        try {
            $muestra = DB::table('muestra_externa')->where('id', $idMuestraExterna)->first();
            if (! $muestra) {
                return ApiResponse::error('Muestra externa no encontrada');
            }

            $docs = [];
            if (! empty($archivos)) {
                $docs = ArchivoHelper::guardarArchivos('analisis_mineral', $archivos);
            }

            $maxEnsayo = (int) (DB::table('analisis_mineral')
                ->where('id_muestra_externa', $idMuestraExterna)
                ->max('numero_ensayo') ?? 0);

            $numeroEnsayo = ! empty($data['numero_ensayo']) ? (int) $data['numero_ensayo'] : ($maxEnsayo + 1);
            $tipoOrigen = ! empty($data['tipo_origen']) ? trim((string) $data['tipo_origen']) : 'Externo';

            // Obtener humedad y recuperación existentes de la muestra para mantenerlas idénticas
            $analisisPrevio = DB::table('analisis_mineral')
                ->where('id_muestra_externa', $idMuestraExterna)
                ->where(function ($q) {
                    $q->where('ley_humedad', '>', 0)
                      ->orWhere('ley_recuperacion', '>', 0);
                })
                ->first();

            $humedadActual = $analisisPrevio ? (float) $analisisPrevio->ley_humedad : 0.000;
            $recuperacionActual = $analisisPrevio ? (float) $analisisPrevio->ley_recuperacion : 0.000;

            $idAnalisis = DB::table('analisis_mineral')->insertGetId([
                'id_grupo_analisis' => null,
                'id_muestra_externa' => $idMuestraExterna,
                'id_laboratorio' => ! empty($data['id_laboratorio']) ? (int) $data['id_laboratorio'] : null,
                'asumido_por' => ! empty($data['asumido_por']) ? trim((string) $data['asumido_por']) : 'Proveedor',
                'numero_ensayo' => $numeroEnsayo,
                'tipo_origen' => $tipoOrigen,
                'sin_lote' => 1,
                'documentos' => json_encode($docs),
                'ley_oro' => 0.000,
                'ley_plata' => 0.000,
                'ley_humedad' => $humedadActual,
                'ley_recuperacion' => $recuperacionActual,
                'ley_oro_confirmada' => 0,
                'ley_plata_confirmada' => 0,
                'id_empleado_registro' => $idEmpleado,
                'log_cambios' => json_encode([]),
                'created_at' => now(),
            ]);

            return ApiResponse::success(['id' => $idAnalisis], 'Análisis agregado a la muestra externa correctamente');
        } catch (\Exception $e) {
            return ApiResponse::error('Error al agregar análisis a la muestra: '.$e->getMessage());
        }
    }

    /**
     * Asociar muestra externa a grupo
     */
    public static function asociar_muestra_a_grupo(int $idMuestraExterna, int $idGrupoDestino): array
    {
        return self::asociar_muestras_multiples_a_grupo([$idMuestraExterna], $idGrupoDestino);
    }

    /**
     * Asociar múltiples muestras externas a un grupo
     */
    public static function asociar_muestras_multiples_a_grupo(array $idsMuestraExterna, int $idGrupoDestino): array
    {
        if (empty($idsMuestraExterna)) {
            return ApiResponse::error('Debe seleccionar al menos una muestra para asociar');
        }

        try {
            DB::beginTransaction();
            $ok = CierreLeyesData::asociar_muestras_multiples_a_grupo($idsMuestraExterna, $idGrupoDestino);
            if (! $ok) {
                DB::rollBack();

                return ApiResponse::error('No se pudo encontrar el grupo destino especificado');
            }
            DB::commit();

            $msg = count($idsMuestraExterna) === 1
                ? 'Muestra asociada al grupo correctamente'
                : count($idsMuestraExterna).' muestras asociadas al grupo correctamente';

            return ApiResponse::success(null, $msg);
        } catch (\Exception $e) {
            DB::rollBack();

            return ApiResponse::error('Error al asociar muestras: '.$e->getMessage());
        }
    }

    /**
     * Obtener muestras asociadas a un grupo
     */
    public static function get_muestras_asociadas_por_grupo(int $idGrupo): array
    {
        $data = CierreLeyesData::get_muestras_asociadas_por_grupo($idGrupo);

        return ApiResponse::success($data, 'Muestras asociadas obtenidas correctamente');
    }
}
