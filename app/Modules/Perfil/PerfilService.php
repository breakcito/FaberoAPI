<?php

namespace App\Modules\Perfil;

use App\Shared\Responses\ApiResponse;
use App\Modules\Perfil\Data\PerfilData;

class PerfilService
{
    /**
     * Obtener la información del perfil del usuario
     */
    public static function get_perfil(?int $id_usuario): array
    {
        if (!$id_usuario) {
            return ApiResponse::error('Usuario no autenticado.');
        }

        $info = PerfilData::get_info_perfil($id_usuario);

        if (!$info) {
            return ApiResponse::error('No se pudo encontrar la información del perfil.');
        }

        if ($info->path_foto) {
            $info->path_foto = asset('storage/' . $info->path_foto);
        }

        return ApiResponse::success($info);
    }
}
