# Fabero — API

API del ERP para una planta de beneficio de mineral de oro y plata. Digitaliza el ciclo completo: compra de mineral a proveedores, recepción, análisis de leyes, blending, valorización, contabilidad y despacho a plantas de destino.

## Módulos

- **Configuración y accesos**: Empresas, Sucursales, Bancos, Marcas, Organigrama, Empleados, Roles, Cuentas, Perfil, Login, Modo Auditoría
- **Maestros de mineral**: Proveedores, Concesiones mineras, Condiciones Comerciales Proveedor, Cuentas Bancarias (Proveedor / Empresa / Planta Destino), Empresas Transporte, Vehículos, Conductores, Encargados de Muestra, Zonas de Origen, Motivos de Ingreso, Plantas Destino
- **Recepción**: Recepción Visitas, Guías Primer Tramo, Recepción Mineral, Recepción Unidades (TicketBalanza)
- **Análisis y procesamiento**: Gestión de Leyes, Cierre de Leyes, Blending
- **Compra y contabilidad**: Anticipos Proveedor, Valorización Compra, Contabilidad Compra (Comprobantes + Pagos)
- **Catálogos globales**: Tipo de Cambio, Ubigeo (departamentos / provincias / distritos)

## Stack

- PHP 8.2 / Laravel 12
- JWT: `php-open-source-saver/jwt-auth`
- WebSockets: Laravel Reverb (eventos en tiempo real)
- Dev local: Laravel Octane + FrankenPHP
- DB: MySQL con SQL crudo (`DB::select`, `DB::insertGetId`) + Eloquent con métodos estáticos
- Análisis estático: PHPStan (Larastan)

## Arquitectura: Hybrid Modular

No usa la estructura estándar de Laravel. Cada proceso de negocio es un módulo en `app/Modules/<Dominio>/`. Las entidades compartidas viven en una capa global en `app/Controllers/`, `app/Endpoints/`, `app/Data/`, `app/Services/`.

### Capas

| Capa | Ruta | Qué hace |
|---|---|---|
| Endpoints | `app/Modules/<D>/XEndpoints.php` o `app/Endpoints/XEndpoints.php` | Define rutas. |
| Controllers | `app/Modules/<D>/Controller/` o `app/Controllers/` | Valida request y orquesta. Sin SQL. |
| Services | `app/Modules/<D>/Service/` o `app/Services/` | Lógica de negocio. Sin SQL directo. |
| Data | `app/Modules/<D>/Data/` o `app/Data/` | Único lugar con SQL. |
| Models | `app/Models/` | Métodos estáticos para insert/update/select. |

### Capa global

Para catálogos recurrentes (empleados, proveedores, marcas, ubigeo, tipos de vehículo, motivos de ingreso, sucursales, tipos de cambio, lotes disponibles, cuentas bancarias filtradas por moneda, etc.) usar `AuxController` + `AuxEndpoints` (`/api/aux/...`). Prohibido duplicar endpoints genéricos dentro de los módulos.

`ArchivoController` / `ArchivoEndpoints` gestionan adjuntos. `MenuNavController` / `MenuNavEndpoints` construyen la navegación por rol. `ConcesionesController` / `ConcesionesEndpoints` también son globales (catálogo de concesiones mineras).

### Enums

En `app/Shared/Enums/`. **Cada proceso físico tiene su Enum dedicado** dentro de su subcarpeta. Ejemplo: `ValorizacionCompra/EstadoValorizacionCompra` y `ContabilidadCompra/EstadoComprobanteCompra` NO se reciclan.

Enums genéricos en `_Generic/`: `TipoMineral`, `TipoProducto`, `Moneda`, `MetodoPago`, `Periodo`, `EstadoBase`, `TipoEntidad`, `TipoCarga`, `TipoComprobante`, `TipoOrigen`, `MotivoTraslado`, `CondicionIngreso`, `ElementoQuimicoValorizacion`, `EstadoAnticipoProveedor`, `EstadoLeyes`, `EstadoPesaje`, `EstadoVisita`.

### Respuestas

SIEMPRE `App\Shared\Responses\ApiResponse::success()` o `::error()`. Helper estático, estructura `{success, data, message, errors?}`.

## Reglas críticas

