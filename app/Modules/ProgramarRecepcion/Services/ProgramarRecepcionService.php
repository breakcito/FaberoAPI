<?php

namespace App\Modules\ProgramarRecepcion\Services;

use App\Modules\ProgramacionDespachos\Data\ProgramacionDespachosData;
use App\Modules\ProgramacionDespachos\Services\ProgramacionDespachosService;
use App\Modules\ProgramarRecepcion\Data\ProgramarRecepcionData;
use App\Modules\RecepcionUnidades\Data\RecepcionUnidadesData;
use App\Modules\RecepcionUnidades\Services\UnidadesParaPesarNotifier;
use App\Shared\Helpers\ArchivoHelper;
use App\Shared\Responses\ApiResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

class ProgramarRecepcionService
{
    /**
     * Listar programaciones.
     */
    public static function get_programaciones(array $filtros): array
    {
        $data = ProgramarRecepcionData::get_programaciones($filtros);

        return ApiResponse::success($data, 'Programaciones obtenidas correctamente');
    }

    /**
     * Detalle completo de una programación (cabecera + visita + vehículos + visitantes).
     */
    public static function get_programacion(int $id): array
    {
        $data = ProgramarRecepcionData::get_programacion_full($id);
        if ($data === null) {
            return ApiResponse::error('No se encontró la programación.', 404);
        }

        return ApiResponse::success($data, 'Programación obtenida correctamente');
    }

    /**
     * Crear una programación de recepción de unidad.
     *
     * La programación es una fila de `recepcion_unidad` con `es_programacion = 1`:
     * sus proveedores quedan en `proveedor_recepcion` y sus guías (con los
     * archivos adjuntos) en la columna JSON `guias`.
     *
     * @param  array  $data  Datos básicos de la programación. Claves del contrato
     *                       de carga:
     *                       - proveedores: int[] (ids de proveedor de la carga)
     *                       - guias: array (una entrada por proveedor)
     *                       - guias_archivos: array indexado por posición de la
     *                       guía, con los archivos nuevos subidos
     *                       (`guia_remitente` / `guia_transportista`).
     */
    public static function crear_programacion(array $data): array
    {
        $data = self::resolver_contrato_programacion($data);

        try {
            $data['guias'] = RecepcionUnidadesData::preparar_guias($data['guias']);
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }

        $erroresUnicidad = self::validar_unicidad_guias($data['guias'], null);
        if ($erroresUnicidad !== null) {
            return ApiResponse::error($erroresUnicidad, 409);
        }

        $id = ProgramarRecepcionData::crear_programacion($data);
        $nueva = RecepcionUnidadesData::get_recepcion_by_id($id);

        return ApiResponse::success($nueva, 'Programación registrada correctamente');
    }

    /**
     * Actualizar una programación (solo si NO está confirmada).
     *
     * `tipo_ingreso` no se envía: lo fija el proceso de la unidad y no es
     * editable. Los cambios quedan registrados en `log_cambios`.
     */
    public static function actualizar_programacion(int $id, array $data, int $idEmpleado): array
    {
        $data = self::resolver_contrato_programacion($data);

        try {
            $data['guias'] = RecepcionUnidadesData::preparar_guias($data['guias']);
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }

        $erroresUnicidad = self::validar_unicidad_guias($data['guias'], $id);
        if ($erroresUnicidad !== null) {
            return ApiResponse::error($erroresUnicidad, 409);
        }

        $ok = ProgramarRecepcionData::actualizar_programacion($id, $data, $idEmpleado);
        if (! $ok) {
            $recepcion = RecepcionUnidadesData::get_recepcion_by_id($id);
            if (! $recepcion) {
                return ApiResponse::error('No se encontró la programación indicada.', 404);
            }
            if (! empty($recepcion['id_empleado_recepcion'])) {
                return ApiResponse::error('Esta programación ya fue confirmada y no admite cambios.', 409);
            }

            return ApiResponse::error('No se pudo actualizar la programación.', 400);
        }

        $actualizada = ProgramarRecepcionData::get_programacion_full($id);

        return ApiResponse::success($actualizada, 'Programación actualizada correctamente');
    }

