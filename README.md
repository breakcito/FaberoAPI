# Fabero — API

API del ERP para una planta de beneficio de mineral de oro y plata. Digitaliza el ciclo completo: compra de mineral a proveedores, recepción, análisis de leyes, blending, valorización, contabilidad y despacho a plantas de destino.

## Módulos

- **Configuración y accesos**: Empresas, Sucursales, Bancos, Marcas, Organigrama, Empleados, Roles, Cuentas, Perfil, Login, Modo Auditoría
- **Maestros y logística**: Proveedores, Concesiones mineras, Condiciones Comerciales Proveedor, Cuentas Bancarias (Proveedor / Empresa / Planta Destino), Empresas Transporte, Vehículos, Conductores, Visita Vehículo, Plantas Destino, Condiciones Comerciales Planta
- **Recepción**: Recepción Visitas, Programar Recepción, Guías Primer Tramo, Recepción Mineral, Recepción Unidades (TicketBalanza)
- **Análisis y procesamiento**: Gestión de Leyes, Cierre de Leyes, Blending, Validación Distribución
- **Compra, venta y contabilidad**: Anticipos Proveedor, Anticipos Planta, Programación Despachos, Valorización Compra, Valorización Venta, Valor Elemento Químico, Contabilidad Compra (Comprobantes + Pagos)
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

Enums genéricos en `_Generic/`: `TipoMineral`, `TipoProducto`, `Moneda`, `MetodoPago`, `Periodo`, `EstadoBase`, `TipoEntidad`, `TipoComprobante`, `TipoOrigen`, `MotivoTraslado`, `CondicionIngreso`, `ElementoQuimicoValorizacion`, `EstadoAnticipoProveedor`, `EstadoLeyes`, `EstadoPesaje`, `EstadoVisita`.

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
   - Un `LoteMineral` puede **particionarse desde balanza**: las particiones viven en `particion_lote_mineral` y cada una tiene su propia `recepcion_unidad` (vehículo, conductor, transporte), su `ticket_balanza` y sus pesos. `finalizar_particion_lote` sella el lote padre (`particion_finalizada = 1`) y fija su `peso_neto` con el sumatorio de las particiones.
4. **Análisis de leyes**: `GestionLeyes` (leyes por lote) → `CierreLeyes` (cierre con leyes finales). Modelos relacionados: `AnalisisMineral`, `Analito`, `GrupoAnalisis`, `GrupoAnalisisDetalle`.
   - Una **muestra externa** solo puede asociarse a un grupo de análisis del **mismo proveedor** que la muestra (el grupo hereda el proveedor de su lote). Se valida en `CierreLeyesData::validar_proveedor_muestras_grupo()` antes de escribir nada; el backend responde 422 con el código `PROVEEDOR_MUESTRA_GRUPO_INCONSISTENTE`.
   - **Valor comercial del lote**: `lote_mineral.con_valor_comercial` (`tinyint NULL`) se define desde Cierre de Leyes (badge "Definir Valor Comercial" → `POST /cierre-leyes/lotes/{id}/cerrar`, validado `required|boolean`). Mientras sea `NULL` el lote **no puede valorizarse**: `get_lotes_disponibles_valorizacion()` lo excluye y `ValorizacionCompraService::validarValorComercialDefinido()` rechaza la propuesta con un mensaje que remite a Cierre de Leyes. `con_valor_comercial = 0` (Sin Valor Comercial) sí es valorizable, pero el detalle se calcula con `subtotal = 0` (sin INTER, PTN ni deducciones) y no aporta al total de la propuesta.
