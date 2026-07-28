# Módulo API: Cuentas Bancarias de Empresa (Deep Dive)

Gestiona la información financiera (número de cuenta, CCI, moneda) asociada a las empresas registradas en el ERP.

## 🛠 Componentes del Módulo

### 1. Controlador (`CuentasBancariasEmpresaController`)

- **`get_cuentas_bancarias`**: Retorna el listado de cuentas asociadas a una empresa.
- **`crear_cuenta_bancaria`**:
    - **Validación**: Valida los campos obligatorios del DTO (empresa, banco, moneda, número de cuenta e indicador de detracción).
- **`editar_cuenta_bancaria`**:
    - **Validación**: Actualiza los detalles de la cuenta.
- **`cambiar_estado_cuenta_bancaria`**:
    - **Validación**: Permite alternar el estado entre `Activo` e `Inactivo` (EstadoBase) sin requerir eliminación física.
- **`eliminar_cuenta_bancaria`**: Eliminación física explícita de la cuenta.

### 2. Servicio de Cuentas Bancarias (`CuentasBancariasEmpresaService`)

- **`crear_cuenta_bancaria`**: Valida duplicados de cuenta de la empresa antes del registro.
- **`cambiar_estado_cuenta_bancaria`**: Permite apagar/encender la cuenta bancaria de manera lógica.

### 3. Acceso a Datos (`CuentasBancariasEmpresaData`)

- **`get_cuentas_bancarias`**: SQL que consolida las cuentas bancarias cruzando datos de la tabla `cuenta_bancaria_empresa` y el maestro `banco`.
- **`cambiar_estado_cuenta_bancaria`**: Realiza el update de estado lógico en la base de datos.

## ⚙️ Reglas de Negocio

- **Inactivación Lógica**: Reemplaza por completo la eliminación física en flujos operativos.
- **Contador Dinámico**: El frontend y las consultas consolidadas computan únicamente las cuentas en estado `Activo` para las estadísticas generales.

## 📂 Esquema de Base de Datos Relacionada

- `cuenta_bancaria_empresa`: Tabla pivote financiera vinculada con la empresa y el banco.
- `empresa`: Maestro de entidades corporativas.
- `banco`: Maestro de entidades bancarias.
