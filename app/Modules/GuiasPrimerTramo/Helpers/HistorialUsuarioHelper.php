<?php

namespace App\Modules\GuiasPrimerTramo\Helpers;

use Illuminate\Http\Request;

class HistorialUsuarioHelper
{
    /**
     * Resuelve {id_usuario, usuario_nombre} desde el request autenticado.
     *
     * JwtAuthMiddleware setea `auth_user` en los attributes del request.
     * Si no está presente (p.ej. en jobs diferidos), devuelve id=0 y nombre 'SISTEMA'.
     *
     * @return array{id_usuario: int, usuario_nombre: string}
     */
    public static function resolverDesdeRequest(?Request $request): array
    {
        $authUser = $request?->attributes->get('auth_user');

        if (! $authUser) {
            return ['id_usuario' => 0, 'usuario_nombre' => 'SISTEMA'];
        }

        $nombre = trim(sprintf(
            '%s %s',
            (string) ($authUser->nombre ?? ''),
            (string) ($authUser->apellido ?? '')
        ));

        return [
            'id_usuario' => (int) ($authUser->id_usuario ?? 0),
            'usuario_nombre' => $nombre !== '' ? $nombre : (string) ($authUser->username ?? 'USUARIO'),
        ];
    }

    /**
     * Variante para cuando ya se tiene el objeto Usuario suelto (p.ej. en jobs).
     *
     * @return array{id_usuario: int, usuario_nombre: string}
     */
    public static function resolverDesdeObjeto(?object $usuario): array
    {
        if (! $usuario) {
            return ['id_usuario' => 0, 'usuario_nombre' => 'SISTEMA'];
        }

        $nombre = trim(sprintf(
            '%s %s',
            (string) ($usuario->nombre ?? ''),
            (string) ($usuario->apellido ?? '')
        ));

        return [
            'id_usuario' => (int) ($usuario->id_usuario ?? 0),
            'usuario_nombre' => $nombre !== '' ? $nombre : (string) ($usuario->username ?? 'USUARIO'),
        ];
    }
}