5. **Blending**: `Blending` combina `LoteMineral` para obtener una ley objetivo. Modelos: `Blending`, `BlendingDetalle`.
6. **Valorización**: `ValorizacionCompra` calcula el monto a pagar al proveedor según leyes, pesos, deducciones y anticipos (consume el saldo del anticipo). Modelos: `ValorizacionCompra`, `ValorizacionCompraDetalle`.
   - Cada detalle apunta a un **grupo de análisis** (`valorizacion_compramineral_detalle.id_grupo_analisis`). Al crear, editar o copiar una propuesta se marca `grupo_analisis.tiene_propuesta_oro` / `tiene_propuesta_plata`, lo que **bloquea ese grupo en el módulo de Cierre de Leyes**.
   - Al **cambiar el grupo de análisis** de un detalle al editar una propuesta: el grupo anterior se libera (sólo si ninguna otra valorización viva lo usa) y el nuevo queda marcado con propuesta → bloqueado en Cierre de Leyes.
   - **Oro y plata son independientes**: los flags `tiene_propuesta_oro` / `tiene_propuesta_plata` se marcan por elemento, así que un grupo con el oro en propuesta sigue con su **ley de plata disponible** para otra propuesta (o viceversa). La unidad de valoración es el detalle (`id_lote_guia` + `elemento_quimico`): un mismo lote puede aparecer 2 veces en una propuesta, una en Oro y otra en Plata, incluso con grupos distintos.
   - **Disponibilidad por elemento**: `get_lotes_disponibles_valorizacion()` excluye el lote sólo cuando está bloqueado en **ambos** elementos; el frontend ofrece únicamente los elementos libres de cada lote.
   - **Ley del grupo → ley del lote**: al aprobar (`asignarLeyesLotesDesdeGrupos()`), `lote_mineral.ley_oro` / `ley_plata` toman el valor de `grupo_analisis.ley_oro_cierre` / `ley_plata_cierre` **del grupo del detalle valorizado**. La ley del elemento **no** valorizado **no** se copia: si la propuesta sólo valorizó Oro, el lote toma `ley_oro` y su `ley_plata` sigue vacía hasta que se apruebe una propuesta de Plata. `ley_humedad` y `ley_recuperacion` sí se toman del grupo (son independientes del elemento).
   - **Una sola versión aprobada por grupo de versiones**: `existe_version_aprobada_en_grupo()` impide aprobar dos versiones del mismo `uuid_grupo`; al aprobar una, las hermanas en Propuesta pasan a *Desestimada* y liberan sus grupos/lotes.
   - **Auditoría de la edición**: `log_cambios` de la cabecera registra `Total Valorización` (antes/después), `Lote en valorización agregado` y `Lote en valorización removido`; el `log_cambios` del detalle registra los cambios de parámetros (INTER, recuperación, maquila, consumo, factor, PTN, subtotal) y el **cambio de grupo de análisis**. El total se calcula con `calcularEconomiaDetalle()`, la misma función que persiste el detalle, para que el valor registrado sea siempre el real.
7. **Contabilidad**: `ContabilidadCompra` registra `ComprobanteCompra` y los `PagoComprobanteCompra` (puede ser total o parcial, multi-moneda, multi-cuenta).
8. **Valorización de venta**: `ValorizacionVenta` (modelos `ValorizacionVenta`, `ValorizacionVentaDetalle`) — el detalle apunta al ítem de despacho (`id_despacho_detalle`) y usa la **ley final confirmada** de ese ítem; la ley final se confirma en Programación de Despachos.
   - **Auditoría de la edición** (`editar_valorizacion`): el `log_cambios` de la cabecera registra `Total Valorización` (antes/después), `Detalle de valorización agregado` y `Detalle de valorización removido`; el `log_cambios` del detalle registra los cambios de parámetros (INTER, recuperación, maquila, consumo, factor, PTN, subtotal) y, cuando el detalle es nuevo, su **alta** (composición inicial). El identificador del detalle se arma con `identificadorDetalleVenta()` (código preliminar · despacho · lote/blend).
   - El motivo de la edición llega en `motivo_edicion` (el controller lo valida y lo pasa al service) y queda en el `motivo` de la entrada del log.
