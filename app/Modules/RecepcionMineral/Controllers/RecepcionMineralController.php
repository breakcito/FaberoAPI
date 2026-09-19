<?php

namespace App\Modules\RecepcionMineral\Controllers;

use App\Modules\RecepcionMineral\Services\RecepcionMineralService;
use App\Shared\Enums\_Generic\CondicionIngreso;
use App\Shared\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

class RecepcionMineralController extends Controller
{
    /**
     * Obtener listado de recepciones filtradas por sucursal
     */
    public function get_recepciones_mineral(Request $request): JsonResponse
    {
        $filters = [
            'id_sucursal' => $request->query('id_sucursal'),
            'estado_pesaje' => $request->query('estado_pesaje'),
        ];

        return response()->json(RecepcionMineralService::get_recepciones_mineral($filters));
    }

    /**
     * Iniciar el proceso de pesaje
     */
    public function iniciar_pesaje(Request $request, int $id): JsonResponse
    {
        return response()->json(RecepcionMineralService::iniciar_pesaje($id));
    }

    /**
     * Validar y actualizar un campo específico paso a paso
     */
    public function validar_campo(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'field' => 'required|string|in:condicion_ingreso,placa,empresa_transporte,tipo_vehiculo,id_vehiculo_carreta,conductor,fecha_hora_ingreso',
            'value' => 'nullable',
        ]);

        $field = $request->input('field');
        $value = $request->input('value');

        return response()->json(RecepcionMineralService::validar_campo($id, $field, $value));
    }

    /**
     * Crear un lote vacío asociado a una recepción de unidad.
     * Si `particionar=true`, crea además una partición A atómicamente.
     */
    public function crear_lote(Request $request, int $id): JsonResponse
    {
        $authUser = $request->attributes->get('auth_user');
        if (! $authUser || empty($authUser->id_empleado)) {
            return response()->json(ApiResponse::error('No se pudo determinar el empleado logueado.'), 401);
        }

        $request->validate([
            'condicion_ingreso' => ['required', Rule::enum(CondicionIngreso::class)],
            'id_empresa' => ['required', 'integer', 'exists:empresa,id'],
            'con_codigo_manual' => ['required', 'boolean'],
            'codigo_manual' => 'nullable|string|max:20',
            'particionar' => 'nullable|boolean',
        ]);

        $condicionIngreso = $request->input('condicion_ingreso');
        $idEmpresa = (int) $request->input('id_empresa');
        $conCodigoManual = $request->boolean('con_codigo_manual');
        $codigoManual = $conCodigoManual ? $request->input('codigo_manual') : null;
        $particionar = $request->boolean('particionar');

        return response()->json(RecepcionMineralService::crear_lote(
            $id,
            (int) $authUser->id_empleado,
            $condicionIngreso,
            $idEmpresa,
            $conCodigoManual,
            $codigoManual,
            $particionar,
        ));
    }

    /**
     * Eliminar un lote por su ID
     */
    public function eliminar_lote(Request $request, int $loteId): JsonResponse
    {
        return response()->json(RecepcionMineralService::eliminar_lote($loteId));
    }

    /**
     * Registrar peso inicial del lote
     */
    public function registrar_peso_inicial(Request $request, int $loteId): JsonResponse
    {
        $request->validate([
            'id_proveedor_minero' => 'nullable|integer|exists:proveedor,id',
            'id_zona_origen' => 'nullable|integer|exists:zona_origen,id',
            'numero_contacto' => 'nullable|string|max:50',
            'tipo_producto' => 'nullable|string|max:100',
            'tipo_mineral' => 'nullable|string|max:100',
            'peso_inicial' => 'required|numeric|gt:0',
            'observacion_peso_inicial' => 'nullable|string',
            'evidencias' => 'nullable|array',
            'evidencias.*' => 'file',
        ]);

        $data = [
            'id_proveedor_minero' => $request->input('id_proveedor_minero'),
            'id_zona_origen' => $request->input('id_zona_origen'),
            'numero_contacto' => $request->input('numero_contacto'),
            'tipo_producto' => $request->input('tipo_producto'),
            'tipo_mineral' => $request->input('tipo_mineral'),
            'peso_inicial' => $request->input('peso_inicial'),
            'observacion_peso_inicial' => $request->input('observacion_peso_inicial'),
        ];

        $archivos = [];
        if ($request->hasFile('evidencias')) {
            $archivos = $request->file('evidencias');
            if (! is_array($archivos)) {
                $archivos = [$archivos];
            }
        }

        return response()->json(RecepcionMineralService::registrar_peso_inicial($loteId, $data, $archivos));
    }

    /**
     * Registrar peso final del lote
     */
    public function registrar_peso_final(Request $request, int $loteId): JsonResponse
    {
        $request->validate([
            'peso_final' => 'required|numeric|gt:0',
            'observacion_peso_final' => 'nullable|string',
            'evidencias' => 'nullable|array',
            'evidencias.*' => 'file',
            'evidencias_existentes' => 'nullable|string',
            'id_proveedor_minero' => 'nullable|integer|exists:proveedor,id',
            'id_zona_origen' => 'nullable|integer|exists:zona_origen,id',
            'numero_contacto' => 'nullable|string|max:50',
            'tipo_producto' => 'nullable|string|max:100',
            'tipo_mineral' => 'nullable|string|max:100',
            'peso_inicial' => 'nullable|numeric|gt:0',
            'observacion_peso_inicial' => 'nullable|string',
            'id_vehiculo' => 'nullable|integer|exists:vehiculo,id',
            'id_empresa_transporte' => 'nullable|integer|exists:empresa_transporte,id',
            'id_tipo_vehiculo' => 'nullable|integer|exists:tipo_vehiculo,id',
            'id_conductor' => 'nullable|integer|exists:conductor,id',
        ]);

        $data = [
            'peso_final' => $request->input('peso_final'),
            'observacion_peso_final' => $request->input('observacion_peso_final'),
            'evidencias_existentes' => $request->input('evidencias_existentes'),
            'id_proveedor_minero' => $request->input('id_proveedor_minero'),
            'id_zona_origen' => $request->input('id_zona_origen'),
            'numero_contacto' => $request->input('numero_contacto'),
            'tipo_producto' => $request->input('tipo_producto'),
            'tipo_mineral' => $request->input('tipo_mineral'),
            'peso_inicial' => $request->input('peso_inicial'),
            'observacion_peso_inicial' => $request->input('observacion_peso_inicial'),
            'id_vehiculo' => $request->input('id_vehiculo'),
            'id_empresa_transporte' => $request->input('id_empresa_transporte'),
            'id_tipo_vehiculo' => $request->input('id_tipo_vehiculo'),
            'id_conductor' => $request->input('id_conductor'),
        ];

        $archivos = [];
        if ($request->hasFile('evidencias')) {
            $archivos = $request->file('evidencias');
            if (! is_array($archivos)) {
                $archivos = [$archivos];
            }
        }

        return response()->json(RecepcionMineralService::registrar_peso_final($loteId, $data, $archivos));
    }

    /**
     * Cerrar el proceso de balanza
     */
    public function cerrar_proceso(Request $request, int $id): JsonResponse
    {
        return response()->json(RecepcionMineralService::cerrar_proceso($id));
    }

    /**
     * Obtener el resumen de balanza filtrado
     */
    public function get_resumen_balanza(Request $request): JsonResponse
    {
        $filters = [
            'id_sucursal' => $request->query('id_sucursal'),
            'fecha_inicio' => $request->query('fecha_inicio'),
            'fecha_fin' => $request->query('fecha_fin'),
            'tipo_ingreso' => $request->query('tipo_ingreso'),
            'placa' => $request->query('placa'),
            'lote_correlativo' => $request->query('lote_correlativo'),
            'id_empresa_transporte' => $request->query('id_empresa_transporte'),
        ];

        return response()->json(RecepcionMineralService::get_resumen_balanza($filters));
    }

    /**
     * Actualizar toda la información de un lote de mineral (para Resumen de Balanza)
     */
    public function actualizar_lote(Request $request, int $loteId): JsonResponse
    {
        $authUser = $request->attributes->get('auth_user');
        $idEmpleado = $authUser ? (int) $authUser->id_empleado : null;

        $request->validate([
            'id_proveedor_minero' => 'nullable|integer|exists:proveedor,id',
            'id_zona_origen' => 'nullable|integer|exists:zona_origen,id',
            'numero_contacto' => 'nullable|string|max:50',
            'tipo_producto' => 'required|string|max:100',
            'tipo_mineral' => 'required|string|max:100',
            'peso_inicial' => 'nullable|numeric|min:0.01',
            'observacion_peso_inicial' => 'nullable|string',
            'peso_final' => 'nullable|numeric|min:0.01',
            'observacion_peso_final' => 'nullable|string',
            'evidencias' => 'nullable|array',
            'evidencias.*' => 'file',
            'evidencias_existentes' => 'nullable|string',
            'id_vehiculo' => 'nullable|integer|exists:vehiculo,id',
            'id_empresa_transporte' => 'nullable|integer|exists:empresa_transporte,id',
            'id_conductor' => 'nullable|integer|exists:conductor,id',
            'condicion_ingreso' => ['required', Rule::enum(CondicionIngreso::class)],
            'motivo' => 'nullable|string',
        ]);

        $data = [
            'id_proveedor_minero' => $request->input('id_proveedor_minero'),
            'id_zona_origen' => $request->input('id_zona_origen'),
            'numero_contacto' => $request->input('numero_contacto'),
            'tipo_producto' => $request->input('tipo_producto'),
            'tipo_mineral' => $request->input('tipo_mineral'),
            'peso_inicial' => $request->input('peso_inicial'),
            'observacion_peso_inicial' => $request->input('observacion_peso_inicial'),
            'peso_final' => $request->input('peso_final'),
            'observacion_peso_final' => $request->input('observacion_peso_final'),
            'evidencias_existentes' => $request->input('evidencias_existentes'),
            'id_vehiculo' => $request->input('id_vehiculo'),
            'id_empresa_transporte' => $request->input('id_empresa_transporte'),
            'id_conductor' => $request->input('id_conductor'),
            'condicion_ingreso' => $request->input('condicion_ingreso'),
            'motivo' => $request->input('motivo'),
        ];

        $archivos = [];
        if ($request->hasFile('evidencias')) {
            $archivos = $request->file('evidencias');
            if (! is_array($archivos)) {
                $archivos = [$archivos];
            }
        }

        return response()->json(RecepcionMineralService::actualizar_lote($loteId, $data, $archivos, $idEmpleado));
    }

    /**
     * Obtener los filtros para el resumen de balanza
     */
    public function get_resumen_filtros(Request $request): JsonResponse
    {
        $idSucursal = (int) $request->query('id_sucursal');
        if (! $idSucursal) {
            return response()->json(ApiResponse::error('Debe especificar la sucursal.'), 400);
        }

        return response()->json(RecepcionMineralService::get_resumen_filtros($idSucursal));
    }

    /**
     * Obtener datos para la impresión del Ticket de Balanza PDF
     */
    public function get_ticket_balanza(int $loteId): JsonResponse
    {
        return response()->json(RecepcionMineralService::get_ticket_balanza($loteId));
    }

    /**
     * Obtener datos para la impresión del Ticket de Balanza PDF a partir de un
     * id de distribucion_detalle (filas del Bloque B del Resumen de Balanza).
     */
    public function get_ticket_balanza_by_distribucion_detalle(int $idDistribucionDetalle): JsonResponse
    {
        return response()->json(RecepcionMineralService::get_ticket_balanza_by_distribucion_detalle($idDistribucionDetalle));
    }

    /**
     * Listar los lotes padre particionados desde Balanza con particiones activas en una sucursal.
     */
    public function get_lotes_padre_particionados(Request $request): JsonResponse
    {
        $idSucursal = (int) $request->query('id_sucursal');
        if (! $idSucursal) {
            return response()->json(ApiResponse::error('Debe especificar la sucursal.'), 400);
        }

        return response()->json(RecepcionMineralService::get_lotes_padre_particionados($idSucursal));
    }

    /**
     * Crear una partición adicional para un lote padre (drag a otra unidad).
     */
    public function crear_particion(Request $request, int $idLote): JsonResponse
    {
        $authUser = $request->attributes->get('auth_user');
        if (! $authUser || empty($authUser->id_empleado)) {
            return response()->json(ApiResponse::error('No se pudo determinar el empleado logueado.'), 401);
        }

        $request->validate([
            'id_recepcion_unidad' => 'required|integer|exists:recepcion_unidad,id',
        ]);

        return response()->json(RecepcionMineralService::crear_particion(
            $idLote,
            (int) $request->input('id_recepcion_unidad'),
            (int) $authUser->id_empleado,
        ));
    }

    /**
     * Listar las particiones activas de un lote.
     */
    public function listar_particiones(int $idLote): JsonResponse
    {
        return response()->json(RecepcionMineralService::listar_particiones($idLote));
    }

    /**
     * Detalle de una partición.
     */
    public function get_particion(int $idParticion): JsonResponse
    {
        return response()->json(RecepcionMineralService::get_particion($idParticion));
    }

    /**
     * Eliminar (físicamente) una partición. La fila se borra de la tabla
     * `particion_lote_mineral`, junto con sus archivos adjuntos. Antes del
     * borrado se genera un log de cambios en `lote_mineral.log_cambios`.
     */
    public function eliminar_particion(Request $request, int $idParticion): JsonResponse
    {
        $authUser = $request->attributes->get('auth_user');
        $idEmpleado = $authUser ? (int) $authUser->id_empleado : null;

        return response()->json(RecepcionMineralService::eliminar_particion($idParticion, $idEmpleado));
    }

    /**
     * Actualizar campos no-peso del lote padre desde una partición (cascada).
     */
    public function actualizar_campos_no_peso(Request $request, int $idParticion): JsonResponse
    {
        $authUser = $request->attributes->get('auth_user');
        $idEmpleado = $authUser ? (int) $authUser->id_empleado : null;

        $request->validate([
            'id_proveedor_minero' => 'nullable|integer|exists:proveedor,id',
            'id_zona_origen' => 'nullable|integer|exists:zona_origen,id',
            'numero_contacto' => 'nullable|string|max:50',
            'tipo_producto' => 'nullable|string|max:100',
            'tipo_mineral' => 'nullable|string|max:100',
        ]);

        $data = [
            'id_proveedor_minero' => $request->input('id_proveedor_minero'),
            'id_zona_origen' => $request->input('id_zona_origen'),
            'numero_contacto' => $request->input('numero_contacto'),
            'tipo_producto' => $request->input('tipo_producto'),
            'tipo_mineral' => $request->input('tipo_mineral'),
        ];

        return response()->json(RecepcionMineralService::actualizar_campos_no_peso(
            $idParticion,
            $data,
            $idEmpleado,
        ));
    }

    /**
     * Registrar peso inicial de una partición (crea ticket si no tiene).
     */
    public function registrar_peso_inicial_particion(Request $request, int $idParticion): JsonResponse
    {
        $request->validate([
            'peso_inicial' => 'required|numeric|gt:0',
            'observacion_peso_inicial' => 'nullable|string',
            'evidencias' => 'nullable|array',
            'evidencias.*' => 'file',
            'evidencias_existentes' => 'nullable',
            'id_proveedor_minero' => 'nullable|integer|exists:proveedor,id',
            'id_zona_origen' => 'nullable|integer|exists:zona_origen,id',
            'numero_contacto' => 'nullable|string|max:50',
            'tipo_producto' => 'nullable|string|max:100',
            'tipo_mineral' => 'nullable|string|max:100',
        ]);

        $pesoInicial = (float) $request->input('peso_inicial');
        if ($pesoInicial <= 0) {
            return response()->json(ApiResponse::error('El peso inicial debe ser mayor a cero.', 422), 422);
        }

        $data = [
            'peso_inicial' => $pesoInicial,
            'observacion_peso_inicial' => $request->input('observacion_peso_inicial'),
            'evidencias_existentes' => $request->input('evidencias_existentes'),
            'id_proveedor_minero' => $request->input('id_proveedor_minero'),
            'id_zona_origen' => $request->input('id_zona_origen'),
            'numero_contacto' => $request->input('numero_contacto'),
            'tipo_producto' => $request->input('tipo_producto'),
            'tipo_mineral' => $request->input('tipo_mineral'),
        ];

        $archivos = [];
        if ($request->hasFile('evidencias')) {
            $archivos = $request->file('evidencias');
            if (! is_array($archivos)) {
                $archivos = [$archivos];
            }
        }

        return response()->json(RecepcionMineralService::registrar_peso_inicial_particion(
            $idParticion,
            $data,
            $archivos,
        ));
    }

    /**
     * Registrar peso final de una partición.
     */
    public function registrar_peso_final_particion(Request $request, int $idParticion): JsonResponse
    {
        $request->validate([
            'peso_final' => 'required|numeric|gt:0',
            'observacion_peso_final' => 'nullable|string',
            'evidencias' => 'nullable|array',
            'evidencias.*' => 'file',
            'evidencias_existentes' => 'nullable',
            'id_proveedor_minero' => 'nullable|integer|exists:proveedor,id',
            'id_zona_origen' => 'nullable|integer|exists:zona_origen,id',
            'numero_contacto' => 'nullable|string|max:50',
            'tipo_producto' => 'nullable|string|max:100',
            'tipo_mineral' => 'nullable|string|max:100',
        ]);

        $pesoFinal = (float) $request->input('peso_final');
        if ($pesoFinal <= 0) {
            return response()->json(ApiResponse::error('El peso final debe ser mayor a cero.', 422), 422);
        }

        $data = [
            'peso_final' => $pesoFinal,
            'observacion_peso_final' => $request->input('observacion_peso_final'),
            'evidencias_existentes' => $request->input('evidencias_existentes'),
            'id_proveedor_minero' => $request->input('id_proveedor_minero'),
            'id_zona_origen' => $request->input('id_zona_origen'),
            'numero_contacto' => $request->input('numero_contacto'),
            'tipo_producto' => $request->input('tipo_producto'),
            'tipo_mineral' => $request->input('tipo_mineral'),
        ];

        $archivos = [];
        if ($request->hasFile('evidencias')) {
            $archivos = $request->file('evidencias');
            if (! is_array($archivos)) {
                $archivos = [$archivos];
            }
        }

        return response()->json(RecepcionMineralService::registrar_peso_final_particion(
            $idParticion,
            $data,
            $archivos,
        ));
    }

    /**
     * Finalizar el lote padre particionado desde Balanza.
     */
    public function finalizar_particion_lote(Request $request, int $idLote): JsonResponse
    {
        $authUser = $request->attributes->get('auth_user');
        if (! $authUser || empty($authUser->id_empleado)) {
            return response()->json(ApiResponse::error('No se pudo determinar el empleado logueado.'), 401);
        }

        return response()->json(RecepcionMineralService::finalizar_particion_lote(
            $idLote,
            (int) $authUser->id_empleado,
        ));
    }

    /**
     * Metadatos del ticket de balanza de una partición para impresión PDF.
     */
    public function get_ticket_balanza_particion(int $idParticion): JsonResponse
    {
        return response()->json(RecepcionMineralService::get_ticket_balanza_particion($idParticion));
    }
}
