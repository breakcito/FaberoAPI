<?php

namespace App\Modules\Perfil;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PerfilController
{
    /**
     * Obtener el perfil del usuario autenticado
     */
    public function get_perfil(Request $request): JsonResponse
    {
        $authUser = $request->attributes->get('auth_user');
        $response = PerfilService::get_perfil($authUser->id_usuario);

        return response()->json($response);
    }
}
