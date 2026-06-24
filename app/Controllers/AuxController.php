<?php

namespace App\Controllers;

use App\Services\EmpleadosService;
use App\Services\EmpresasService;
use App\Services\MarcasService;
use App\Services\ProveedoresService;
use App\Services\UbigeoService;
use App\Services\ConductoresService;
use App\Services\TiposVehiculoService;
use App\Services\EmpresasTransporteService;
use App\Services\VehiculosService;
use App\Services\MotivoIngresoService;
use App\Services\VisitanteService;
use App\Services\SucursalService;
use App\Services\ZonasOrigenService;
use App\Services\EncargadosMuestraService;
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
            'dni' => 'required|string|max:8',
            'nombre' => 'required|string|max:100',
            'apellido' => 'required|string|max:100',
            'numero_licencia' => 'required|string|max:20',
        ]);

        $result = ConductoresService::crear_conductor(
            dni: $request->input('dni'),
            nombre: $request->input('nombre'),
            apellido: $request->input('apellido'),
            numeroLicencia: $request->input('numero_licencia'),
            return_object: true
        );

        return response()->json($result);
    }
    /**
     * Función para obtener el listado de tipos de vehículo
     */
    public function get_tipos_vehiculo(Request $request): JsonResponse
    {
        $id = $request->input('id') ? (int) $request->input('id') : null;
        return response()->json(TiposVehiculoService::get_tipos_vehiculo($id));
    }
    /**
     * Crear un nuevo tipo de vehículo en el sistema
     */
    public function crear_tipo_vehiculo(Request $request): JsonResponse
    {
        $request->validate([
            'nombre' => 'required|string|max:100',
            'tiene_carreta' => 'nullable|boolean',
            'es_carreta' => 'nullable|boolean',
        ]);

        return response()->json(TiposVehiculoService::crear_tipo_vehiculo(
            $request->nombre,
            (bool) $request->input('tiene_carreta', false),
            (bool) $request->input('es_carreta', false)
        ));
    }

    public function editar_tipo_vehiculo(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'nombre' => 'required|string|max:100',
            'tiene_carreta' => 'nullable|boolean',
            'es_carreta' => 'nullable|boolean',
        ]);

        return response()->json(TiposVehiculoService::editar_tipo_vehiculo(
            $id,
            $request->nombre,
            (bool) $request->input('tiene_carreta', false),
            (bool) $request->input('es_carreta', false)
        ));
    }

    public function cambiar_estado_tipo_vehiculo(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'estado' => 'required|string|in:Activo,Inactivo',
        ]);

        return response()->json(TiposVehiculoService::cambiar_estado_tipo_vehiculo($id, $request->estado));
    }

    /**
     * Obtener listado de empresas de transporte activas (datos simplificados)
     */
    public function get_empresas_transporte(Request $request): JsonResponse
    {
        $id = $request->input('id') ? (int) $request->input('id') : null;
        return response()->json(EmpresasTransporteService::get_empresas_transporte($id));
    }

    /**
     * Obtener listado de vehículos (datos simplificados)
     */
    public function get_vehiculos(Request $request): JsonResponse
    {
        $serie = $request->input('serie') ?? $request->input('serie_placa');
        $numero_placa = $request->input('numero_placa');

        return response()->json(VehiculosService::get_vehiculos($serie, $numero_placa));
    }

    /**
     * Crear un nuevo vehículo de forma simplificada
     */
    public function crear_vehiculo(Request $request): JsonResponse
    {
        $request->validate([
            'serie_placa' => 'nullable|string|max:10',
            'numero_placa' => 'required|string|max:10',
            'id_empresa_transporte' => 'required|integer|exists:empresa_transporte,id',
            'id_tipo_vehiculo' => 'required|integer|exists:tipo_vehiculo,id',
        ]);

        $result = VehiculosService::crear_vehiculo_simplificado(
            $request->input('serie_placa'),
            $request->input('numero_placa'),
            (int) $request->input('id_empresa_transporte'),
            (int) $request->input('id_tipo_vehiculo')
        );

        return response()->json($result);
    }

    /**
     * Editar un vehículo de forma simplificada (transportista y tipo de vehículo)
     */
    public function editar_vehiculo(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'id_empresa_transporte' => 'required|integer|exists:empresa_transporte,id',
            'id_tipo_vehiculo' => 'required|integer|exists:tipo_vehiculo,id',
        ]);

        $result = VehiculosService::editar_vehiculo_simplificado(
            $id,
            (int) $request->input('id_empresa_transporte'),
            (int) $request->input('id_tipo_vehiculo')
        );

        return response()->json($result);
    }

    /**
     * Obtener listado de motivos de ingreso
     */
    public function get_motivos_ingreso(): JsonResponse
    {
        return response()->json(MotivoIngresoService::get_motivos_ingreso());
    }

    /**
     * Buscar visitante por su DNI
     */
    public function buscar_visitante_por_dni(Request $request): JsonResponse
    {
        $request->validate([
            'dni' => 'required|string|max:8',
        ]);

        return response()->json(VisitanteService::buscar_por_dni($request->query('dni')));
    }

    /**
     * Crear un nuevo visitante
     */
    public function crear_visitante(Request $request): JsonResponse
    {
        $request->validate([
            'nombre' => 'required|string|max:100',
            'apellido' => 'required|string|max:100',
            'dni' => 'required|string|max:8',
            'telefono' => 'nullable|string|max:50',
        ]);

        $data = [
            'nombre' => $request->input('nombre'),
            'apellido' => $request->input('apellido'),
            'dni' => $request->input('dni'),
            'telefono' => $request->input('telefono'),
        ];

        return response()->json(VisitanteService::crear_visitante($data));
    }

    /**
     * Obtener listado de sucursales activas para el select global
     */
    public function get_sucursales(Request $request): JsonResponse
    {
        $estado_val = $request->input('estado');
        $estado = $estado_val ? EstadoBase::from($estado_val) : EstadoBase::Activo;

        $authUser = $request->attributes->get('auth_user');
        $id_usuario = $authUser ? $authUser->id_usuario : null;

        return response()->json(SucursalService::get_sucursales($estado, $id_usuario));
    }

    /**
     * Obtener listado de zonas de origen activas
     */
    public function get_zonas_origen(): JsonResponse
    {
        return response()->json(ZonasOrigenService::get_zonas_origen());
    }

    /**
     * Registrar una nueva zona de origen
     */
    public function crear_zona_origen(Request $request): JsonResponse
    {
        $request->validate([
            'nombre' => 'required|string|max:150',
        ]);

        $result = ZonasOrigenService::crear_zona_origen(
            $request->input('nombre')
        );

        return response()->json($result);
    }

    /**
     * Obtener listado global de encargados de muestra (solo id y nombre completo)
     */
    public function get_encargados_muestra(): JsonResponse
    {
        return response()->json(EncargadosMuestraService::get_encargados_muestra());
    }
}