1. **Services sin acceso a BD**. Toda consulta va por la capa Data. El Service solo orquesta.
2. **`DB::transaction()`** en cualquier flujo que toque múltiples tablas.
3. **Sin reutilización forzada**. Registrar y Actualizar son métodos separados aunque compartan partes. Mejor duplicación clara que un "universal" complejo.
4. **Arrays como parámetro** solo en cabecera+detalle o registros masivos. Si un método recibe `array`, documentar con `@param array $x` describiendo cada clave.
5. **DocBlocks** breves, sin `@param` para primitivos (`int`, `string`, etc.). Solo en arrays y objetos.
6. **Trazabilidad obligatoria (`RES_CambiosLog`)**: todo `log_cambios` debe generarse con `App\Shared\Responses\_Generic\RES_CambiosLog::crear($idEmpleado, $motivo, $cambios)`. Estructura: `{id_empleado, motivo, update_at, cambios[{campo_bd, campo, valor_anterior, valor_nuevo}]}`.
7. **Archivos (`ArchivoHelper`)**: todo guardado de adjuntos debe canalizarse vía `App\Shared\Helpers\ArchivoHelper::guardarArchivos('carpeta_destino', $archivos)`. Guarda en `storage/app/public/{carpeta}/dd-mm-yy/` y retorna `{url, path_relativo, nombre_original, extension}`.
8. **Sin `migrations` ni `seeders` de Laravel**. Las tablas y datos iniciales se crean con SQL plano ejecutado directamente sobre el motor. La carpeta `database/` no existe en el proyecto.
9. **Sin estado global estático**. La API corre con Laravel Octane (FrankenPHP), que mantiene la app en memoria entre requests. Propiedades estáticas mutables, singletons con `Request` o `Container` inyectado en el constructor, o estado guardado en `register()`/`boot()` de providers **causan fugas de memoria y bugs entre requests**. Los Services deben usar métodos estáticos que reciben todo por parámetro y devuelven respuestas. Nada de `$this->cache = []` a nivel de clase.

## Reglas de Base de Datos

Aplican a **toda tabla nueva o modificada**. Si el código existente las viola, corregirlo.

1. **No se usan Foreign Keys, solo `INDEX`**. Las relaciones se garantizan por aplicación, no por motor. Esto da flexibilidad y velocidad durante el desarrollo. Toda tabla nueva debe declarar sus índices de búsqueda explícitamente:
   ```sql
   CREATE TABLE ejemplo_tabla (
       id INT PRIMARY KEY AUTO_INCREMENT,
       id_referencia INT NOT NULL,
       nombre VARCHAR(128) NOT NULL,
       INDEX (id_referencia),
       INDEX (nombre)
   );
   ```
2. **No se usan `migrations` ni `seeders` de Laravel**. Las tablas y datos iniciales se crean con SQL plano ejecutado directamente sobre el motor (`CREATE TABLE`, `INSERT INTO ...`). El control del esquema es directo, sin overhead de sincronización.
3. **Auditoría obligatoria de tablas nuevas**:
   - `id INT PRIMARY KEY AUTO_INCREMENT` siempre.
   - `INDEX` por cada columna usada en `WHERE` / `JOIN`.
   - `INT` para IDs y FKs. `VARCHAR` con largo definido para textos. `DECIMAL` para montos y factores numéricos. `DATE` / `DATETIME` para fechas.
   - Si requiere borrado lógico, incluir campo `estado` (char/varchar) en lugar de `DELETE`.

## Flujo de negocio

1. **Setup del proveedor**: `Proveedores` + `Concesiones` (concesiones mineras del proveedor) + `CondicionesComercialesProveedor` + `CuentasBancariasProveedor`.
2. **Anticipo**: `AnticiposProveedor` — adelantos con su `TransaccionAnticipoProveedor`.
3. **Recepción de mineral** (en orden):
   - `RecepcionVisitas` — visita del proveedor a la planta.
   - `GuiasPrimerTramo` — guía del primer tramo del transporte.
   - `RecepcionMineral` — recepción física con su `LoteMineral`.
   - `RecepcionUnidades` — registro de unidades (vehículo) y `TicketBalanza` (peso en báscula).
