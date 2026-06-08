# Módulo API: Cuentas Bancarias de Proveedor (Deep Dive)

Gestiona la información financiera (número de cuenta, CCI, moneda) para realizar transferencias de pago a los proveedores.

## 🛠 Componentes del Módulo

### 1. Controlador (`CuentasBancariasProveedorController`)

- **`get_cuentas_bancarias`**: Retorna el listado de cuentas asociadas a un proveedor.
- **`crear_cuenta_bancaria`**:
    - **Validación**: Valida los campos obligatorios del DTO (proveedor, banco, moneda, número de cuenta y indicador de detracción).
- **`editar_cuenta_bancaria`**:
    - **Validación**: Actualiza los detalles de la cuenta.
- **`cambiar_estado_cuenta_bancaria`**:
    - **Validación**: Permite alternar el estado entre `Activo` e `Inactivo` (EstadoBase) sin requerir eliminación física.

### 2. Servicio de Cuentas Bancarias (`CuentasBancariasProveedorService`)

- **`crear_cuenta_bancaria`**: Valida duplicados de cuenta del proveedor antes del registro.
- **`cambiar_estado_cuenta_bancaria`**: Permite apagar/encender la cuenta bancaria de manera lógica.

### 3. Acceso a Datos (`CuentasBancariasProveedorData`)

- **`get_cuentas_bancarias`**: SQL que consolida las cuentas bancarias cruzando datos de la tabla `cuenta_bancaria_proveedor` y el maestro `banco`.
- **`cambiar_estado_cuenta_bancaria`**: Realiza el update de estado lógico en la base de datos.

## ⚙️ Reglas de Negocio

- **Inactivación Lógica**: Reemplaza por completo la eliminación física.
- **Contador Dinámico**: El frontend y las consultas consolidadas computan únicamente las cuentas en estado `Activo` para las estadísticas comerciales generales.

## 📂 Esquema de Base de Datos Relacionada

- `cuenta_bancaria_proveedor`: Tabla pivote financiera vinculada con el proveedor y el banco.
