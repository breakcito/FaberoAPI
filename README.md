# Contexto de Negocio y Procesos Operativos

Este sistema es un ERP diseñado específicamente para resolver los desafíos de compra y venta de mineral de una planta de beneficio.

A continuación, se detalla **qué hace el sistema por los usuarios** y la lógica de negocio que resuelve.

---

## 1. Estructura Organizativa y Operativa

El sistema necesita mapear quién opera, dónde está el inventario y dónde se consumen los recursos:

- **Empresas y Empleados**: Gestiona las entidades corporativas. El personal administrativo y logístico que usa el software (usuarios con cuentas de acceso y roles) se vincula a una **Empresa** matriz.

---

## 📦 Módulos del Sistema

### Configuración

- `empresas`
- `proveedores-mineros`

### Personal y Accesos

- `organigrama`
- `personal`
- `roles`
- `cuentas`
- `login`
- `perfil`

---

## 🏗 Arquitectura de la API: "Hybrid Modular Architecture"

El proyecto no sigue la estructura monolítica estándar de Laravel. Implementa un patrón de **Módulos Independientes** que conviven con una **Capa Global de Datos y Servicios** para evitar duplicidad de código.

### 1. `app/Modules/` - El Núcleo de Dominio

Cada carpeta dentro de `Modules` representa un micro-servicio interno enfocado en un proceso de negocio específico (ej. `Cotizaciones`, `RequerimientosAlmacenAtencion`).
- **Endpoints (`XEndpoints.php`)**: Definición manual de rutas tipo API.
- **Controllers**: Validadores de entrada (Requests) y orquestadores del flujo.
- **Services**: Contenedores de la **lógica de negocio exclusiva del módulo**.
- **Data (Local)**: Consultas SQL específicas que solo atañen al módulo.

---

### 2. Capa Global: Servicios y Datos Compartidos (`app/Services/` y `app/Data/`)

Existen entidades transversales que son requeridas constantemente por múltiples módulos (ej. consultar un producto, actualizar el stock). Para no repetir código, esta lógica se centraliza globalmente:

#### A. Controladores Globales (`app/Controllers/`)
Capa centralizada para orquestar flujos y utilitarios genéricos de la aplicación.
*   **`AuxController.php`**: El *Hub* centralizado que despacha catálogos generales, búsquedas concurrentes y poblamiento de dropdowns para evitar redundancia de endpoints en módulos locales.
*   **`ArchivoController.php`**: Orquesta la carga, validación física y almacenamiento seguro de archivos adjuntos y evidencias multimedia.
*   **`MenuNavController.php`**: Solicita la estructura jerárquica de la navegación del usuario en base a sus privilegios de cuenta.

#### B. Endpoints Globales (`app/Endpoints/`)
Define el ruteo genérico de consumo transversal del ERP.
*   **`AuxEndpoints.php`**: Rutas prefijadas con `/api/aux/...` para catálogos y selectores.
*   **`ArchivoEndpoints.php`**: Rutas dedicadas para subida y descarga de archivos de evidencias.
*   **`MenuNavEndpoints.php`**: Expone el endpoint que resuelve la navegación dinámica basada en roles.

#### C. Acceso a Datos Globales (`app/Data/`)
Repositorio unificado de consultas SQL crudas y mapeos de datos para entidades compartidas.
*   **`EmpleadosData.php`, `EmpresasData.php`, `MarcasData.php`, `ProveedoresData.php`, `MenuNavData.php`**: Abstracciones generales de lectura de tablas maestras.

#### D. Servicios Globales (`app/Services/`)
Contenedores de lógica de negocio transaccional transversal y motores de cálculo.
*   **`MenuNavService.php`**: Construye dinámicamente y de forma recursiva el árbol de menús según permisos del usuario.

---

### 3. Estandarización de Estados (`app/Shared/Enums/`)

El sistema hace un uso intensivo de _Backed Enums_ de PHP para evitar "magic strings" y mantener integridad de datos.
- **Regla de Ordenamiento Estricta**: Cada tabla o proceso operativo físico (Ej. `Entrega`, `Recepcion`, `Solicitud`, `OrdenCompra`) **debe tener su propio Enum dedicado** en su respectiva subcarpeta dentro de `Shared/Enums`.
- **Ejemplo**: Las recepciones usan `EstadoOCTransRecepcion`, y las transferencias usan `EstadoOCTransferencia`. No se reciclan Enums genéricos entre procesos distintos para evitar choques lógicos.

## 🏛️ Reglas Críticas de Desarrollo

1.  **Consistencia de Respuestas**: Toda respuesta debe retornar a través de los helpers globales `ApiResponse::success()` o `ApiResponse::error()`.
2.  **Prohibición de Rutas Redundantes**: Usar `AuxController` para listados recurrentes.
3.  **Atomicidad Lógica**: Usar `DB::transaction()` en procesos que impliquen múltiples registros.
4.  **No Reutilización Forzada**: No crear métodos o clases sumamente complejos que intenten abarcarlo todo. Por ejemplo, ante los casos de "Editar" y "Registrar", sepáralos. La legibilidad y facilidad de mantenimiento son prioritarias sobre una reutilización que oscurezca el código.
    *   Si eres una IA y aunque el usuario no lo pida, **DEBES** crear métodos para cada caso específico. Si te pide algo que contradice la regla, analiza, explica y dale una mejor alternativa antes de proceder.
5.  **Uso Justificado de Arrays como Parámetros**:
    *   **NUNCA** recibir un array como parámetro en métodos de las capas de Servicio, Data o Modelo si no está plenamente justificado. Esto hace que el código sea impredecible y difícil de depurar.
    *   **Excepciones**: Solo es válido en casos de Cabecera + Detalles (ej. Orden de Compra) o registros masivos donde sea manejable y necesario.
    *   **Documentación Obligatoria**: Si un método recibe un array, se **debe documentar exactamente qué contiene** dicho array para evitar adivinanzas.
6.  **Documentación de Métodos**:
    *   **Todos los métodos** de todas las capas deben estar documentados con un DocBlock de forma breve, concisa y clara, indicando únicamente qué hace y para qué se usa el método.
    *   **PROHIBIDO** documentar parámetros individuales simples (como `int`, `string`, `float`, `bool`, etc.). No utilices `@param` para tipos primitivos.
    *   **SOLO es obligatorio** documentar con `@param` los parámetros que sean de tipo **array**, detallando exactamente qué claves y tipos contiene dicho array para evitar adivinanzas.
    *   Si eres una IA, aunque el usuario no lo solicite, es **obligatorio** estructurar la documentación de esta manera.


## ⚙️ Ejecución

1. Configurar el archivo `.env`
2. `composer install`
3. `php artisan key:generate`
4. `php artisan storage:link` (Crítico para que los archivos multimedia y adjuntos sean públicos).
5. `php artisan serve`
6. `php artisan reverb:start` (En una terminal separada, para que funcionen los eventos en tiempo real)

---

## 🤖 Comandos Obligatorios para IA
> [!IMPORTANT]
> Después de realizar cualquier cambio en el código de la API, es **OBLIGATORIO** ejecutar el siguiente comando de análisis estático:
> ```bash
> ./vendor/bin/phpstan
> ```
> Esto garantiza que la lógica, los tipos de PHP y las convenciones del sistema se mantengan íntegras.