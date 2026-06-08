# Módulo API: Concesiones (Deep Dive)

Gestiona los derechos mineros, su ubicación legal (departamento, provincia, distrito) y los acuerdos contractuales con las empresas explotadoras.

## 🛠 Componentes del Módulo

### 1. Controlador (`ConcesionesController`)

- **`crear_concesion`**:
    - **Validación**: Valida los campos obligatorios del ubigeo y el nombre de la concesión.
- **`editar_concesion`**:
    - Actualiza los datos de la concesión.
- **`cambiar_estado_concesion`**:
    - **Validación**: Recibe el nuevo estado (`Activo` o `Inactivo`) y actualiza lógicamente la concesión en el sistema.
- **`crear_contrato`**:
    - **Validación**: Requiere la vinculación entre una concesión y una empresa con fechas de vigencia.

### 2. Servicio de Títulos Mineros (`ConcesionesService`)

- **`crear_concesion`**:
    - **Validación de Identidad**: Asegura que el nombre de la concesión sea único para evitar conflictos administrativos.
- **`cambiar_estado_concesion`**:
    - Cambia el estado de una concesión de forma lógica.
- **`crear_contrato`**:
    - **Regla de Superposición**: El servicio valida en tiempo real que una empresa no tenga más de un contrato activo sobre la misma concesión, previniendo errores de duplicidad legal.

### 3. Capa de Datos (`ConcesionesData`, `ContratosData`)

- **SQL de Trazabilidad**:
    - `get_contratos`: Recupera el historial completo de empresas que han operado en la concesión, permitiendo auditorías de explotación por período.
- **Persistencia**:
    - Maneja la tabla `concesion` y la tabla pivot `concesion_empresa_contrato` que define la vigencia de la operación.

## ⚙️ Reglas de Negocio

- **REINFO**: Permite registrar el código de formalización minera (REINFO), dato esencial para la legalidad de los despachos de mineral.
- **Inactivación Lógica**: No se permite la eliminación física de concesiones. Al inactivar una concesión, ésta es excluida del selector de asociaciones disponibles para evitar nuevas vinculaciones comerciales.

## 📂 Esquema de Base de Datos Relacionada

- `concesion`: Maestro de títulos mineros.
- `concesion_empresa_contrato`: Historial de acuerdos de explotación.
- `empresa`: Entidad que explota la concesión.