9. **Despacho**: a `PlantasDestino` (plantas receptoras del mineral procesado). Modelos: `PlantaDestino`, `CuentasBancariasPlantaDestino`.
   - **Leyes reportadas por el cliente** (`distribucion_detalle.ley_oro_cliente` / `ley_plata_cliente` / `ley_humedad_cliente`): se registran en la **llegada del cliente** (modal "Registrar Datos del Cliente") con **3 decimales**, igual que el resto del sistema (`lote_mineral` `DECIMAL(10,3)` y `grupo_analisis` `DECIMAL(12,3)`). El backend las normaliza con `round(..., 3)`. La validación es `nullable|numeric|min:0` (y `max:100` para la humedad): no impone número de decimales.

## Guías y Ticket de Balanza

Las guías viven en dos tramos y se registran en dos sitios distintos:

| Tramo | Tabla | Se registra en | Enlace |
|---|---|---|---|
| 1er tramo (ingreso) | `guia_primer_tramo` | Al registrar la unidad de recepción **o** en el módulo de Guías de Primer Tramo | `guia_primer_tramo.id_recepcion_unidad` **o** `lote_guia` (`id_lote_mineral` / `id_particion_lote_mineral`) |
| 2º tramo (embarque) | `guia_segundo_tramo` | Programación de Despachos | `guia_segundo_tramo.id_ditribucion` |

Ojo: un `lote_guia` enlazado a una **partición** tiene `id_lote_mineral` NULL (el lote está en `id_particion_lote_mineral`). Cualquier resolución por `lote_guia` debe cubrir ambos casos.

## Dashboard de indicadores (fuente de las toneladas)

`DashboardData::get_serie_mensual()` arma la serie mensual. Las toneladas tienen **cuatro fuentes distintas**, que no deben confundirse:

| Métrica | Origen del peso | Mes de atribución |
|---|---|---|
| `tn_receptadas` | `lote_mineral.peso_neto` | `COALESCE(fecha_hora_peso_final, created_at)` |
| `tn_despachadas` | `despacho_detalle.peso_tomado` | `despacho.created_at` |
| **`tn_compradas`** (gráfico "Compras vs ventas") | `lote_mineral.peso_neto`, **una vez por lote único** | `valorizacion_compra.fecha_hora_valorizacion` |
| **`tn_vendidas`** (gráfico "Compras vs ventas") | `despacho_detalle.peso_tomado`, **una vez por ítem despachado único** | `valorizacion_venta.fecha_hora_valorizacion` |

**Regla de no duplicar** (CTEs `cmp_lote` y `vta_item`): el mismo lote puede aparecer en varias valorizaciones del mismo mes y valorizarse en **Oro y Plata**; con una suma directa el peso se contaba 2-3 veces (p.ej. 53 t compradas frente a 20 t realmente recibidas). Por eso las toneladas del gráfico se calculan agrupando por entidad única:

- Compras: `GROUP BY ym, lm.id` (el lote, resuelriendo la partición: `COALESCE(lg.id_lote_mineral, particion.id_lote_mineral)`).
- Ventas: `GROUP BY ym, dd.id` (el ítem despachado; los despachos parciales son ítems distintos y su peso sí se suma).

Con esto `tn_compradas` = `tn_receptadas` y `tn_vendidas` = `tn_despachadas` para el mismo conjunto de lotes, y el **% de rotación** del gráfico refleja la rotación real.

Los **costos por tonelada** (`costo_compra_usd_t`, `costos.compra[Oro|Plata]`) siguen calculándose sobre el peso por elemento (`cmp` / `vta` agrupados por `ym, el`): al ser promedios ponderados, la duplicación se cancela y el resultado no cambia.

### Stock en cancha (`get_snapshot()`)

Es **el peso que hoy sigue en la cancha**, desglosado en tres componentes:

| Componente | Origen | Qué representa |
|---|---|---|
| `lotes` | `COALESCE(lm.peso_actual, lm.peso_neto)` | Lotes con material remanente en la cancha |
| `blendings` | `b.peso_actual` | Blendings con material remanente |
| `por_salir` | `distribucion_detalle.peso_tomado` de los despachos **no anulados** | Ítems **despachados** (de lote o de blending) |

