# Módulo API: Bancos (Deep Dive)

Gestiona la lista de bancos autorizados en el sistema para vincular las cuentas bancarias de los proveedores.

## 🛠 Componentes del Módulo

### 1. Controlador (`BancosController`)

- **`get_bancos`**: Retorna el listado completo de bancos en el sistema.
- **`crear_banco`**:
    - **Validación**: Requiere `nombre` (string, max 100) y `abreviatura` (string, max 20).

### 2. Servicio de Bancos (`BancosService`)

- **`get_bancos`**: Encapsula la obtención de registros a través de `BancosData`.
- **`crear_banco`**:
    - **Validación de Identidad**: Valida que no exista previamente un banco con el mismo nombre o abreviatura.

### 3. Acceso a Datos (`BancosData`)

- **`get_bancos`**: Ejecuta la consulta SQL pura sobre la tabla `banco` para listar bancos ordenados alfabéticamente.
- **`crear_banco`**: Inserta el registro inicial del banco en estado `Activo`.

## ⚙️ Reglas de Negocio

- **Nombre y Abreviatura Únicos**: Previene duplicados administrativos en la selección del banco.

## 📂 Esquema de Base de Datos Relacionada

- `banco`: Tabla catálogo de bancos en el sistema.
