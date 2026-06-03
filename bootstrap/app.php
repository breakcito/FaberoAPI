<?php

use App\Middlewares\JwtAuthMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        channels: __DIR__ . '/../routes/channels.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware('api')->prefix('api')->group(function () {
                require base_path('app/Modules/Login/LoginEndpoints.php');
                require base_path('app/Endpoints/MenuNavEndpoints.php');
                require base_path('app/Endpoints/ArchivoEndpoints.php');
                require base_path('app/Endpoints/AuxEndpoints.php');
                require base_path('app/Modules/Almacenes/AlmacenesEndpoints.php');
                require base_path('app/Modules/Categorias/CategoriasEndpoints.php');
                require base_path('app/Modules/Empresas/EmpresasEndpoints.php');
                require base_path('app/Modules/Organigrama/OrganigramaEndpoints.php');
                require base_path('app/Modules/Concesiones/ConcesionesEndpoints.php');
                require base_path('app/Modules/Empleados/EmpleadosEndpoints.php');
                require base_path('app/Modules/Contratistas/ContratistasEndpoints.php');
                require base_path('app/Modules/Productos/ProductosEndpoints.php');
                require base_path('app/Modules/MinasLabores/MinasLaboresEndpoints.php');
                require base_path('app/Modules/LotesProductos/LotesEndpoints.php');
                require base_path('app/Modules/ActivosFijos/ActivosFijosEndpoints.php');
                require base_path('app/Modules/ControlUso/ControlUsoEndpoints.php');
                require base_path('app/Modules/ControlConsumoActivos/ControlConsumoActivosEndpoints.php');
                // require base_path('app/Modules/RequerimientosAlmacen/RequerimientosEndpoints.php');
                require base_path('app/Modules/RequerimientosAlmacenAtencion/RequerimientosAtencionEndpoints.php');
                require base_path('app/Modules/KardexProductos/KardexEndpoints.php');
                require base_path('app/Modules/Roles/RolesEndpoints.php');
                require base_path('app/Modules/Cuentas/CuentasEndpoints.php');
                require base_path('app/Modules/Perfil/PerfilEndpoints.php');
                require base_path('app/Modules/SolicitudesReabastecimiento/SolicitudesEndpoints.php');
                require base_path('app/Modules/SolicitudesReabastecimientoAtencion/SolicitudesAtencionEndpoints.php');
                require base_path('app/Modules/PrestamosAlmacenAtencion/PrestamosAtencionEndpoints.php');
                require base_path('app/Modules/PrestamosAlmacen/PrestamosAlmacenEndpoints.php');
                require base_path('app/Modules/Proveedores/ProveedoresEndpoints.php');
                require base_path('app/Modules/Cotizaciones/CotizacionesEndpoints.php');
                require base_path('app/Modules/OrdenesCompra/OrdenCompraEndpoints.php');
                require base_path('app/Modules/OrdenesCompraRecepcionTransferencias/OCRecepcionTransferenciasEndpoints.php');
                require base_path('app/Modules/ModoAuditoria/ModoAuditoriaEndpoints.php');
            });
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'auth.jwt.custom' => JwtAuthMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
