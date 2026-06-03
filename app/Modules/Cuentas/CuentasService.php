<?php

namespace App\Modules\Cuentas;

use App\Modules\Cuentas\Data\CuentasData;
use App\Shared\Responses\ApiResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

use App\Shared\Helpers\ArchivoHelper;
use Illuminate\Http\UploadedFile;

class CuentasService
{
    public static function get_cuentas(): array|object
    {
        return ApiResponse::success(CuentasData::get_cuentas());
    }

    public static function get_empleados_sin_cuenta(): array|object
    {
        return ApiResponse::success(CuentasData::get_empleados_sin_cuenta());
    }

    public static function get_roles_disponibles(): array|object
    {
        return ApiResponse::success(CuentasData::get_roles_disponibles());
    }

    /**
     * Crear una nueva cuenta de usuario
     */
    public static function crear_cuenta(
        int $id_rol,
        int $id_empleado,
        string $username,
        string $password
    ): array|object {
        return DB::transaction(function () use ($id_rol, $id_empleado, $username, $password) {
            $id_usuario = CuentasData::insert_usuario(
                $id_rol,
                $id_empleado,
                $username,
                Hash::make($password)
            );

            $creado = CuentasData::get_usuario_by_id($id_usuario);

            return ApiResponse::success($creado, 'Cuenta creada correctamente.');
        });
    }

    /**
     * Actualizar datos de la cuenta (incluyendo contraseña opcional)
     */
    public static function actualizar_cuenta(
        int $id_usuario,
        int $id_rol,
        string $username,
        ?string $password = null,
        ?string $estado = null
    ): array|object {
        $updateData = [
            'id_rol' => $id_rol,
            'username' => $username
        ];

        if (!empty($password)) {
            $updateData['password'] = Hash::make($password);
        }

        if (!empty($estado)) {
            $updateData['estado'] = $estado;
        }

        CuentasData::update_usuario($id_usuario, $updateData);

        return ApiResponse::success(null, 'Cuenta actualizada correctamente.');
    }

    /**
     * Actualizar la foto del empleado asociado a una cuenta
     */
    public static function actualizar_foto_empleado(int $id_empleado, UploadedFile $archivo): array|object
    {
        // 1. Guardar el archivo en la carpeta de perfiles
        $resultados = ArchivoHelper::guardarArchivos('perfiles', [$archivo]);

        if (empty($resultados)) {
            return ApiResponse::error('Error al procesar el archivo.');
        }

        $pathRelativo = $resultados[0]['path_relativo'];

        // 2. Actualizar en la base de datos (tabla empleado)
        DB::table('empleado')
            ->where('id', $id_empleado)
            ->update(['path_foto' => asset('storage/' . $pathRelativo)]);

        return ApiResponse::success(
            ['url' => asset('storage/' . $pathRelativo)],
            'Foto actualizada correctamente.'
        );
    }
}
