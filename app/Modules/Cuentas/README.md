# Módulo API: Cuentas de Usuario (Deep Dive)

Gestiona las credenciales de acceso al sistema, vinculando la identidad del empleado con su rol de seguridad y su nombre de usuario.

## 🛠 Componentes del Módulo

### 1. Controlador (`CuentasController`)

- **`crear_cuenta`**:
    - **Validación**: Valida que el empleado exista y que el nombre de usuario no esté en uso.
- **`actualizar_cuenta`**:
    - **Validación**: Permite cambios parciales en el perfil, incluyendo la gestión del estado (Activo/Inactivo).

### 2. Servicio de Autenticación y Perfiles (`CuentasService`)

- **`crear_cuenta` (Transaccional)**:
    - **Seguridad de Contraseñas**: Utiliza el helper `Hash::make()` para encriptar las contraseñas antes de persistirlas, siguiendo los estándares modernos de seguridad.
    - **Vinculación Directa**: Fuerza la relación 1:1 entre un Usuario y un Empleado, asegurando que no existan cuentas huérfanas en el sistema.
- **`actualizar_cuenta`**:
    - Implementa una lógica de actualización flexible. Solo re-encripta la contraseña si esta es provista en la petición, permitiendo ediciones rápidas de roles o nombres de usuario sin afectar la clave actual.
- **`get_empleados_sin_cuenta`**:
    - Provee inteligencia al frontend filtrando solo aquellos trabajadores que aún no tienen acceso al sistema, facilitando la creación de nuevos usuarios.

### 3. Capa de Datos (`CuentasData`)

- **Queries de Gestión**:
    - `get_cuentas`: Consulta que une el usuario con el nombre del empleado y el nombre del rol asignado.
- **Persistencia**:
    - Maneja la tabla `usuario`, núcleo de la seguridad del sistema.

## ⚙️ Reglas de Negocio

- **Unicidad de Usuario**: No se permiten dos cuentas con el mismo `username`.
- **Estado de Cuenta**: El sistema permite desactivar cuentas (cambio a estado "Inactivo") sin eliminar el historial de acciones del usuario en otros módulos, manteniendo la integridad referencial.

## 📂 Esquema de Base de Datos Relacionada

- `usuario`: Tabla de credenciales y roles.
- `empleado`: Perfil del trabajador vinculado.
- `rol`: Definición de permisos asociados.
