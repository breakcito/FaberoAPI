# Módulo API: Proveedores (Deep Dive)

Gestiona la base de datos de socios comerciales, sus credenciales fiscales y su información financiera para pagos.

## 🛠 Componentes del Módulo

### 1. Controlador (`ProveedoresController`)

- **`crear_proveedor`**:
    - **Validación**: Valida el tipo de entidad (Natural/Jurídica) y el documento correspondiente (DNI/RUC). Recibe un array de `cuentas` bancarias.
- **`get_proveedores`**: Lista los proveedores con sus datos de contacto base.

### 2. Servicio de Gestión Comercial (`ProveedoresService`)

- **`crear_proveedor` (Transaccional)**:
    - **Integridad Fiscal**: Registra los datos del proveedor en el maestro.
    - **Gestión Financiera**: Itera sobre el array de cuentas bancarias para vincularlas al proveedor recién creado.
    - **Especialización de Cuentas**: El servicio permite marcar cuentas específicas como **"Para Detracción"**, dato crítico para el cumplimiento tributario en el módulo de Tesorería.
    - **Atomicidad**: Si falla el registro de una sola cuenta bancaria, se revierte la creación del proveedor, evitando registros incompletos.

### 3. Capa de Datos (`ProveedoresData`, `CuentasBancariasData`)

- **SQL de Consolidación**:
    - `get_proveedores`: Consulta optimizada para listados rápidos.
- **Persistencia Bancaria**:
    - `crear_cuenta_bancaria`: Inserta en la tabla `proveedor_cuenta_bancaria`, vinculando con el maestro de bancos.

## ⚙️ Reglas de Negocio

- **Validación de Moneda**: Soporta cuentas en diversas monedas (PEN, USD) para la gestión de pagos nacionales e internacionales.
- **Relación con Cotizaciones**: Los proveedores registrados en este módulo son los únicos habilitados para participar en el proceso de comparación de precios y generación de Órdenes de Compra.

## 📂 Esquema de Base de Datos Relacionada

- `proveedor`: Maestro de empresas y personas naturales.
- `proveedor_cuenta_bancaria`: Información financiera para transferencias.
- `banco`: Catálogo de instituciones financieras.
