# Módulo API: Perfil de Usuario (Deep Dive)

Provee la información extendida del usuario autenticado para la personalización de la interfaz y la validación de contexto del empleado.

## 🛠 Componentes del Módulo

### 1. Controlador (`PerfilController`)

- **`get_perfil`**:
    - Recupera el ID del usuario directamente del token de sesión (vía middleware) para asegurar que un usuario solo pueda consultar su propia información.

### 2. Servicio de Información Personal (`PerfilService`)

- **`get_perfil`**:
    - **Validación de Identidad**: Valida que el `id_usuario` exista y esté activo.
    - **Transformación de Multimedia**: Si el empleado tiene una foto de perfil registrada, el servicio utiliza `asset()` para convertir la ruta física en una URL accesible desde cualquier cliente (Web/Móvil).
    - **Enriquecimiento de Datos**: No solo retorna datos de la cuenta, sino que cruza la información con el maestro de empleados para devolver el nombre completo, cargo, área y la mina a la que pertenece.

### 3. Capa de Datos (`PerfilData`)

- **SQL de Perfil**:
    - `get_info_perfil`: Ejecuta un join complejo entre `usuario`, `empleado`, `cargo`, `area` y `mina`, entregando un objeto de perfil completo en una sola transacción SQL.

## ⚙️ Reglas de Negocio

- **Seguridad de Consulta**: La información de perfil es de "solo lectura" desde este módulo. Cualquier cambio de datos debe realizarse a través del módulo de Empleados o Cuentas por un administrador.
- **Contexto Operativo**: El frontend utiliza la información de este módulo (específicamente `id_mina` e `id_empleado`) para filtrar automáticamente los almacenes y labores permitidos en los formularios de requerimientos.

## 📂 Esquema de Base de Datos Relacionada

- `usuario`: Datos de acceso.
- `empleado`: Datos personales y profesionales.
- `mina`: Contexto geográfico del usuario.
