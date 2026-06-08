# Módulo de Plantas Destino

Este módulo gestiona la creación, edición y activación/inactivación de las plantas destino, así como la vinculación/asociación de estas con los proveedores mineros registrados en el sistema.

## Estructura del Módulo

- **Endpoints (`PlantasDestinoEndpoints.php`):** Define los endpoints de la API bajo el prefijo `plantas-destino`.
- **Controller (`Controllers/PlantasDestinoController.php`):** Valida las peticiones y responde en formato JSON.
- **Service (`Services/PlantasDestinoService.php`):** Contiene las reglas del negocio de las plantas destino y la asociación con proveedores.
- **Data (`Data/PlantasDestinoData.php`):** Concentra las consultas SQL nativas y operaciones a la base de datos de las plantas y asociaciones.

## Endpoints

Todas las rutas requieren autenticación con token JWT (`auth.jwt.custom`):

- `GET /api/plantas-destino` - Listar todas las plantas destino con contadores de cuentas y proveedores activos.
- `POST /api/plantas-destino` - Registrar una nueva planta de destino.
- `PUT /api/plantas-destino/{id}` - Editar los datos de una planta.
- `GET /api/plantas-destino/{id}` - Obtener el detalle de una planta.
- `PATCH /api/plantas-destino/{id}/estado` - Cambiar el estado (Activo/Inactivo) de una planta de destino.
- `GET /api/plantas-destino/{id_planta}/proveedores` - Listar los proveedores asociados a una planta destino.
- `POST /api/plantas-destino/proveedores` - Asociar un proveedor activo existente a una planta.
- `DELETE /api/plantas-destino/{id_planta}/proveedores/{id_proveedor}` - Eliminar la asociación entre una planta y un proveedor.
