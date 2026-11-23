<?php

namespace App\Modules\RecepcionUnidades\Controllers;

use App\Modules\RecepcionUnidades\Services\RecepcionUnidadesService;
use App\Shared\Enums\_Generic\EstadoSalida;
use App\Shared\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RecepcionUnidadesController extends Controller
{
    /**
     * Obtener listado de recepciones filtradas.
     */
    public function get_recepciones(Request $request): JsonResponse
    {
        $filters = [
            'fecha_inicio' => $request->query('fecha_inicio'),
            'fecha_fin' => $request->query('fecha_fin'),
            'placa' => $request->query('placa'),
            'id_empresa_transporte' => $request->query('id_empresa_transporte'),
            'tipo_ingreso' => $request->query('tipo_ingreso'),
        ];

        return response()->json(RecepcionUnidadesService::get_recepciones($filters));
    }

    /**
     * Obtener una recepción puntual con sus lotes asociados.
     */
    public function get_recepcion(int $id): JsonResponse
    {
        return response()->json(RecepcionUnidadesService::get_recepcion($id));
    }

    /**
     * Obtener datos estructurados para imprimir el Ticket de Ingreso de Vehículos con Carga.
     *
     * `id_proveedor` acota el ticket a UN proveedor de la carga y `id_guia` a
     * UNA guía de primer tramo concreta (que identifica al proveedor).
     */
    public function get_ticket_ingreso(Request $request, int $id): JsonResponse
    {
        $idGuia = $request->query('id_guia') ? (int) $request->query('id_guia') : null;
        $idProveedor = $request->query('id_proveedor') ? (int) $request->query('id_proveedor') : null;

        return response()->json(RecepcionUnidadesService::get_ticket_ingreso($id, $idGuia, $idProveedor));
    }

    /**
     * Obtener los tickets de ingreso disponibles de una recepción de unidad
     * (uno por proveedor de la carga).
     */
    public function get_tickets_ingreso(int $id): JsonResponse
    {
        return response()->json(RecepcionUnidadesService::get_tickets_ingreso($id));
    }

    /**
     * Obtener las guías de primer tramo asociadas a la recepción.
     */
    public function get_guias(int $id): JsonResponse
    {
        return response()->json(RecepcionUnidadesService::get_guias_recepcion($id));
    }

    /**
     * Obtener las guías (remitente/transportista + archivos) por proveedor de
     * una recepción de unidad.
     */
    public function get_guias_recepcion(int $id): JsonResponse
    {
        return response()->json(RecepcionUnidadesService::get_guias($id));
    }

    /**
     * Reemplazar las guías (por proveedor) de una recepción de unidad.
     */
    public function set_guias_recepcion(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'guias' => 'required|array',
            'guias.*' => 'required|array',
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
        ]);

        $authUser = $request->attributes->get('auth_user');
        if (! $authUser || empty($authUser->id_empleado)) {
            return response()->json(ApiResponse::error('No se pudo determinar el empleado logueado.'), 401);
        }

        return response()->json(RecepcionUnidadesService::set_guias(
            $id,
            $request->input('guias', []),
            (int) $authUser->id_empleado
        ));
    }

    /**
     * Listar los proveedores de la carga de una recepción de unidad.
     */
    public function get_proveedores(int $id): JsonResponse
    {
        return response()->json(RecepcionUnidadesService::get_proveedores($id));
    }

    /**
     * Sincronizar la lista de proveedores de una recepción de unidad.
     */
    public function set_proveedores(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'proveedores' => 'required|array',
            'proveedores.*' => 'required|integer|exists:proveedor,id',
        ]);

        $authUser = $request->attributes->get('auth_user');
        if (! $authUser || empty($authUser->id_empleado)) {
            return response()->json(ApiResponse::error('No se pudo determinar el empleado logueado.'), 401);
        }

        return response()->json(RecepcionUnidadesService::set_proveedores(
            $id,
            $request->input('proveedores', []),
            (int) $authUser->id_empleado
        ));
    }

    /**
     * Registrar un nuevo ingreso/recepción de unidad.
     */
    public function crear_recepcion(Request $request): JsonResponse
    {
        // `proveedores` y `guias` viajan como string JSON cuando el request
        // es multipart (FormData): se decodifican ANTES de validar para que
        // las reglas `array` apliquen sobre la estructura real.
        $datos = $request->all();
        $datos['proveedores'] = self::decodificar_lista_int($request->input('proveedores'));
        $datos['guias'] = self::decodificar_guias($request->input('guias'));

        $validator = Validator::make($datos, [
            'id_vehiculo' => 'nullable|integer|exists:vehiculo,id',
            'id_vehiculo_carreta' => 'nullable|integer|exists:vehiculo,id',
            'id_empresa_transporte' => 'required|integer|exists:empresa_transporte,id',
            'id_tipo_vehiculo' => 'required|integer|exists:tipo_vehiculo,id',
            'id_conductor' => 'required|integer|exists:conductor,id',
            'proveedores' => 'nullable|array',
            'proveedores.*' => 'integer|exists:proveedor,id',
            'guias' => 'nullable|array',
            'guias.*' => 'array',
            'guias.*.id_proveedor' => 'required|integer|min:0',
            'guias.*.guia_remitente' => 'nullable|string|max:20',
            'guias.*.guia_transportista' => 'nullable|string|max:20',
            'guias.*.guia_remitente_archivo' => 'nullable|array',
            'guias.*.guia_transportista_archivo' => 'nullable|array',
            'tipo_ingreso' => 'nullable|string|max:50',
            'observacion' => 'nullable|string',
            'evidencias' => 'nullable|array',
            'evidencias.*' => 'file',
            'id_sucursal' => 'required|integer|exists:sucursal,id',
            'id_motivo_ingreso' => 'nullable|integer|exists:motivo_ingreso,id',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $authUser = $request->attributes->get('auth_user');
        if (! $authUser || empty($authUser->id_empleado)) {
            return response()->json(ApiResponse::error('No se pudo determinar el empleado logueado para registrar el ingreso.'), 401);
        }

        $guias = $datos['guias'];

        // `id_proveedor = 0` es la guía de la unidad (sin proveedor asignado
        // todavía): el proveedor se conoce con el registro de las cargas.
        foreach ($guias as $guia) {
            if (! is_array($guia)) {
                continue;
            }
            $idProveedor = (int) ($guia['id_proveedor'] ?? 0);
            if ($idProveedor <= 0) {
                continue;
            }
            if (! DB::table('proveedor')->where('id', $idProveedor)->exists()) {
                return response()->json(ApiResponse::error('El proveedor indicado en las guías no existe.'), 422);
            }
        }

        $documentosExistentes = null;
        if ($request->filled('documentos_programacion_existentes')) {
            $decoded = json_decode($request->input('documentos_programacion_existentes'), true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $documentosExistentes = $decoded;
            }
        }

        $data = [
            'id_empleado_registro' => (int) $authUser->id_empleado,
            'id_vehiculo' => $request->input('id_vehiculo') ? (int) $request->input('id_vehiculo') : null,
            'id_vehiculo_carreta' => $request->input('id_vehiculo_carreta') ? (int) $request->input('id_vehiculo_carreta') : null,
            'id_empresa_transporte' => (int) $request->input('id_empresa_transporte'),
            'id_tipo_vehiculo' => (int) $request->input('id_tipo_vehiculo'),
            'id_conductor' => (int) $request->input('id_conductor'),
            'proveedores' => self::decodificar_lista_int($request->input('proveedores')),
            'guias' => $guias,
            'tipo_ingreso' => $request->input('tipo_ingreso', 'Recepción de Mineral'),
            'observacion' => $request->input('observacion'),
            'id_sucursal' => (int) $request->input('id_sucursal'),
            // Campos legacy (un solo proveedor y un par de guías planas): los
            // resuelve el servicio como proveedor/guía principal.
            'id_proveedor_minero' => $request->input('id_proveedor_minero') ? (int) $request->input('id_proveedor_minero') : null,
            'guia_remitente' => $request->input('guia_remitente'),
            'guia_transportista' => $request->input('guia_transportista'),
            'guia_remitente_file' => $request->file('guia_remitente_file'),
            'guia_transportista_file' => $request->file('guia_transportista_file'),
            'documentos_programacion_existentes' => $documentosExistentes,
        ];

        // Obtener archivos subidos
        $archivos = [];
        if ($request->hasFile('evidencias')) {
            $archivos = $request->file('evidencias');
            if (! is_array($archivos)) {
                $archivos = [$archivos];
            }
        }

        // Parsear visitantes y sus fotos
        $visitantes = $request->input('visitantes', []);
        $archivosPorIndice = [];
        foreach ($visitantes as $index => $v) {
            $fileKey = "visitantes.{$index}.foto_documento";
            if ($request->hasFile($fileKey)) {
                $files = $request->file($fileKey);
                $archivosPorIndice[$index] = is_array($files) ? $files : [$files];
            }
        }

        // Parsear vehículos acompañantes y sus fotos
        $vehiculos = $request->input('vehiculos', []);
        $archivosVehiculos = [];
        foreach ($vehiculos as $vIndex => $v) {
            $fileKey = "vehiculos.{$vIndex}.archivos";
            if ($request->hasFile($fileKey)) {
                $files = $request->file($fileKey);
                $archivosVehiculos[$vIndex] = is_array($files) ? $files : [$files];
            }
        }

        $visitaData = [
            'id_motivo_ingreso' => $request->input('id_motivo_ingreso'),
            'observacion' => $request->input('observacion'),
            'visitantes' => $visitantes,
            'archivosPorIndice' => $archivosPorIndice,
            'vehiculos' => $vehiculos,
            'archivosVehiculos' => $archivosVehiculos,
        ];

        return response()->json(RecepcionUnidadesService::crear_recepcion($data, $archivos, $visitaData));
    }

    /**
     * Registrar la salida de la unidad (estado y observación).
     */
    public function registrar_salida(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'estado_salida' => ['required', 'string', Rule::in(array_column(EstadoSalida::cases(), 'value'))],
            'observacion_salida' => 'nullable|string',
        ]);

        $authUser = $request->attributes->get('auth_user');
        if (! $authUser || empty($authUser->id_empleado)) {
            return response()->json(ApiResponse::error('No se pudo determinar el empleado logueado.'), 401);
        }

        $evidencias = [];
        if ($request->hasFile('evidencias')) {
            $files = $request->file('evidencias');
            $evidencias = is_array($files) ? $files : [$files];
        } elseif ($request->hasFile('evidencias_salida')) {
            $files = $request->file('evidencias_salida');
            $evidencias = is_array($files) ? $files : [$files];
        }

        $result = RecepcionUnidadesService::registrar_salida(
            $id,
            $request->input('estado_salida'),
            $request->input('observacion_salida'),
            $evidencias,
            (int) $authUser->id_empleado
        );

        return response()->json($result);
    }

    /**
     * Editar campos puntuales de una recepción de unidad.
     *
     * Los campos registrados en una programación (de recepción o de despacho)
     * vienen bloqueados en `campos_bloqueados` y el backend los rechaza.
     * Cada cambio queda registrado en `log_cambios`.
     */
    public function actualizar_campos(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'campos' => 'required|array',
            'id_vehiculo' => 'nullable|integer',
            'id_tipo_vehiculo' => 'nullable|integer',
            'id_vehiculo_carreta' => 'nullable|integer',
            'id_empresa_transporte' => 'nullable|integer',
            'id_conductor' => 'nullable|integer',
            'id_proveedor_minero' => 'nullable|integer',
            'motivo' => 'nullable|string|max:255',
        ]);

        $authUser = $request->attributes->get('auth_user');
        if (! $authUser || empty($authUser->id_empleado)) {
            return response()->json(ApiResponse::error('No se pudo determinar el empleado logueado.'), 401);
        }

        $campos = $request->input('campos', []);
        if (! is_array($campos)) {
            $campos = [];
        }

        return response()->json(RecepcionUnidadesService::actualizar_campos(
            $id,
            $campos,
            (int) $authUser->id_empleado,
            $request->input('motivo')
        ));
    }

    /**
     * Listar los lotes asociados a una recepción de unidad.
     */
    public function get_lotes(int $id): JsonResponse
    {
        return response()->json(RecepcionUnidadesService::get_lotes($id));
    }

    /**
     * Generar un nuevo lote para la recepción de unidad indicada.
     * El backend debe retornar el correlativo asignado (ej. LOT-26-00005).
     */
    public function crear_lote(int $id): JsonResponse
    {
        $authUser = request()->attributes->get('auth_user');
        if (! $authUser || empty($authUser->id_empleado)) {
            return response()->json(ApiResponse::error('No se pudo determinar el empleado logueado para generar el lote.'), 401);
        }

        return response()->json(RecepcionUnidadesService::crear_lote($id, (int) $authUser->id_empleado));
    }

    /**
     * Eliminar un lote generado.
     */
    public function eliminar_lote(int $lote): JsonResponse
    {
        return response()->json(RecepcionUnidadesService::eliminar_lote($lote));
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