    /**
     * Resolver el contrato de proveedores y guías de la programación.
     *
     * - `proveedores`: ids de proveedor de la carga (sin repetidos).
     * - `guias`: una entrada por proveedor seleccionado, en el mismo orden.
     *   Cada entrada mezcla el archivo ya guardado (si lo había) con el
     *   archivo nuevo recién subido.
     *
     * Devuelve `$data` con `guias` y `proveedores` listos para persistir y
     * quita `guias_archivos` para no contaminar la fila.
     */
    private static function resolver_contrato_programacion(array $data): array
    {
        $guiasCrudas = is_array($data['guias'] ?? null) ? array_values($data['guias']) : [];
        $archivosPorIndice = is_array($data['guias_archivos'] ?? null) ? $data['guias_archivos'] : [];

        $guiasPorProveedor = [];
        foreach ($guiasCrudas as $indice => $entrada) {
            if (! is_array($entrada)) {
                continue;
            }

            $idProveedor = (int) ($entrada['id_proveedor'] ?? 0);
            if ($idProveedor <= 0) {
                continue;
            }

            $guia = [
                'id_proveedor' => $idProveedor,
                'guia_remitente' => (string) ($entrada['guia_remitente'] ?? ''),
                'guia_transportista' => (string) ($entrada['guia_transportista'] ?? ''),
                'guia_remitente_archivo' => $entrada['guia_remitente_archivo'] ?? null,
                'guia_transportista_archivo' => $entrada['guia_transportista_archivo'] ?? null,
            ];

            $archivos = $archivosPorIndice[$indice] ?? null;
            if (is_array($archivos)) {
                $guia['guia_remitente_archivo'] = self::resolver_archivo(
                    $guia['guia_remitente_archivo'],
                    $archivos['guia_remitente'] ?? null,
                );
                $guia['guia_transportista_archivo'] = self::resolver_archivo(
                    $guia['guia_transportista_archivo'],
                    $archivos['guia_transportista'] ?? null,
                );
            }

            $guiasPorProveedor[$idProveedor] = $guia;
        }

        $proveedores = [];
        foreach ((array) ($data['proveedores'] ?? []) as $id) {
            $id = (int) $id;
            if ($id > 0 && ! in_array($id, $proveedores, true)) {
                $proveedores[] = $id;
            }
        }

        // Una entrada de guía por proveedor, en el orden de los proveedores.
        $guias = [];
        foreach ($proveedores as $idProveedor) {
            $guias[] = $guiasPorProveedor[$idProveedor] ?? [
                'id_proveedor' => $idProveedor,
                'guia_remitente' => '',
                'guia_transportista' => '',
                'guia_remitente_archivo' => null,
                'guia_transportista_archivo' => null,
            ];
        }

        $data['proveedores'] = $proveedores;
        $data['guias'] = $guias;

        unset($data['guias_archivos']);

        return $data;
    }

    /**
     * Resolver el archivo final de una guía: el ya guardado o el recién
     * subido (que se persiste en el disco público).
     */
    private static function resolver_archivo(mixed $existente, mixed $nuevo): mixed
    {
        if ($nuevo instanceof UploadedFile && $nuevo->isValid()) {
            $subidos = ArchivoHelper::guardarArchivos('documentos-programacion', [$nuevo]);

            return $subidos[0] ?? null;
        }

        return $existente;
    }

    /**
     * Validar que no exista otra recepción con la misma guía para un
     * proveedor. Devuelve el primer mensaje de error, null si todo OK.
     *
     * @param  array<int, array<string, mixed>>  $guias
     */
    private static function validar_unicidad_guias(array $guias, ?int $excluirId): ?string
    {
        foreach ($guias as $guia) {
            $errorGuia = RecepcionUnidadesData::validar_unicidad_guia_proveedor(
                (int) $guia['id_proveedor'],
                $guia['guia_remitente'] ?? null,
                $guia['guia_transportista'] ?? null,
                $excluirId,
            );

            if ($errorGuia !== null) {
                return $errorGuia;
            }
        }

        return null;
    }

