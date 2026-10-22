<?php

namespace App\Modules\CierreLeyes\Controllers;

use App\Modules\CierreLeyes\Services\CierreLeyesService;
use App\Shared\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CierreLeyesController
{
    public function get_lotes_sugeridos(): JsonResponse
    {
        return response()->json(CierreLeyesService::get_lotes_sugeridos());
    }

    public function get_lotes_cierre(Request $request): JsonResponse
    {
        $request->validate([
            'estado' => 'nullable|string',
            'fecha_inicio' => 'nullable|date_format:Y-m-d',
            'fecha_fin' => 'nullable|date_format:Y-m-d',
        ]);

        return response()->json(CierreLeyesService::get_lotes_cierre(
            $request->query('estado'),
            $request->query('fecha_inicio'),
            $request->query('fecha_fin')
        ));
    }

    public function crear_grupo(Request $request): JsonResponse
    {
        $request->validate([
            'id_lote_mineral' => 'nullable|integer',
            'id_muestra_externa' => 'nullable|integer',
            'nombre' => 'nullable|string|max:50',
            'id_laboratorio' => 'nullable|integer',
            'asumido_por' => 'nullable|string|max:20',
            'numero_ensayo' => 'nullable|integer',
            'tipo_origen' => 'nullable|string|in:Interno,Externo',
            'documentos.*' => 'nullable|file',
        ]);

        $idEmpleado = (int) auth()->id();
        $archivos = $request->file('documentos') ?? [];
        if (! is_array($archivos)) {
            $archivos = [$archivos];
        }

        return response()->json(CierreLeyesService::crear_grupo(
            $request->all(),
            $idEmpleado,
            $archivos
        ));
    }

    public function agregar_analisis_grupo(Request $request, int $idGrupo): JsonResponse
    {
        $request->validate([
            'id_laboratorio' => 'nullable|integer',
            'asumido_por' => 'nullable|string|max:20',
            'numero_ensayo' => 'nullable|integer',
            'tipo_origen' => 'nullable|string|in:Interno,Externo',
            'documentos.*' => 'nullable|file',
        ]);

        $idEmpleado = (int) auth()->id();
        $archivos = $request->file('documentos') ?? [];
        if (! is_array($archivos)) {
            $archivos = [$archivos];
        }

        return response()->json(CierreLeyesService::agregar_analisis_a_grupo(
            $idGrupo,
            $request->all(),
            $idEmpleado,
            $archivos
        ));
    }

    public function guardar_valores_analisis(Request $request, int $idAnalisis): JsonResponse
    {
        $request->validate([
            'ley_oro' => 'nullable|numeric|min:0',
            'ley_plata' => 'nullable|numeric|min:0',
            'ley_humedad' => 'nullable|numeric|min:0',
            'ley_recuperacion' => 'nullable|numeric|min:0',
            'ley_oro_confirmada' => 'nullable|boolean',
            'ley_plata_confirmada' => 'nullable|boolean',
            'tipo_origen' => 'nullable|string|in:Interno,Externo',
            'asumido_por' => 'nullable|string|max:20',
            'numero_ensayo' => 'nullable|integer',
            'id_laboratorio' => 'nullable|integer',
            'motivo' => 'nullable|string',
        ]);

        $idEmpleado = (int) auth()->id();

        return response()->json(CierreLeyesService::guardar_valores_analisis(
            $idAnalisis,
            $request->all(),
            $idEmpleado,
            $request->input('motivo')
        ));
    }

    public function guardar_leyes_cierre_grupo(Request $request, int $idGrupo): JsonResponse
    {
        $request->validate([
            'ley_oro_cierre' => 'nullable|numeric|min:0',
            'ley_plata_cierre' => 'nullable|numeric|min:0',
            'ley_humedad_cierre' => 'nullable|numeric|min:0',
            'ley_recuperacion_cierre' => 'nullable|numeric|min:0',
            'motivo' => 'nullable|string',
        ]);

        $idEmpleado = (int) auth()->id();

        return response()->json(CierreLeyesService::guardar_leyes_cierre_grupo(
            $idGrupo,
            $request->all(),
            $idEmpleado,
            $request->input('motivo')
        ));
    }

    public function subir_documentos_analisis(Request $request, int $idAnalisis): JsonResponse
    {
        $request->validate([
            'documentos' => 'required',
            'documentos.*' => 'file',
        ]);

        $archivos = $request->file('documentos') ?? [];
        if (! is_array($archivos)) {
            $archivos = [$archivos];
        }

        return response()->json(CierreLeyesService::subir_documentos_analisis($idAnalisis, $archivos));
    }

    public function eliminar_analisis(int $idAnalisis): JsonResponse
    {
        return response()->json(CierreLeyesService::eliminar_analisis($idAnalisis));
    }

    public function confirmar_lote(Request $request, int $idLote): JsonResponse
    {
        $request->validate([
            'con_valor_comercial' => 'required|boolean',
        ]);

        $idEmpleado = (int) auth()->id();

        return response()->json(CierreLeyesService::confirmar_lote(
            $idLote,
            (bool) $request->input('con_valor_comercial'),
            $idEmpleado
        ));
    }

    public function get_muestras_externas(): JsonResponse
    {
        return response()->json(CierreLeyesService::get_muestras_externas());
    }

    public function iniciar_muestra_externa(Request $request): JsonResponse
    {
        $idEmpleado = (int) auth()->id();
        $data = $request->validate([
            'nombre' => 'nullable|string|max:100',
            'codigo_cliente' => 'nullable|string|max:100',
            'id_proveedor' => 'nullable|integer',
            'proveedor_nombre' => 'nullable|string|max:150',
            'fecha' => 'nullable|string',
            'id_laboratorio' => 'nullable|integer',
            'asumido_por' => 'nullable|string|max:50',
            'numero_ensayo' => 'nullable|integer',
            'documentos' => 'nullable|array',
            'documentos.*' => 'file|max:20480',
        ]);

        return response()->json(CierreLeyesService::iniciar_muestra_externa($idEmpleado, $data));
    }

    public function agregar_analisis_muestra_externa(Request $request, int $idMuestraExterna): JsonResponse
    {
        $request->validate([
            'id_laboratorio' => 'nullable|integer',
            'asumido_por' => 'nullable|string|max:50',
            'numero_ensayo' => 'nullable|integer',
            'tipo_origen' => 'nullable|string|in:Interno,Externo',
            'documentos.*' => 'nullable|file',
        ]);

        $idEmpleado = (int) auth()->id();
        $archivos = $request->file('documentos') ?? [];
        if (! is_array($archivos)) {
            $archivos = [$archivos];
        }

        return response()->json(CierreLeyesService::agregar_analisis_a_muestra_externa(
            $idMuestraExterna,
            $request->all(),
            $idEmpleado,
            $archivos
        ));
    }

    public function asociar_muestra_a_grupo(Request $request, int $idMuestraExterna): JsonResponse
    {
        $request->validate([
            'id_grupo_analisis' => 'required|integer',
        ]);

        return response()->json(CierreLeyesService::asociar_muestra_a_grupo(
            $idMuestraExterna,
            (int) $request->input('id_grupo_analisis')
        ));
    }

    public function asociar_muestras_multiples_a_grupo(Request $request): JsonResponse
    {
        $request->validate([
            'ids_muestra_externa' => 'required|array',
            'ids_muestra_externa.*' => 'integer',
            'id_grupo_analisis' => 'required|integer',
        ]);

        return response()->json(CierreLeyesService::asociar_muestras_multiples_a_grupo(
            $request->input('ids_muestra_externa', []),
            (int) $request->input('id_grupo_analisis')
        ));
    }

    public function get_muestras_asociadas_por_grupo(Request $request): JsonResponse
    {
        $request->validate([
            'id_grupo_analisis' => 'required|integer',
        ]);

        return response()->json(CierreLeyesService::get_muestras_asociadas_por_grupo(
            (int) $request->input('id_grupo_analisis')
        ));
    }
}