`lote_mineral.peso_actual` **se descuenta** al mezclar (`BlendingService`) y al despachar (`ProgramacionDespachosService`): es el remanente real. Por eso el CTE `lotes` **no** excluye los lotes que tienen `blending_detalle` ni `despacho_detalle` — hacerlo borraba del stock material que seguía en la cancha (el remanente del lote se sumaba dos veces: en el lote y en el blend).

`distribucion_detalle.peso_tomado` es la **porción** consumida por cada distribución: al sumar todas las distribuciones de un `despacho_detalle` se obtiene el peso total del ítem despachado, sin duplicar cuando el ítem se reparte en varias distribuciones.

La suma de los tres componentes es el stock total, y cuadra con lo recibido (`tn_receptadas`): cada gramo está en un solo lugar — el remanente del lote, el remanente del blend o el ítem despachado.

**Resolución en el ticket** (`App\Data\GuiasData`):

| Tipo de ticket | Fuente | Orden de resolución |
|---|---|---|
| **Recepción** (`lote_mineral.id_ticket_balanza`, `particion_lote_mineral.id_ticket_balanza`) | `guia_primer_tramo` | 1) la guía enlazada al item vía `lote_guia` (primero la partición, luego el lote padre) · 2) la guía de la unidad de recepción (`guia_primer_tramo.id_recepcion_unidad`) · 3) los números capturados en la programación (`recepcion_unidad.guia_remitente` / `guia_transportista`) |
| **Despacho** (`distribucion_detalle.id_ticket_balanza`) | `guia_segundo_tramo` | 1) la guía de segundo tramo de esa distribución · 2) la del último despacho del lote · 3) la del primer tramo del lote |

Se aplica a los 5 endpoints de ticket (`recepcion-mineral/lotes/{id}/ticket-balanza`, `.../particiones/{id}/ticket-balanza`, `.../distribuciones-detalles/{id}/ticket-balanza`, `validacion-distribucion/lotes/{id}/ticket-balanza` y `.../particiones/{id}/ticket-balanza`).

## Edición de lotes y particiones (reglas de integridad)

Aplican al módulo `RecepcionMineral` (edición desde el Resumen de Balanza) y a cualquier otra ruta que escriba sobre `lote_mineral` o `particion_lote_mineral`.

1. **Bloqueo por valorización**. Un lote con valorización viva (`EstadoValorizacionCompra::Propuesta` o `::Aprobado`) **no es editable**. La comprobación vive en `RecepcionMineralData::get_valorizacion_viva_por_lote()` y se aplica en `RecepcionMineralService::actualizar_lote()` y `actualizar_campos_no_peso()` (respuesta `ApiResponse::error(..., 422)`). La ruta de resolución es `valorizacion_compramineral_detalle → valorizacion_compra → lote_guia → COALESCE(lg.id_lote_mineral, plm.id_lote_mineral)`: si lo valorizado fue una partición, el `COALESCE` resuelve al **lote padre** y el bloqueo alcanza a todo el lote. El alcance es la **edición de datos**: el pesaje (`registrar_peso_inicial` / `registrar_peso_final` y sus variantes de partición) no se bloquea.
2. **Los pesos del padre particionado son un agregado**. `lote_mineral.peso_neto` es el sumatorio de los `peso_neto` de sus particiones (lo fija `finalizar_particion_lote`). La edición del padre **no** escribe pesos: `RecepcionMineralData::recalcular_peso_neto_lote_padre()` mantiene el agregado al día cuando cambia el peso de una partición.
3. **Datos no-peso en cascada**. Proveedor, zona, contacto, tipo de producto y tipo de mineral viven en `lote_mineral` (el padre). `RecepcionMineralService::persistir_campos_no_peso_en_padre()` los propaga al padre cuando se edita una partición; el resto de particiones los hereda por JOIN.
4. **El transporte es de la unidad**. Vehículo, empresa de transporte y conductor viven en `recepcion_unidad` (una por lote o por partición), no en `lote_mineral`. La edición de un lote los persiste sobre su unidad.
5. **Correlativo de partición**. `particion_lote_mineral.correlativo` es `{correlativo_lote}-{letra}`. Si cambia la condición de ingreso del padre y se regenera su correlativo, hay que realinear el de sus particiones.
6. **El resumen devuelve tres tipos de fila**: `LOTE_RECEPCION` (lote), `PARTICION` (una fila por partición de un lote particionado) y `DISTRIBUCION_DETALLE` (despacho). `get_resumen_balanza()` los arma con un `UNION ALL` de tres bloques con idéntica lista de columnas; cada fila trae `valorizacion` (valorización viva que bloquea la edición) cuando aplica.

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

