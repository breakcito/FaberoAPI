<?php

namespace App\Modules\CierreLeyes\Controllers;

use App\Modules\CierreLeyes\Services\CierreLeyesService;
use App\Shared\Responses\ApiResponse;
use Illuminate\Http\Request;

class CierreLeyesController
{
    public function get_lotes_sugeridos()
    {
        return response()->json(CierreLeyesService::get_lotes_sugeridos());
    }

    public function iniciar_lote(Request $request)
    {
        $request->validate([
            'id_lote_mineral' => 'required|integer|exists:lote_mineral,id',
        ]);

        $authUser = $request->attributes->get('auth_user');
        if (! $authUser || empty($authUser->id_empleado)) {
            return response()->json(ApiResponse::error('No se pudo determinar el empleado logueado para iniciar el análisis.'), 401);
        }

        return response()->json(CierreLeyesService::iniciar_lote(
            (int) $request->input('id_lote_mineral'),
            (int) $authUser->id_empleado
        ));
    }

    public function get_lotes_cierre(Request $request)
    {
        $request->validate([
            'estado' => 'nullable|string',
            'fecha_inicio' => 'nullable|date_format:Y-m-d',
            'fecha_fin' => 'nullable|date_format:Y-m-d|after_or_equal:fecha_inicio',
        ]);

        return response()->json(CierreLeyesService::get_lotes_cierre(
            $request->query('estado'),
            $request->query('fecha_inicio'),
            $request->query('fecha_fin'),
        ));
    }

    public function guardar_valor_ley(Request $request)
    {
        $request->validate([
            'id' => 'nullable|integer',
            'id_lote_mineral' => 'required|integer',
            'id_grupo_analisis_detalle' => 'required|integer',
            'tipo_origen' => 'nullable|string|in:Proveedor,Interno',
            'uuid_fila' => 'required|string|max:36',
            'ley' => 'required|numeric|min:0',
            'esta_confirmada' => 'required|boolean',
        ]);

        $authUser = $request->attributes->get('auth_user');
        

        return response()->json(CierreLeyesService::guardar_valor_ley(
            (int) $request->input('id_lote_mineral'),
            (int) $request->input('id_grupo_analisis_detalle'),
            $request->input('tipo_origen'),
            $request->input('uuid_fila'),
            (float) $request->input('ley'),
            (bool) $request->input('esta_confirmada'),
            (int) $authUser->id_empleado,
            $request->input('id') !== null ? (int) $request->input('id') : null
        ));
    }

    public function eliminar_valor(int $id)
    {
        return response()->json(CierreLeyesService::eliminar_valor($id));
    }

    public function eliminar_fila(int $idLoteMineral, string $uuidFila)
    {
        return response()->json(CierreLeyesService::eliminar_fila($idLoteMineral, $uuidFila));
    }

    public function confirmar_lote_leyes(Request $request)
    {
        $request->validate([
            'id_lote_mineral' => 'required|integer|exists:lote_mineral,id',
            'con_valor_comercial' => 'required|boolean',
            'leyes_manuales' => 'nullable|array',
            'leyes_manuales.*.id_grupo_analisis_detalle' => 'required_with:leyes_manuales|integer',
            'leyes_manuales.*.ley' => 'required_with:leyes_manuales|numeric|min:0',
        ]);

        $authUser = $request->attributes->get('auth_user');
        if (! $authUser || empty($authUser->id_empleado)) {
            return response()->json(ApiResponse::error('No se pudo determinar el empleado logueado para confirmar el cierre.'), 401);
        }

        $leyesManuales = null;
        if ($request->has('leyes_manuales') && is_array($request->input('leyes_manuales'))) {
            $leyesManuales = [];
            foreach ($request->input('leyes_manuales') as $entrada) {
                if (isset($entrada['id_grupo_analisis_detalle'], $entrada['ley'])) {
                    $leyesManuales[(int) $entrada['id_grupo_analisis_detalle']] = (float) $entrada['ley'];
                }
            }
            if (empty($leyesManuales)) {
                $leyesManuales = null;
            }
        }

        return response()->json(CierreLeyesService::confirmar_lote_leyes(
            (int) $request->input('id_lote_mineral'),
            (bool) $request->input('con_valor_comercial'),
            (int) $authUser->id_empleado,
            $leyesManuales
        ));
    }

    public function actualizar_origen_fila(Request $request, int $idLoteMineral, string $uuidFila)
    {
        $request->validate([
            'tipo_origen' => 'nullable|string|in:Proveedor,Interno',
        ]);

        $authUser = $request->attributes->get('auth_user');
        $idEmpleado = ($authUser && ! empty($authUser->id_empleado)) ? (int) $authUser->id_empleado : 1;

        return response()->json(CierreLeyesService::actualizar_origen_fila(
            $idLoteMineral,
            $uuidFila,
            $request->input('tipo_origen'),
            $idEmpleado
        ));
    }

