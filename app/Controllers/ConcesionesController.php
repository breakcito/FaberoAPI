<?php

namespace App\Controllers;

use App\Services\ConcesionesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ConcesionesController extends Controller
{
    public function get_concesiones(): JsonResponse
    {
        return response()->json(ConcesionesService::get_concesiones());
    }

    public function get_concesiones_by_proveedor(Request $request): JsonResponse
    {
        $request->validate([
            'id_proveedor' => 'required|integer|min:1',
        ]);

        return response()->json(ConcesionesService::get_concesiones_by_proveedor(
            (int) $request->query('id_proveedor')
        ));
    }

    public function crear_concesion(Request $request): JsonResponse
    {
        $request->validate([
            'id_departamento' => 'required|integer|min:1',
            'id_provincia' => 'required|integer|min:1',
            'id_distrito' => 'required|integer|min:1',
            'nombre' => 'required|string|max:255',
            'codigo_reinfo' => 'nullable|string|max:64',
        ]);

        return response()->json(ConcesionesService::crear_concesion(
            (int) $request->id_departamento,
            (int) $request->id_provincia,
            (int) $request->id_distrito,
            $request->nombre,
            $request->codigo_reinfo
        ));
    }

    public function editar_concesion(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'id_departamento' => 'required|integer|min:1',
            'id_provincia' => 'required|integer|min:1',
            'id_distrito' => 'required|integer|min:1',
            'nombre' => 'required|string|max:255',
            'codigo_reinfo' => 'nullable|string|max:64',
        ]);

        return response()->json(ConcesionesService::editar_concesion(
            $id,
            (int) $request->id_departamento,
            (int) $request->id_provincia,
            (int) $request->id_distrito,
            $request->nombre,
            $request->codigo_reinfo
        ));
    }

    public function cambiar_estado_concesion(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'estado' => 'required|string|in:Activo,Inactivo',
        ]);

        return response()->json(ConcesionesService::cambiar_estado_concesion($id, $request->estado));
    }
}
