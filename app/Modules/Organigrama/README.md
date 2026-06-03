# Módulo API: Organigrama (Deep Dive)

Gestiona la estructura jerárquica de la organización, definiendo las áreas funcionales y los cargos disponibles para el personal.

## 🛠 Componentes del Módulo

### 1. Controlador (`OrganigramaController`)

- **`crear_area`**:
    - **Validación**: Valida que el nombre del área no sea nulo y sea único.
- **`crear_cargo`**:
    - **Validación**: Requiere la vinculación obligatoria a un `id_area` existente.

### 2. Servicio de Estructura Organizativa (`OrganigramaService`)

- **Gestión de Áreas**:
    - **Validación de Unicidad**: Asegura que no existan áreas duplicadas en la raíz de la organización.
- **Gestión de Cargos**:
    - **Contextualización**: Valida que el nombre del cargo sea único **dentro de su misma área**, permitiendo que existan cargos similares en áreas distintas si fuera necesario.
- **Ciclo de Vida de Cargos**:
    - `cambiar_estado_cargo`: Implementa un "Toggle" de estado (Activo/Inactivo), permitiendo inhabilitar cargos obsoletos sin borrarlos de la base de datos, preservando la integridad de los perfiles de empleados históricos.

### 3. Capa de Datos (`AreasData`, `CargosData`)

- **SQL de Integridad**:
    - `verificar_nombre_duplicado`: Consultas rápidas para asegurar la limpieza del catálogo.
- **Persistencia**:
    - Maneja las tablas maestras `area` y `cargo`.

## ⚙️ Reglas de Negocio

- **Jerarquía Rígida**: Todo cargo debe pertenecer a un área. No existen cargos independientes.
- **Preservación Histórica**: La eliminación física de áreas o cargos está restringida si existen empleados vinculados, favoreciendo el uso del cambio de estado.

## 📂 Esquema de Base de Datos Relacionada

- `area`: Departamentos de la empresa (Ej: Logística, Mina, RRHH).
- `cargo`: Puestos de trabajo dentro de las áreas.
- `empleado`: Consumidor final de esta estructura.
