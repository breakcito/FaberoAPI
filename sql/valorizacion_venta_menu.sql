-- =====================================================================
-- Fabero — Inserción del módulo "Valorización de Venta" en el menú
-- =====================================================================
-- Este script registra el nuevo módulo en las tablas menu, submenu,
-- modulo y modulo_rol para que aparezca en el menú lateral del frontend
-- con la misma jerarquía que "Valorización Compra".
--
-- EJECUCIÓN (corre el script completo en tu instancia de MySQL):
--   mysql -u <user> -p <db_name> < sql/valorizacion_venta_menu.sql
--
-- NOTAS:
--   * El script es idempotente: si el menú/submenu/módulo ya existe,
--     los INSERT ... SELECT no insertan duplicados.
--   * Asigna el módulo a TODOS los roles que tengan acceso a
--     "Valorización Compra" (mismo permiso funcional).
--   * Si prefieres agregar a roles específicos, edita la sección
--     "ASIGNACIÓN A ROLES" antes de ejecutar.
-- =====================================================================

SET @id_modulo_vc := (SELECT id_modulo FROM modulo WHERE nombre = 'Listado' AND id_submenu = (
    SELECT id_submenu FROM submenu WHERE nombre = 'Valorización Compra' LIMIT 1
) LIMIT 1);

-- 1. SUBMENU: "Valorización Venta" en el mismo menu donde está "Valorización Compra"
INSERT INTO submenu (id_menu, nombre, path, numero_orden, estado)
SELECT
    s.id_menu,
    'Valorización Venta',
    'valorizacion-venta',
    s.numero_orden + 10,
    'Activo'
FROM submenu s
WHERE s.nombre = 'Valorización Compra'
LIMIT 1;

-- Si no existe submenu "Valorización Compra", abortar (no se puede inferir menú padre).
SELECT ROW_COUNT() AS submenu_insertado;

-- 2. MODULO: "Listado" dentro del nuevo submenu
INSERT INTO modulo (id_submenu, nombre, path, numero_orden, estado)
SELECT
    sm.id_submenu,
    'Listado',
    'listado',
    10,
    'Activo'
FROM submenu sm
WHERE sm.nombre = 'Valorización Venta'
  AND NOT EXISTS (
    SELECT 1 FROM modulo m WHERE m.id_submenu = sm.id_submenu AND m.nombre = 'Listado'
  )
LIMIT 1;

SELECT ROW_COUNT() AS modulo_insertado;

-- 3. ASIGNACIÓN A ROLES: copiar permisos de "Valorización Compra"
INSERT INTO modulo_rol (id_modulo, id_rol)
SELECT
    (SELECT id_modulo FROM modulo WHERE nombre = 'Listado' AND id_submenu = (
        SELECT id_submenu FROM submenu WHERE nombre = 'Valorización Venta' LIMIT 1
    ) LIMIT 1),
    mr.id_rol
FROM modulo_rol mr
WHERE mr.id_modulo = @id_modulo_vc;

SELECT ROW_COUNT() AS permisos_asignados;

-- 4. VERIFICACIÓN
SELECT
    m.id_menu, m.nombre AS menu, m.path AS menu_path,
    sm.id_submenu, sm.nombre AS submenu, sm.path AS submenu_path,
    md.id_modulo, md.nombre AS modulo, md.path AS modulo_path
FROM modulo md
JOIN submenu sm ON sm.id_submenu = md.id_submenu
JOIN menu m ON m.id_menu = sm.id_menu
WHERE md.id_modulo = (
    SELECT id_modulo FROM modulo WHERE nombre = 'Listado' AND id_submenu = (
        SELECT id_submenu FROM submenu WHERE nombre = 'Valorización Venta' LIMIT 1
    ) LIMIT 1
);
