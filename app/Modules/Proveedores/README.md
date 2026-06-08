# Módulo API: Proveedores (Deep Dive)

Gestiona la base de datos de socios comerciales, sus credenciales fiscales (DNI, RUC) y sus asociaciones con concesiones mineras.

## 🛠 Componentes del Módulo

### 1. Controlador (`ProveedoresController`)

- **`crear_proveedor`**:
    - **Validación**: Valida el tipo de entidad (Natural/Jurídica) y el documento correspondiente (DNI/RUC). Recibe un array opcional de `cuentas` bancarias que delega al servicio.
- **`get_proveedores`**: Lista los proveedores con sus datos de contacto base y contador de cuentas bancarias activas.

### 2. Servicio de Gestión Comercial (`ProveedoresService`)

- **`crear_proveedor` (Transaccional)**:
    - **Integridad Fiscal**: Registra los datos del proveedor en el maestro.
    - **Gestión Financiera**: Itera sobre las cuentas iniciales y las registra llamando a `CuentasBancariasProveedorData` en el mismo hilo transaccional.
    - **Atomicidad**: Si falla el registro de una sola cuenta bancaria, se revierte la creación del proveedor, evitando registros inconsistentes.

### 3. Capa de Datos (`ProveedoresData`)

- **SQL de Consolidación**:
    - `get_proveedores`: Consulta optimizada que incluye una subconsulta para calcular dinámicamente la cantidad de cuentas bancarias activas asociadas.
- **Persistencia de Concesiones**:
    - Métodos para asociar y desasociar concesiones a través de la tabla intermedia pivot `concesion_proveedor`.

## ⚙️ Reglas de Negocio

- **Relación con Cotizaciones**: Los proveedores registrados en este módulo son los únicos habilitados para participar en el proceso de comparación de precios e ingresos en el sistema.
- **Inactivación Lógica**: El módulo oculta e inhabilita las opciones de eliminación física de proveedores, promoviendo el uso del toggle de estado lógico.

## 📂 Esquema de Base de Datos Relacionada

- `proveedor`: Maestro de empresas y personas naturales.
- `concesion_proveedor`: Tabla pivot de relaciones de exploración minera.