4. **Análisis de leyes**: `GestionLeyes` (leyes por lote) → `CierreLeyes` (cierre con leyes finales). Modelos relacionados: `AnalisisMineral`, `Analito`, `GrupoAnalisis`, `GrupoAnalisisDetalle`.
5. **Blending**: `Blending` combina `LoteMineral` para obtener una ley objetivo. Modelos: `Blending`, `BlendingDetalle`.
6. **Valorización**: `ValorizacionCompra` calcula el monto a pagar al proveedor según leyes, pesos, deducciones y anticipos (consume el saldo del anticipo). Modelos: `ValorizacionCompra`, `ValorizacionCompraDetalle`.
7. **Contabilidad**: `ContabilidadCompra` registra `ComprobanteCompra` y los `PagoComprobanteCompra` (puede ser total o parcial, multi-moneda, multi-cuenta).
8. **Despacho**: a `PlantasDestino` (plantas receptoras del mineral procesado). Modelos: `PlantaDestino`, `CuentasBancariasPlantaDestino`.

## Comportamiento HTTP

- `200 OK` para GET/PUT/POST exitosos.
- `204 No Content` para `OPTIONS` (preflight CORS). El middleware `HandleCors` corta la request antes de llegar al controller.
- `401 Unauthorized` cuando el JWT es inválido o expiró. Middleware: `auth.jwt.custom`.
- Errores controlados: `ApiResponse::error()` retorna `4xx/5xx` con `{success:false, message, errors?}`.

## Estructura de un módulo

```
app/Modules/Blending/
├── Endpoints/             # algunos módulos lo tienen aquí
│   └── BlendingEndpoints.php
├── Controllers/
├── Services/
└── Data/
```

Módulos más simples (sin subcarpeta `Endpoints/`):
```
app/Modules/Sucursales/
├── SucursalesEndpoints.php
├── Controller/
├── Service/
└── Data/
```

## Ejecución local

NO usar `php artisan serve` (single-threaded, re-boot por request). Usar Octane con **al menos `--workers=N`** igual al número de núcleos para evitar que las requests se encolen.

Setup inicial (una vez por máquina):

```bash
composer install
php artisan key:generate
php artisan storage:link
php artisan octane:install --server=frankenphp
```

Diario (3 terminales):

```bash
# T1 — API
php artisan octane:start --workers=10

# T2 — WebSockets
php artisan reverb:start

# T3 — Frontend
cd ../fabero-front && npm run dev
```

Con hot-reload: `php artisan octane:start --workers=10 --watch` (requiere `npm i -D chokidar`).

### Comandos Octane

| Comando | Uso |
|---|---|
| `php artisan octane:reload` | Aplica cambios sin reiniciar el server |
| `php artisan octane:stop` | Detiene el server |
| `php artisan octane:status` | Estado del server |

## Reglas para IA

1. **Leer este README completo antes de actuar.** Es la fuente de verdad del proyecto. Si el usuario da contexto que contradice esto, avisar antes de cambiar nada.
2. **Verificar versiones en `composer.json`** antes de usar APIs de librerías. Si hay duda sobre comportamiento actual, **buscar en internet** — el entrenamiento del modelo puede estar desactualizado o diferir con docs vigentes.
3. **No commitear ni hacer push** sin que el usuario lo pida explícitamente.
4. **No alterar la base de datos** (tablas, registros, SQL directo). Indicar al usuario qué debe correr o modificar.
5. **No usar `php artisan serve`**. Usar Octane con `--workers=N`.
6. Después de cualquier cambio: `./vendor/bin/phpstan`. Si Octane corre, también `php artisan octane:reload`.
7. **Cuestionar reusos forzados**. Si piden "una función que sirva para X e Y", proponer separar antes de implementar.
8. Si una idea rompe alguna regla de este documento, plantear la alternativa antes de codear.
9. **Aplicar las reglas de este README aunque el código existente las viole.** Si encontrás un Service que consulta la BD directo, un Controller con SQL, una tabla sin `INDEX` en columnas de búsqueda, estado guardado en propiedades estáticas, o cualquier otra violación: corregirlo (con autorización del usuario). No perpetuar malas prácticas por seguir el patrón del archivo de al lado. Si la refactorización es grande, proponer un plan antes de hacerla.
