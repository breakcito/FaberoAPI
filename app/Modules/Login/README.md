# Módulo API: Autenticación (Deep Dive)

Es la puerta de entrada al sistema, encargada de la validación de credenciales y la emisión de tokens de acceso seguros.

## 🛠 Componentes del Módulo

### 1. Controlador (`LoginController`)

- **`login`**:
    - Recibe `username` y `password`. Implementa protección básica contra ataques de fuerza bruta (vía middleware de Laravel).

### 2. Servicio de Autenticación (`LoginService`)

- **`login`**:
    - **Validación de Identidad**: Localiza al usuario por su nombre único.
    - **Seguridad Criptográfica**: Utiliza `Hash::check()` para validar la contraseña contra el hash almacenado en la BD (Bcrypt).
    - **Verificación de Estado Dual**:
        1. Valida que la **Cuenta de Usuario** esté activa.
        2. Valida que el **Empleado** vinculado esté activo. Esto previene que empleados cesados entren al sistema incluso si su cuenta no ha sido desactivada manualmente.
    - **Emisión de Token (JWT)**:
        - Utiliza la librería `PHPOpenSourceSaver\JWTAuth`.
        - **Payload Enriquecido**: Inyecta en el token (Claims) datos críticos: `id_usuario`, `id_rol` e `id_empleado`. Esto permite que los middlewares de otros módulos conozcan la identidad del usuario sin consultar la base de datos en cada petición.
    - **Respuesta Consolidada**: Retorna el token y un objeto de información básica para el frontend (nombre, rol, foto).

### 3. Capa de Datos (`LoginData`)

- **Queries de Autenticación**:
    - `get_usuario_by_username`: Consulta optimizada al maestro de usuarios.
    - `getInfoUsuarioById`: Trae el perfil consolidado (Empleado + Rol + Mina) para la respuesta inicial.

## ⚙️ Reglas de Negocio

- **Sesión Única (Opcional)**: El sistema está configurado para manejar tokens con tiempo de vida (TTL) definido, garantizando la seguridad en dispositivos compartidos.
- **Transparencia de Errores**: Provee mensajes descriptivos pero seguros (diferenciando si el error es de usuario o contraseña) para mejorar la experiencia de soporte técnico.

## 📂 Esquema de Base de Datos Relacionada

- `usuario`: Almacén de credenciales.
- `empleado`: Validación de estado laboral.
- `rol`: Vinculación para permisos iniciales.
