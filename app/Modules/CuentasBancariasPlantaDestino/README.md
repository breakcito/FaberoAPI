# Módulo de Cuentas Bancarias de Plantas Destino

Este módulo gestiona la persistencia y control de las cuentas bancarias vinculadas a las plantas de destino.

## Estructura del Módulo

- **Endpoints (`CuentasBancariasPlantaDestinoEndpoints.php`):** Define los endpoints de la API con el prefijo `plantas-destino/cuentas-bancarias`.
- **Controller (`Controllers/CuentasBancariasPlantaDestinoController.php`):** Valida las peticiones y responde en formato JSON.
- **Service (`Services/CuentasBancariasPlantaDestinoService.php`):** Implementa las validaciones de negocio (evitar duplicados de número de cuenta y banco por planta).
- **Data (`Data/CuentasBancariasPlantaDestinoData.php`):** Contiene las consultas SQL nativas y operaciones de base de datos.

## Endpoints

Todas las rutas requieren autenticación con token JWT (`auth.jwt.custom`):

- `GET /api/plantas-destino/cuentas-bancarias/{id_planta}` - Obtener cuentas bancarias de una planta.
- `POST /api/plantas-destino/cuentas-bancarias` - Registrar nueva cuenta bancaria para una planta.
- `PUT /api/plantas-destino/cuentas-bancarias/{id}` - Editar los datos de una cuenta bancaria.
- `PATCH /api/plantas-destino/cuentas-bancarias/{id}/estado` - Cambiar estado (Activo/Inactivo) de una cuenta.
