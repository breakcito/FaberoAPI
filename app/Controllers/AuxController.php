<?php

namespace App\Controllers;

use App\Services\EmpleadosService;
use App\Services\EmpresasService;
use App\Services\MarcasService;
use App\Services\ProveedoresService;
use App\Services\UbigeoService;
use App\Services\ConductoresService;
use App\Shared\Enums\_Generic\EstadoBase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class AuxController extends Controller
{
    public function get_empleados(Request $request): JsonResponse
    {
        $id_empleado = $request->input('id_empleado') ? (int) $request->input('id_empleado') : null;
        $estado_val = $request->input('estado');
        $estado = $estado_val ? EstadoBase::from($estado_val) : EstadoBase::Activo;

        $result = EmpleadosService::get_empleados(
            id_empleado: $id_empleado,
            estado: $estado
        );

        return response()->json($result);
    }

    /**
     * Obtener proveedores habilitados
     */
    public function get_proveedores(Request $request): JsonResponse
    {
        $id_proveedor = $request->input('id_proveedor') ? (int) $request->input('id_proveedor') : null;
        $estado_val = $request->input('estado');
        $estado = $estado_val ? EstadoBase::from($estado_val) : null;
        $tipo_entidad = $request->input('tipo_entidad');

        $result = ProveedoresService::get_proveedores(
            id_proveedor: $id_proveedor,
            estado: $estado,
            tipoEntidad: $tipo_entidad
        );

        return response()->json($result);
    }

    public function get_empresas(Request $request): JsonResponse
    {
        $id_empresa = $request->input('id_empresa') ? (int) $request->input('id_empresa') : null;
        $estado_val = $request->input('estado');
        $estado = $estado_val ? EstadoBase::from($estado_val) : null;

        return response()->json(EmpresasService::get_empresas(
            id_empresa: $id_empresa,
            estado: $estado
        ));
    }

    public function get_marcas(Request $request): JsonResponse
    {
        $id_marca = $request->input('id_marca') ? (int) $request->input('id_marca') : null;
        $estado_val = $request->input('estado');
        $estado = $estado_val ? EstadoBase::from($estado_val) : null;

        return response()->json(MarcasService::get_marcas(id_marca: $id_marca, estado: $estado));
    }

    public function crear_marca(Request $request): JsonResponse
    {
        $request->validate([
            'nombre' => 'required|string',
        ]);

        $result = MarcasService::crear_marca(
            nombre: $request->input('nombre')
        );

        return response()->json($result);
    }

    /**
     * Obtener listado de departamentos
     */
    public function get_departamentos(): JsonResponse
    {
        $result = UbigeoService::get_departamentos();

        return response()->json($result);
    }

    /**
     * Obtener listado de provincias por departamento
     */
    public function get_provincias(Request $request): JsonResponse
    {
        $id_departamento = (int) $request->input('id_departamento');
        $result = UbigeoService::get_provincias($id_departamento);

        return response()->json($result);
    }

    /**
     * Obtener listado de distritos por provincia
     */
    public function get_distritos(Request $request): JsonResponse
    {
        $id_provincia = (int) $request->input('id_provincia');
        $result = UbigeoService::get_distritos($id_provincia);

        return response()->json($result);
    }

    /**
     * Obtener el listado de conductores activos
     */
    public function get_conductores(): JsonResponse
    {
        $result = ConductoresService::get_conductores();
        return response()->json($result);
    }

    /**
     * Crear un nuevo conductor en el sistema
     */
    public function crear_conductor(Request $request): JsonResponse
    {
        $request->validate([
            'dni' => 'required|string',
            'nombre' => 'required|string',
            'apellido' => 'required|string',
            'numero_licencia' => 'required|string',
            'ruc' => 'nullable|string',
        ]);

        $result = ConductoresService::crear_conductor(
            dni: $request->input('dni'),
            nombre: $request->input('nombre'),
            apellido: $request->input('apellido'),
            numeroLicencia: $request->input('numero_licencia'),
            ruc: $request->input('ruc'),
            return_object: true
        );

        return response()->json($result);
    }
}