    /**
     * Confirmar una programación. Marca la fila como 'En Planta' y registra id_empleado_recepcion.
     * Si la recepción_unidad está vinculada a una distribución, dispara automáticamente la
     * transición En Espera → En Planta en la distribución (con su log_cambios).
     *
     * Acepta opcionalmente `observacion` y `archivosEvidencias` que se persisten en
     * `recepcion_unidad` con su entrada en `log_cambios`.
     */
    public static function confirmar_programacion(
        int $id,
        int $idEmpleadoRecepcion,
        array $overrides = [],
        ?string $observacion = null,
        array $archivosEvidencias = [],
    ): array {
        $ok = ProgramarRecepcionData::confirmar_programacion(
            $id,
            $idEmpleadoRecepcion,
            $overrides,
            $observacion,
            $archivosEvidencias,
        );
        if (! $ok) {
            // Diagnóstico del motivo real: el mensaje genérico confundía
            // (p.ej. una programación ya confirmada se reportaba como
            // "no existe"). Si el diagnóstico falla, se devuelve el error
            // genérico en vez de reventar la petición.
            try {
                $recepcion = RecepcionUnidadesData::get_recepcion_by_id($id);

                if (! $recepcion) {
                    return ApiResponse::error('No se encontró la programación indicada.', 404);
                }

                if (! (int) ($recepcion['es_programacion'] ?? 0)) {
                    return ApiResponse::error('El registro indicado no es una programación de unidad.', 422);
                }

                if (! empty($recepcion['id_empleado_recepcion'])) {
                    return ApiResponse::error('Esta programación ya fue confirmada.', 409);
                }
            } catch (\Throwable $e) {
                Log::warning('No se pudo diagnosticar el error de confirmación de la programación {id}: {msg}', [
                    'id' => $id,
                    'msg' => $e->getMessage(),
                ]);
            }

            return ApiResponse::error('No se pudo confirmar la programación.', 400);
        }

        $distribucionId = ProgramacionDespachosData::get_distribucion_id_for_recepcion_unidad($id);
        if ($distribucionId !== null) {
            // `notificar: false`: el aviso a la balanza se emite una sola vez,
            // al final de este método (la unidad es la misma).
            $result = ProgramacionDespachosService::confirmar_distribucion($distribucionId, $idEmpleadoRecepcion, notificar: false);
            if (! ($result['success'] ?? false)) {
                Log::warning('Auto-confirmar distribución {id} desde ProgramarRecepcion {ru} falló: {msg}', [
                    'id' => $distribucionId,
                    'ru' => $id,
                    'msg' => $result['message'] ?? 'unknown',
                ]);
            }
        }

        $actualizada = RecepcionUnidadesData::get_recepcion_by_id($id);

        // La programación pasa a "En Planta" y "Sin Pesar": la unidad entra a la
        // lista de la balanza, así que se le avisa por WebSocket.
        UnidadesParaPesarNotifier::notificar($id);

        return ApiResponse::success($actualizada, 'Programación confirmada correctamente');
    }

    /**
     * Editar la observación y/o evidencias de una recepción ya confirmada.
     * Devuelve la recepción actualizada o un error si no existe.
     */
    public static function actualizar_observacion_evidencias(
        int $id,
        ?string $observacion,
        ?string $observacionSalida,
        array $evidenciasExistentes,
        array $archivosNuevos,
        int $idEmpleado,
        ?string $motivo = null,
    ): array {
        $existe = RecepcionUnidadesData::get_recepcion_by_id($id);
        if (! $existe) {
            return ApiResponse::error('No se encontró la recepción a editar.', 404);
        }

        $cambios = ProgramarRecepcionData::actualizar_observacion_evidencias(
            $id,
            $observacion,
            $observacionSalida,
            $evidenciasExistentes,
            $archivosNuevos,
            $idEmpleado,
            // El motivo es opcional: si no lo manda el operador, el historial
            // registra la edición de la observación de la recepción.
            $motivo ?: 'Edición de observación de la recepción de unidad',
        );

        $actualizada = RecepcionUnidadesData::get_recepcion_by_id($id);

        $msg = $cambios > 0
            ? "Recepción actualizada ({$cambios} cambio(s) registrado(s))."
            : 'Sin cambios para guardar.';

        return ApiResponse::success($actualizada, $msg);
    }
}
