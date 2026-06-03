# Módulo API: Concesiones (Deep Dive)

Gestiona los derechos mineros, su ubicación legal y los acuerdos contractuales con las empresas explotadoras.

## 🛠 Componentes del Módulo

### 1. Controlador (`ConcesionesController`)

- **`crear_concesion`**:
    - **Validación**: Valida el código de concesión, el código REINFO y el ubigeo.
- **`crear_contrato`**:
    - **Validación**: Requiere la vinculación entre una concesión y una empresa con fechas de vigencia.

### 2. Servicio de Títulos Mineros (`ConcesionesService`)

- **`crear_concesion`**:
    - **Validación de Identidad**: Asegura que el nombre de la concesión sea único para evitar conflictos administrativos.
    - **Normalización de Datos**: Implementa el uso del Enum `TipoMineral` (Metálico/No Metálico) para categorizar la extracción de forma estandarizada.
- **`crear_contrato`**:
    - **Regla de Superposición**: El servicio valida en tiempo real que una empresa no tenga más de un contrato activo sobre la misma concesión, previniendo errores de duplicidad legal.
- **`terminar_contrato`**:
    - Gestiona el cierre administrativo de los acuerdos, liberando la concesión para nuevos contratos o marcándola como inactiva.

### 3. Capa de Datos (`ConcesionesData`, `ContratosData`)

- **SQL de Trazabilidad**:
    - `get_contratos`: Recupera el historial completo de empresas que han operado en la concesión, permitiendo auditorías de explotación por período.
- **Persistencia**:
    - Maneja la tabla `concesion_empresa_contrato` que define la vigencia de la operación.

## ⚙️ Reglas de Negocio

- **REINFO**: Permite registrar el código de formalización minera (REINFO), dato esencial para la legalidad de los despachos de mineral.
- **Filtrado por Usuario**: El listado de concesiones está restringido a las empresas a las que el usuario tiene acceso, garantizando la privacidad de los datos.

## 📂 Esquema de Base de Datos Relacionada

- `concesion`: Maestro de títulos mineros.
- `concesion_empresa_contrato`: Historial de acuerdos de explotación.
- `empresa`: Entidad que explota la concesión.
