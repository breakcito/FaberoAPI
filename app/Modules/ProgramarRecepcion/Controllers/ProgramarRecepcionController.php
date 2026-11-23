<?php

namespace App\Modules\ProgramarRecepcion\Controllers;

use App\Modules\ProgramarRecepcion\Services\ProgramarRecepcionService;
use App\Shared\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;

class ProgramarRecepcionController extends Controller
{
    /**
     * Listar programaciones (opcionalmente solo pendientes).
     */
    public function get_programaciones(Request $request): JsonResponse
    {
        $estadoConfirmacion = $request->query('estado_confirmacion');
        $soloPendientes = null;
        if ($request->has('solo_pendientes')) {
            $soloPendientes = filter_var($request->query('solo_pendientes'), FILTER_VALIDATE_BOOLEAN);
        }

        $filtros = [
            'estado_confirmacion' => $estadoConfirmacion,
            'solo_pendientes' => $soloPendientes,
            'fecha_inicio' => $request->query('fecha_inicio'),
            'fecha_fin' => $request->query('fecha_fin'),
        ];

        return response()->json(ProgramarRecepcionService::get_programaciones($filtros));
    }

    /**
     * Detalle completo de una programación: cabecera + visita asociada + vehículos + visitantes.
     */
    public function get_programacion(int $id): JsonResponse
    {
        return response()->json(ProgramarRecepcionService::get_programacion($id));
    }

    /**
     * Crear una nueva programación.
     *
     * El proveedor de la carga y las guías viajan en el contrato nuevo:
     * `proveedores` (ids) y `guias` (una entrada por proveedor). Los archivos
     * de cada guía suben aparte en `guias_archivos[<índice>][guia_*]`.
     */
    public function crear_programacion(Request $request): JsonResponse
    {
        $authUser = $request->attributes->get('auth_user');
        if (! $authUser || empty($authUser->id_empleado)) {
            return response()->json(ApiResponse::error('No se pudo determinar el empleado logueado para autorizar la programación.'), 401);
        }

        $data = $this->contrato_creacion($request);

        $validator = Validator::make($data, self::REGLAS_CONTRATO);
        if ($validator->fails()) {
            return response()->json(ApiResponse::error($validator->errors()->first()), 400);
        }

        $data['id_empleado_autoriza'] = (int) $authUser->id_empleado;

        return response()->json(ProgramarRecepcionService::crear_programacion($data));
    }

    /**
     * Actualizar una programación (solo si aún no fue confirmada).
     *
     * `tipo_ingreso` no forma parte del contrato de actualización: queda
     * bloqueado y el backend lo ignora aunque llegue en el request.
     */
    public function actualizar_programacion(Request $request, int $id): JsonResponse
    {
        $authUser = $request->attributes->get('auth_user');
        if (! $authUser || empty($authUser->id_empleado)) {
            return response()->json(ApiResponse::error('No se pudo determinar el empleado logueado para registrar la edición.'), 401);
        }

        $data = $this->contrato_creacion($request);

        $validator = Validator::make($data, self::REGLAS_CONTRATO);
        if ($validator->fails()) {
            return response()->json(ApiResponse::error($validator->errors()->first()), 400);
        }

        return response()->json(ProgramarRecepcionService::actualizar_programacion(
            $id,
            $data,
            (int) $authUser->id_empleado,
        ));
    }

    /**
     * Confirmar una programación (la marca como 'En Planta' y registra id_empleado_recepcion).
     * Acepta opcionalmente `observacion` y archivos `evidencias[]` (multipart) para
     * persistir también en `recepcion_unidad` con su entrada en `log_cambios`.
     *
     * El proveedor y las guías NO se modifican aquí: los conserva la
     * programación (misma fila de `recepcion_unidad`).
     */
    public function confirmar_programacion(Request $request, int $id): JsonResponse
    {
        $authUser = $request->attributes->get('auth_user');
        if (! $authUser || empty($authUser->id_empleado)) {
            return response()->json(ApiResponse::error('No se pudo determinar el empleado logueado para confirmar la programación.'), 401);
        }

        $request->validate([
            'observacion' => 'nullable|string',
            'evidencias' => 'nullable|array',
            'evidencias.*' => 'file',
        ]);

        $overrides = [];
        if ($request->input('id_vehiculo')) {
            $overrides['id_vehiculo'] = (int) $request->input('id_vehiculo');
        }
        if ($request->input('id_tipo_vehiculo')) {
            $overrides['id_tipo_vehiculo'] = (int) $request->input('id_tipo_vehiculo');
        }
        if ($request->input('id_sucursal')) {
            $overrides['id_sucursal'] = (int) $request->input('id_sucursal');
        }
        if ($request->input('id_conductor')) {
            $overrides['id_conductor'] = (int) $request->input('id_conductor');
        }
        if ($request->input('id_empresa_transporte')) {
            $overrides['id_empresa_transporte'] = (int) $request->input('id_empresa_transporte');
        }
        if ($request->filled('id_vehiculo_carreta')) {
            $overrides['id_vehiculo_carreta'] = (int) $request->input('id_vehiculo_carreta');
        }

        $observacion = $request->input('observacion');
        $archivos = [];
        if ($request->hasFile('evidencias')) {
            $archivos = $request->file('evidencias');
            if (! is_array($archivos)) {
                $archivos = [$archivos];
            }
        }

        return response()->json(ProgramarRecepcionService::confirmar_programacion(
            $id,
            (int) $authUser->id_empleado,
            $overrides,
            $observacion,
            $archivos,
        ));
    }

