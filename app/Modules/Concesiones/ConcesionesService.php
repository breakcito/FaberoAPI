<?php

namespace App\Modules\Concesiones;

use App\Modules\Concesiones\Data\ConcesionesData;
use App\Modules\Concesiones\Data\ContratosData;
use App\Shared\Responses\ApiResponse;

class ConcesionesService
{
    /**
     * Obtener listado de concesiones asociadas a las empresas del usuario
     */
    public static function get_concesiones(int $id_usuario): array|object
    {
        $concesiones = ConcesionesData::get_concesiones(id_usuario: $id_usuario);

        return ApiResponse::success($concesiones);
    }

    /**
     * Obtener empresas asociadas al usuario para nuevos contratos
     */
    public static function get_empresas(): array|object
    {
        $empresas = ContratosData::get_empresas();

        return ApiResponse::success($empresas);
    }

    /**
     * Crear una nueva concesión
     */
    public static function crear_concesion(
        int $id_departamento,
        int $id_provincia,
        int $id_distrito,
        string $nombre,
        ?string $codigo_reinfo
    ): array|object {
        if (ConcesionesData::existe_nombre($nombre)) {
            return ApiResponse::error('El nombre de la concesión ya existe.');
        }

        $id = ConcesionesData::crear_concesion(
            id_departamento: $id_departamento,
            id_provincia: $id_provincia,
            id_distrito: $id_distrito,
            nombre: $nombre,
            codigo_reinfo: $codigo_reinfo
        );

        return ApiResponse::success(ConcesionesData::get_concesion_by_id($id), 'Concesión creada con éxito');
    }

    /**
     * Obtener historial de contratos de una concesión
     */
    public static function get_contratos(int $id_concesion): array|object
    {
        $contratos = ContratosData::get_contratos($id_concesion);

        return ApiResponse::success($contratos);
    }

    /**
     * Crear contrato con empresa
     */
    public static function crear_contrato(
        int $id_concesion,
        int $id_empresa,
        string $fecha_inicio,
        ?string $fecha_fin
    ): array|object {
        if (ContratosData::verificar_contrato_activo($id_concesion, $id_empresa)) {
            return ApiResponse::error('Esta empresa ya tiene un contrato activo en esta concesión.');
        }

        $id = ContratosData::crear_contrato(
            id_concesion: $id_concesion,
            id_empresa: $id_empresa,
            fecha_inicio: $fecha_inicio,
            fecha_fin: $fecha_fin
        );

        $nuevo = ContratosData::get_contrato_by_id($id);

        return ApiResponse::success($nuevo, 'Contrato registrado correctamente');
    }

    /**
     * Terminar contrato
     */
    public static function terminar_contrato(int $id_contrato): array|object
    {
        ContratosData::terminar_contrato($id_contrato);

        return ApiResponse::success(null, 'Contrato finalizado correctamente');
    }

    public static function editar_concesion(
        int $id,
        int $id_departamento,
        int $id_provincia,
        int $id_distrito,
        string $nombre,
        ?string $codigo_reinfo
    ): array|object {
        ConcesionesData::editar_concesion(
            id: $id,
            id_departamento: $id_departamento,
            id_provincia: $id_provincia,
            id_distrito: $id_distrito,
            nombre: $nombre,
            codigo_reinfo: $codigo_reinfo
        );

        return ApiResponse::success(ConcesionesData::get_concesion_by_id($id), 'Concesión editada con éxito');
    }

    public static function cambiar_estado_concesion(int $id, string $estado): array|object
    {
        ConcesionesData::cambiar_estado_concesion($id, $estado);

        return ApiResponse::success(ConcesionesData::get_concesion_by_id($id), 'Estado de la concesión cambiado con éxito');
    }
}
