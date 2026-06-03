# Módulo API: Empleados (Corporativos)

Gestiona el capital humano administrativo y operativo de planilla de las empresas asociadas al sistema.

> [!NOTE]
> Este módulo fue refactorizado para separar al personal corporativo de los contratistas mineros. La lógica de minas y labores operativas ahora reside en el módulo de `Contratistas`.

## 🛠 Componentes del Módulo

### 1. Controlador (`EmpleadosController`)

- **`crear_empleado`**: Registra personal vinculado a una **Empresa** específica.
- **`get_empresas`**: Lista las empresas disponibles para vinculación.
- **`get_areas` / `get_minas`**: Proveen catálogos para filtros y formularios.

### 2. Servicio de Personal (`EmpleadosService`)

- **Gestión de Empresas**: Todo empleado debe estar asociado a una empresa (`id_empresa`).
- **Validación Documental**: Verifica duplicidad de DNI.
- **Gestión Multimedia**: Procesa fotos de perfil mediante `ArchivoHelper`.

### 3. Capa de Datos (`EmpleadosData`)

- **Consultas Optimizadas**:
    - `get_empleados`: Utiliza `LEFT JOIN` con empresas para asegurar que empleados antiguos (con id_empresa NULL) sigan siendo visibles.
    - `get_empresas`: Consulta el maestro de empresas para vinculación institucional.

## ⚙️ Reglas de Negocio

- **Vinculación Institucional**: A diferencia de los contratistas, los empleados se asocian a empresas, no directamente a minas para su contratación (aunque pueden filtrarse por mina para fines operativos).
- **Jerarquía Funcional**: Mantiene la estructura de Área y Cargo obligatoria.

## 📂 Esquema de Base de Datos Relacionada

- `empleado`: Maestro de personal.
- `empresa`: Entidad de vinculación (Planilla).
- `cargo` / `area`: Estructura funcional.
- `usuario`: Los empleados pueden tener una cuenta de acceso al sistema.
