<?php

namespace App\Modules\Proveedores\Controllers;

use App\Modules\Proveedores\Services\ProveedoresService;
use Illuminate\Http\Request;

class ProveedoresController
{
    public function get_proveedores()
    {
        return response()->json(ProveedoresService::get_proveedores());
    }

    public function crear_proveedor(Request $request)
    {
        $request->validate([
            'tipo_entidad' => 'required|string',
            'dni' => 'nullable|string|size:8',
            'ruc' => 'nullable|string|size:11',
            'razon_social' => 'required|string|max:255',
            'direccion' => 'nullable|string|max:255',
            'telefono' => 'nullable|string|max:20',
            'correo' => 'nullable|email|max:100',
            'origen_guias' => 'nullable|string|max:512',
            'destino_guias' => 'nullable|string|max:512',
        ]);

        return response()->json(ProveedoresService::crear_proveedor(
            $request->tipo_entidad,
            $request->dni,
            $request->ruc,
            $request->razon_social,
            $request->direccion,
            $request->telefono,
            $request->correo,
            $request->cuentas ?? [],
            $request->origen_guias,
            $request->destino_guias
        ));
    }

    public function editar_proveedor(Request $request, int $id)
    {
        $request->validate([
            'tipo_entidad' => 'required|string',
            'dni' => 'nullable|string|size:8',
            'ruc' => 'nullable|string|size:11',
            'razon_social' => 'required|string|max:255',
            'direccion' => 'nullable|string|max:255',
            'telefono' => 'nullable|string|max:20',
            'correo' => 'nullable|email|max:100',
            'origen_guias' => 'nullable|string|max:512',
            'destino_guias' => 'nullable|string|max:512',
        ]);

        return response()->json(ProveedoresService::editar_proveedor(
            $id,
            $request->tipo_entidad,
            $request->dni,
            $request->ruc,
            $request->razon_social,
            $request->direccion,
            $request->telefono,
            $request->correo,
            $request->origen_guias,
            $request->destino_guias
        ));
    }

    public function cambiar_estado_proveedor(Request $request, int $id)
    {
        $request->validate([
            'estado' => 'required|string|in:Activo,Inactivo',
        ]);

        return response()->json(ProveedoresService::cambiar_estado_proveedor($id, $request->estado));
    }

    public function eliminar_proveedor(Request $request, int $id)
    {
        return response()->json(ProveedoresService::eliminar_proveedor($id));
    }

    public function get_concesiones(Request $request, int $id)
    {
        return response()->json(ProveedoresService::get_concesiones($id));
    }

    public function asociar_concesion(Request $request)
    {
        $request->validate([
            'id_proveedor' => 'required|integer',
            'id_concesion' => 'required|integer',
        ]);

        return response()->json(ProveedoresService::asociar_concesion(
            (int) $request->id_proveedor,
            (int) $request->id_concesion
        ));
    }

    public function desasociar_concesion(Request $request, int $id_proveedor, int $id_concesion)
    {
        return response()->json(ProveedoresService::desasociar_concesion(
            $id_proveedor,
            $id_concesion
        ));
    }

    /**
     * Contactos registrados de un proveedor.
     */
    public function get_contactos(Request $request, int $id)
    {
        return response()->json(ProveedoresService::get_contactos($id));
    }

    /**
     * Contacto que debe figurar en los documentos del proveedor.
     *
     * Si el proveedor no tiene contactos, se replica la información del propio
     * proveedor (razón social, teléfono y correo).
     */
    public function get_contacto_documento(Request $request, int $id)
    {
        return response()->json(ProveedoresService::get_contacto_documento($id));
    }

    public function crear_contacto(Request $request)
    {
        $request->validate([
            'id_proveedor' => 'required|integer|exists:proveedor,id',
            'nombre_completo' => 'required|string|max:150',
            'dni' => 'nullable|string|max:20',
            'telefono' => 'nullable|string|max:30',
            'correo' => 'nullable|email|max:150',
        ]);

        $authUser = $request->attributes->get('auth_user');
        $idEmpleado = $authUser ? (int) ($authUser->id_empleado ?? $authUser->id_usuario) : 1;

        return response()->json(ProveedoresService::crear_contacto(
            (int) $request->id_proveedor,
            $idEmpleado,
            $request->nombre_completo,
            $request->dni,
            $request->telefono,
            $request->correo
        ));
    }

    public function editar_contacto(Request $request, int $id)
    {
        $request->validate([
            'nombre_completo' => 'required|string|max:150',
            'dni' => 'nullable|string|max:20',
            'telefono' => 'nullable|string|max:30',
            'correo' => 'nullable|email|max:150',
        ]);

        return response()->json(ProveedoresService::editar_contacto(
            $id,
            $request->nombre_completo,
            $request->dni,
            $request->telefono,
            $request->correo
        ));
    }

    public function eliminar_contacto(Request $request, int $id)
    {
        return response()->json(ProveedoresService::eliminar_contacto($id));
    }
}
