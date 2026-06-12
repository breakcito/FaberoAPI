<?php

namespace App\Modules\EmpresasTransporte\Services;

use App\Modules\EmpresasTransporte\Data\EmpresasTransporteData;
use App\Shared\Responses\ApiResponse;

class EmpresasTransporteService
{
    public static function get_empresas_transporte(): array
    {
        $data = EmpresasTransporteData::get_empresas_transporte();

        return ApiResponse::success($data, 'Empresas de transporte obtenidas correctamente');
    }

    public static function crear_empresa_transporte(
        string $tipoEntidad,
        ?string $dni,
        string $ruc,
        string $razonSocial,
        ?string $direccion,
        ?string $telefono,
        ?string $correo
    ): array {
        $id = EmpresasTransporteData::crear_empresa_transporte($tipoEntidad, $dni, $ruc, $razonSocial, $direccion, $telefono, $correo);
        $nuevaEmpresa = EmpresasTransporteData::get_empresa_transporte_by_id($id);

        return ApiResponse::success($nuevaEmpresa, 'Empresa de transporte creada correctamente');
    }

    public static function editar_empresa_transporte(
        int $id,
        string $tipoEntidad,
        ?string $dni,
        string $ruc,
        string $razonSocial,
        ?string $direccion,
        ?string $telefono,
        ?string $correo
    ): array {
        EmpresasTransporteData::editar_empresa_transporte($id, $tipoEntidad, $dni, $ruc, $razonSocial, $direccion, $telefono, $correo);
        $updated = EmpresasTransporteData::get_empresa_transporte_by_id($id);

        return ApiResponse::success($updated, 'Empresa de transporte editada correctamente');
    }

    public static function cambiar_estado_empresa_transporte(int $id, string $estado): array
    {
        EmpresasTransporteData::cambiar_estado_empresa_transporte($id, $estado);
        $updated = EmpresasTransporteData::get_empresa_transporte_by_id($id);

        return ApiResponse::success($updated['id'], 'Estado de la empresa de transporte cambiado correctamente');
    }
}
