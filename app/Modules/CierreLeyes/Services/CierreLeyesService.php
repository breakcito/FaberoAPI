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
     * Crear un grupo de análisis para un lote (con 0..N análisis iniciales)
     *
     * @param  array<int, array<string, mixed>>  $analisisItems
     */
    public static function crear_grupo(array $data, int $idEmpleado, array $analisisItems = []): array
    {
        if (empty($data['id_lote_mineral']) && empty($data['id_muestra_externa'])) {
            return ApiResponse::error('Debe especificar un lote o una muestra externa.');
        }

        $idLote = ! empty($data['id_lote_mineral']) ? (int) $data['id_lote_mineral'] : null;
        $nombre = ! empty($data['nombre']) ? trim((string) $data['nombre']) : 'Grupo 1';

        DB::beginTransaction();
        try {
            $idGrupo = CierreLeyesData::crear_grupo([
                'id_lote_mineral' => $idLote,
                'id_muestra_externa' => ! empty($data['id_muestra_externa']) ? (int) $data['id_muestra_externa'] : 0,
                'id_empleado_registro' => $idEmpleado,
                'nombre' => $nombre,
            ]);

            foreach ($analisisItems as $item) {
                $docs = [];
                if (! empty($item['archivos']) && is_array($item['archivos'])) {
                    /** @var UploadedFile[] $archivosValidos */
                    $archivosValidos = array_values(array_filter($item['archivos'], fn ($f) => $f instanceof UploadedFile));
                    if (count($archivosValidos) > 0) {
                        $docs = ArchivoHelper::guardarArchivos('cierre_leyes', $archivosValidos);
                    }
                }

                CierreLeyesData::agregar_analisis_a_grupo($idGrupo, [
                    'id_laboratorio' => ! empty($item['id_laboratorio']) ? (int) $item['id_laboratorio'] : null,
                    'asumido_por' => ! empty($item['asumido_por']) ? trim((string) $item['asumido_por']) : 'Proveedor',
                    'numero_ensayo' => ! empty($item['numero_ensayo']) ? (int) $item['numero_ensayo'] : null,
                    'tipo_origen' => ! empty($item['tipo_origen']) ? trim((string) $item['tipo_origen']) : 'Interno',
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
     * Agregar uno o múltiples análisis a un grupo existente
     *
     * @param  array<int, array<string, mixed>>  $analisisItems
     */
    public static function agregar_analisis_a_grupo(int $idGrupo, array $analisisItems, int $idEmpleado): array
    {
        $grupo = CierreLeyesData::get_grupo_by_id($idGrupo);
        if (! $grupo) {
            return ApiResponse::error('Grupo de análisis no encontrado.');
        }

        if ($grupo->tiene_propuesta_oro || $grupo->tiene_propuesta_plata) {
            return ApiResponse::error('No se puede agregar análisis a un grupo que ya se encuentra en una propuesta de valorización.');
        }

        if (empty($analisisItems)) {
            return ApiResponse::error('Debe indicar al menos un análisis para registrar.');
        }

        DB::beginTransaction();
        try {
            $ids = [];
            foreach ($analisisItems as $item) {
                $docs = [];
                if (! empty($item['archivos']) && is_array($item['archivos'])) {
                    /** @var UploadedFile[] $archivosValidos */
                    $archivosValidos = array_values(array_filter($item['archivos'], fn ($f) => $f instanceof UploadedFile));
                    if (count($archivosValidos) > 0) {
                        $docs = ArchivoHelper::guardarArchivos('cierre_leyes', $archivosValidos);
                    }
                }

                $ids[] = CierreLeyesData::agregar_analisis_a_grupo($idGrupo, [
                    'id_laboratorio' => ! empty($item['id_laboratorio']) ? (int) $item['id_laboratorio'] : null,
                    'asumido_por' => ! empty($item['asumido_por']) ? trim((string) $item['asumido_por']) : 'Proveedor',
                    'numero_ensayo' => ! empty($item['numero_ensayo']) ? (int) $item['numero_ensayo'] : null,
                    'tipo_origen' => ! empty($item['tipo_origen']) ? trim((string) $item['tipo_origen']) : 'Interno',
                    'documentos' => $docs,
                    'sin_lote' => ! empty($item['sin_lote']),
                ], $idEmpleado);
            }

            DB::commit();

            $msg = count($ids) === 1
                ? 'Análisis agregado correctamente al grupo'
                : count($ids).' análisis agregados correctamente al grupo';

            return ApiResponse::success(['id' => $ids[0] ?? 0, 'ids' => $ids], $msg);
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
            $analisis = CierreLeyesData::get_analisis_by_id($idAnalisis);
            if ($analisis && $analisis->id_grupo_analisis) {
                $grupo = CierreLeyesData::get_grupo_by_id((int) $analisis->id_grupo_analisis);
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
            $grupo = CierreLeyesData::get_grupo_by_id($idGrupo);
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
            $analisis = CierreLeyesData::get_analisis_by_id($idAnalisis);
            if ($analisis && $analisis->id_grupo_analisis) {
                $grupo = CierreLeyesData::get_grupo_by_id((int) $analisis->id_grupo_analisis);
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
     * Iniciar muestra externa (con 0..N análisis iniciales)
     *
     * @param  array<int, array<string, mixed>>  $analisisItems
     */
    public static function iniciar_muestra_externa(int $idEmpleado, array $data, array $analisisItems = []): array
    {
        DB::beginTransaction();
        try {
            $codigoCliente = ! empty($data['codigo_cliente'])
                ? trim((string) $data['codigo_cliente'])
                : (! empty($data['nombre']) ? trim((string) $data['nombre']) : null);

            $idProveedor = ! empty($data['id_proveedor']) ? (int) $data['id_proveedor'] : null;
            $fecha = ! empty($data['fecha']) ? $data['fecha'] : now();

            $idMuestra = CierreLeyesData::crear_muestra_externa([
                'id_empleado_registro' => $idEmpleado,
                'id_proveedor_minero' => $idProveedor,
                'codigo_cliente' => $codigoCliente,
                'fecha_hora_ingreso' => $fecha,
            ]);

            foreach ($analisisItems as $item) {
                $docsJson = [];
                if (! empty($item['archivos']) && is_array($item['archivos'])) {
                    /** @var UploadedFile[] $archivosSubidos */
                    $archivosSubidos = array_values(array_filter($item['archivos'], fn ($f) => $f instanceof UploadedFile));
                    if (count($archivosSubidos) > 0) {
                        $docsJson = ArchivoHelper::guardarArchivos('analisis_mineral', $archivosSubidos);
                    }
                }

                CierreLeyesData::agregar_analisis_a_muestra_externa($idMuestra, [
                    'id_laboratorio' => ! empty($item['id_laboratorio']) ? (int) $item['id_laboratorio'] : null,
                    'asumido_por' => ! empty($item['asumido_por']) ? trim((string) $item['asumido_por']) : 'Proveedor',
                    'numero_ensayo' => ! empty($item['numero_ensayo']) ? (int) $item['numero_ensayo'] : null,
                    'tipo_origen' => ! empty($item['tipo_origen']) ? trim((string) $item['tipo_origen']) : 'Externo',
                    'documentos' => $docsJson,
                ], $idEmpleado);
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
     * Agregar uno o múltiples análisis directamente a una muestra externa
     *
     * @param  array<int, array<string, mixed>>  $analisisItems
     */
    public static function agregar_analisis_a_muestra_externa(int $idMuestraExterna, array $analisisItems, int $idEmpleado): array
    {
        $muestra = CierreLeyesData::get_muestra_externa_by_id($idMuestraExterna);
        if (! $muestra) {
            return ApiResponse::error('Muestra externa no encontrada');
        }

        if (empty($analisisItems)) {
            return ApiResponse::error('Debe indicar al menos un análisis para registrar.');
        }

        DB::beginTransaction();
        try {
            $ids = [];
            foreach ($analisisItems as $item) {
                $docs = [];
                if (! empty($item['archivos']) && is_array($item['archivos'])) {
                    /** @var UploadedFile[] $archivosValidos */
                    $archivosValidos = array_values(array_filter($item['archivos'], fn ($f) => $f instanceof UploadedFile));
                    if (count($archivosValidos) > 0) {
                        $docs = ArchivoHelper::guardarArchivos('analisis_mineral', $archivosValidos);
                    }
                }

                $ids[] = CierreLeyesData::agregar_analisis_a_muestra_externa($idMuestraExterna, [
                    'id_laboratorio' => ! empty($item['id_laboratorio']) ? (int) $item['id_laboratorio'] : null,
                    'asumido_por' => ! empty($item['asumido_por']) ? trim((string) $item['asumido_por']) : 'Proveedor',
                    'numero_ensayo' => ! empty($item['numero_ensayo']) ? (int) $item['numero_ensayo'] : null,
                    'tipo_origen' => ! empty($item['tipo_origen']) ? trim((string) $item['tipo_origen']) : 'Externo',
                    'documentos' => $docs,
                ], $idEmpleado);
            }

            DB::commit();

            $msg = count($ids) === 1
                ? 'Análisis agregado a la muestra externa correctamente'
                : count($ids).' análisis agregados a la muestra externa correctamente';

            return ApiResponse::success(['id' => $ids[0] ?? 0, 'ids' => $ids], $msg);
        } catch (\Exception $e) {
            DB::rollBack();

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