**Nunca usar `php artisan serve`** (es single-threaded y re-bootea Laravel en cada request). Eso vuelve inutilizable el desarrollo paralelo del frontend.

El server de desarrollo depende del sistema operativo:

- **macOS / Linux** → Laravel Octane con FrankenPHP (workers persistentes).
- **Windows** → Laravel Herd, que usa nginx + PHP-FPM (mismo stack que producción). Laravel Octane **no se soporta oficialmente en Windows** (requiere extensiones PHP `pcntl`/`posix` que no existen ahí); Herd es la solución oficial del equipo Laravel para ese entorno.

### macOS / Linux — Octane + FrankenPHP

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
php artisan octane:start --watch

# T2 — WebSockets
php artisan reverb:start

# T3 — Frontend
cd ../fabero && npm run dev
```

### Windows — Laravel Herd

Setup inicial (una vez por máquina):

1. Descargar e instalar Herd desde **https://herd.laravel.com/download/windows** (doble-click al `.exe`, siguiente → siguiente → instalar; pide admin una vez para registrar nginx como servicio).
2. Abrir PowerShell, ir a la API y vincular el sitio con el hostname compartido del equipo:

```powershell
cd C:\ruta\al\proyecto\faberoAPI
herd link api-local-fabero
```

Si Herd pregunta por la versión de PHP, elegir **8.4** (cumple el `^8.2` del `composer.json`).

3. Instalar dependencias PHP y preparar Laravel (igual que en Mac):

```powershell
composer install
php artisan key:generate
php artisan storage:link
```

4. (Opcional) Visitar `https://api-local-fabero.test` en el navegador para verificar que Herd sirve el sitio.

Diario (3 terminales):

```powershell
# T1 — Sitio Laravel (nginx + php-fpm vía Herd)
herd start

# T2 — WebSockets (Reverb corre como proceso PHP normal)
php artisan reverb:start

# T3 — Frontend
cd ..\fabero && npm run dev
```

La API queda expuesta en `https://api-local-fabero.test` (HTTPS auto-firmado; Herd lo configura solo). El puerto lo sirve nginx en `:80`/`:443`; el PHP-FPM corre interno con workers concurrentes.

#### Comandos Herd

| Comando                          | Uso                                              |
| -------------------------------- | ------------------------------------------------ |
| `herd link <hostname>`           | Vincula el directorio actual como sitio Laravel  |
| `herd link --unlink`             | Desvincula el sitio del directorio actual        |
| `herd start`                     | Arranca nginx + php-fpm (el sitio Laravel)       |
| `herd stop`                      | Detiene nginx + php-fpm                          |
| `herd restart`                   | Reinicia el sitio                                |

#### En desarrollo, **no ejecutar** estos comandos (invalida el hot-reload):

```bash
php artisan config:cache     # congela la config en bootstrap/cache/
php artisan route:cache      # congela las rutas en bootstrap/cache/
php artisan view:cache       # congela vistas Blade compiladas
```

Si algo no se refleja después de editar, limpiar todos los caches con un solo comando:

```bash
php artisan optimize:clear
```

Eso limpia config, rutas, vistas y eventos. Repetir y refrescar el navegador.

Para cambios en `composer.json` (instalar/actualizar un paquete), también ejecutar:

```bash
composer install
php artisan optimize:clear
```

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