    /**
     * Editar la observación y/o evidencias de una recepción ya confirmada.
     * Persiste los cambios en `recepcion_unidad` con su entrada en `log_cambios`.
     */
    public function actualizar_observacion_evidencias(Request $request, int $id): JsonResponse
    {
        $authUser = $request->attributes->get('auth_user');
        if (! $authUser || empty($authUser->id_empleado)) {
            return response()->json(ApiResponse::error('No se pudo determinar el empleado logueado para registrar los cambios.'), 401);
        }

        $request->validate([
            'observacion' => 'nullable|string',
            'observacion_salida' => 'nullable|string',
            'evidencias_existentes' => 'nullable|string',
            'evidencias' => 'nullable|array',
            'evidencias.*' => 'file',
            'motivo' => 'nullable|string|max:500',
        ]);

        $evidenciasExistentes = [];
        if ($request->filled('evidencias_existentes')) {
            $decoded = json_decode($request->input('evidencias_existentes'), true);
            if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
                return response()->json(ApiResponse::error('El campo evidencias_existentes no es un JSON válido.'), 422);
            }
            $evidenciasExistentes = $decoded;
        }

        $archivosNuevos = [];
        if ($request->hasFile('evidencias')) {
            $archivosNuevos = $request->file('evidencias');
            if (! is_array($archivosNuevos)) {
                $archivosNuevos = [$archivosNuevos];
            }
        }

        return response()->json(ProgramarRecepcionService::actualizar_observacion_evidencias(
            $id,
            $request->input('observacion'),
            $request->input('observacion_salida'),
            $evidenciasExistentes,
            $archivosNuevos,
            (int) $authUser->id_empleado,
            $request->input('motivo'),
        ));
    }

    /**
     * Reglas de validación del contrato de creación/edición.
     *
     * @var array<string, string>
     */
    private const REGLAS_CONTRATO = [
        'id_empresa_transporte' => 'required|integer|exists:empresa_transporte,id',
        'id_vehiculo' => 'nullable|integer|exists:vehiculo,id',
        'id_tipo_vehiculo' => 'nullable|integer|exists:tipo_vehiculo,id',
        'id_conductor' => 'nullable|integer|exists:conductor,id',
        'id_sucursal' => 'nullable|integer|exists:sucursal,id',
        'id_vehiculo_carreta' => 'nullable|integer|exists:vehiculo,id',
        'fecha_estimada_llegada' => 'nullable|date',
        'observacion' => 'nullable|string',
        'tipo_ingreso' => 'nullable|string|max:50',
        'proveedores' => 'required|array|min:1',
        'proveedores.*' => 'integer|exists:proveedor,id',
        'guias' => 'nullable|array',
        'guias.*.id_proveedor' => 'required|integer|exists:proveedor,id',
        'guias.*.guia_remitente' => 'nullable|string|max:20',
        'guias.*.guia_transportista' => 'nullable|string|max:20',
        'guias.*.guia_remitente_archivo' => 'nullable|array',
        'guias.*.guia_remitente_archivo.url' => 'nullable|string|max:255',
        'guias.*.guia_remitente_archivo.path_relativo' => 'nullable|string|max:255',
        'guias.*.guia_remitente_archivo.nombre_original' => 'nullable|string|max:255',
        'guias.*.guia_remitente_archivo.extension' => 'nullable|string|max:20',
        'guias.*.guia_transportista_archivo' => 'nullable|array',
        'guias.*.guia_transportista_archivo.url' => 'nullable|string|max:255',
        'guias.*.guia_transportista_archivo.path_relativo' => 'nullable|string|max:255',
        'guias.*.guia_transportista_archivo.nombre_original' => 'nullable|string|max:255',
        'guias.*.guia_transportista_archivo.extension' => 'nullable|string|max:20',
    ];

    /**
     * Armar el contrato de datos de la programación desde el request.
     *
     * `proveedores` y `guias` pueden llegar como array (JSON body) o como
     * string JSON (FormData multipart, que es lo que usa el formulario por
     * los archivos adjuntos).
     */
    private function contrato_creacion(Request $request): array
    {
        $data = $request->all();
        $data['proveedores'] = self::decodificar_lista_int($request->input('proveedores'));
        $data['guias'] = self::decodificar_guias($request->input('guias'));
        $data['guias_archivos'] = $request->file('guias_archivos', []);

        return $data;
    }

    /**
     * Decodificar una lista de enteros que puede llegar como array o como
     * string JSON (FormData).
     *
     * @return array<int, int>
     */
    private static function decodificar_lista_int(mixed $valor): array
    {
        if (is_string($valor)) {
            $decoded = json_decode($valor, true);
            $valor = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($valor)) {
            return [];
        }

        $ids = [];
        foreach ($valor as $item) {
            $id = (int) $item;
            if ($id > 0 && ! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Decodificar el array de guías, que puede llegar como array o como
     * string JSON (FormData).
     *
     * @return array<int, mixed>
     */
    private static function decodificar_guias(mixed $valor): array
    {
        if (is_string($valor)) {
            $decoded = json_decode($valor, true);
            $valor = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($valor)) {
            return [];
        }

        return array_values($valor);
    }
}
