# Módulo API: Roles y Permisos (Deep Dive)

Administra la matriz de seguridad del sistema, definiendo qué usuarios pueden acceder a qué módulos y realizar qué acciones.

## 🛠 Componentes del Módulo

### 1. Controlador (`RolesController`)

- **`crear_rol`**:
    - **Validación**: Valida el `nombre` y un array de `modulos` (IDs de permisos).
- **`actualizar_permisos_rol`**:
    - **Validación**: Recibe el ID del rol y la nueva lista de IDs de módulos autorizados.

### 2. Servicio de Seguridad (`RolesService`)

- **`get_estructura_permisos`**:
    - Retorna una estructura jerárquica de todos los permisos disponibles en el sistema, permitiendo al frontend renderizar un árbol de selección.
- **`crear_rol` (Transaccional)**:
    - Inserta el rol en el maestro y recorre el array de módulos para generar los vínculos de seguridad.
- **`actualizar_permisos_rol` (Atomicidad)**:
    - Implementa una lógica de **Limpiar y Reasignar**:
        1. Elimina todos los registros previos en la tabla pivot para ese rol.
        2. Inserta los nuevos permisos seleccionados.
    - Esto garantiza que los cambios sean inmediatos y que no queden "residuos" de permisos antiguos.

### 3. Capa de Datos (`RolesData`, `PermisosData`)

- **SQL de Estructura**:
    - `get_ids_modulos_por_rol`: Recupera de forma eficiente solo los IDs de los permisos para precargar los checks en la UI.
- **Persistencia**:
    - Maneja la tabla `rol_modulo` que es el punto de control central para el Middleware de seguridad.

## ⚙️ Reglas de Negocio

- **Efecto en Cascada**: Al quitar un permiso de un rol, todos los usuarios vinculados a ese rol pierden el acceso instantáneamente en su siguiente petición (debido a la validación del token y permisos en el middleware).
- **Roles Protegidos**: Previene la edición de ciertos roles raíz si están configurados como "de sistema".

## 📂 Esquema de Base de Datos Relacionada

- `rol`: Maestro de perfiles de usuario.
- `rol_modulo`: Tabla pivot que define la matriz de accesos.
- `menu_navegacion`: Estructura de módulos y submódulos disponibles.