    public function agregar_analisis(Request $request, int $idLoteMineral)
    {
        $authUser = $request->attributes->get('auth_user');
        if (! $authUser || empty($authUser->id_empleado)) {
            return response()->json(ApiResponse::error('No se pudo determinar el empleado logueado para agregar el análisis.'), 401);
        }

        return response()->json(CierreLeyesService::agregar_analisis(
            $idLoteMineral,
            (int) $authUser->id_empleado
        ));
    }

    public function iniciar_muestra_externa(Request $request)
    {
        $request->validate([
            'id_proveedor_minero' => 'required|integer',
            'codigo_cliente' => 'nullable|string|max:20',
            'fecha_hora_ingreso' => 'nullable|date',
        ]);

        $authUser = $request->attributes->get('auth_user');
        if (! $authUser || empty($authUser->id_empleado)) {
            return response()->json(ApiResponse::error('No se pudo determinar el empleado logueado para iniciar la muestra externa.'), 401);
        }

        return response()->json(CierreLeyesService::iniciar_muestra_externa(
            (int) $request->input('id_proveedor_minero'),
            (int) $authUser->id_empleado,
            $request->input('codigo_cliente'),
            $request->input('fecha_hora_ingreso')
        ));
    }

    public function get_muestras_externas()
    {
        return response()->json(CierreLeyesService::get_muestras_externas());
    }

    public function guardar_valor_muestra_externa(Request $request)
    {
        $request->validate([
            'id' => 'nullable|integer',
            'id_muestra_externa' => 'required|integer',
            'id_grupo_analisis_detalle' => 'required|integer',
            'tipo_origen' => 'nullable|string|in:Proveedor,Interno',
            'uuid_fila' => 'required|string|max:36',
            'ley' => 'required|numeric|min:0',
            'esta_confirmada' => 'required|boolean',
        ]);

        $authUser = $request->attributes->get('auth_user');

        return response()->json(CierreLeyesService::guardar_valor_muestra_externa(
            (int) $request->input('id_muestra_externa'),
            (int) $request->input('id_grupo_analisis_detalle'),
            $request->input('tipo_origen'),
            $request->input('uuid_fila'),
            (float) $request->input('ley'),
            (bool) $request->input('esta_confirmada'),
            (int) $authUser->id_empleado,
            $request->input('id') !== null ? (int) $request->input('id') : null
        ));
    }

    public function eliminar_fila_muestra_externa(int $idMuestraExterna, string $uuidFila)
    {
        return response()->json(CierreLeyesService::eliminar_fila_muestra_externa($idMuestraExterna, $uuidFila));
    }

    public function actualizar_origen_fila_muestra_externa(Request $request, int $idMuestraExterna, string $uuidFila)
    {
        $request->validate([
            'tipo_origen' => 'nullable|string|in:Proveedor,Interno',
        ]);

        $authUser = $request->attributes->get('auth_user');
        $idEmpleado = ($authUser && ! empty($authUser->id_empleado)) ? (int) $authUser->id_empleado : 1;

        return response()->json(CierreLeyesService::actualizar_origen_fila_muestra_externa(
            $idMuestraExterna,
            $uuidFila,
            $request->input('tipo_origen'),
            $idEmpleado
        ));
    }

    public function asociar_muestra_a_lote(Request $request, int $idMuestraExterna)
    {
        $request->validate([
            'id_lote_mineral' => 'required|integer|exists:lote_mineral,id',
        ]);

        $authUser = $request->attributes->get('auth_user');
        $idEmpleado = ($authUser && ! empty($authUser->id_empleado)) ? (int) $authUser->id_empleado : 1;

        return response()->json(CierreLeyesService::asociar_muestra_a_lote(
            $idMuestraExterna,
            (int) $request->input('id_lote_mineral'),
            $idEmpleado
        ));
    }

    public function agregar_analisis_muestra(int $idMuestraExterna)
    {
        $authUser = request()->attributes->get('auth_user');
        if (! $authUser || empty($authUser->id_empleado)) {
            return response()->json(ApiResponse::error('No se pudo determinar el empleado logueado para agregar el análisis.'), 401);
        }

        return response()->json(CierreLeyesService::agregar_analisis_muestra(
            $idMuestraExterna,
            (int) $authUser->id_empleado
        ));
    }

    public function get_muestras_asociadas_por_lote(Request $request)
    {
        $request->validate([
            'id_lote_mineral' => 'required|integer|exists:lote_mineral,id',
        ]);

        return response()->json(CierreLeyesService::get_muestras_asociadas_por_lote(
            (int) $request->input('id_lote_mineral')
        ));
    }
}
