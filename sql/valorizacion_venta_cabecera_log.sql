-- =====================================================================
-- Fabero — ALTER TABLE valorizacion_venta (cabecera)
-- =====================================================================
-- Agrega las columnas mínimas para que el módulo registre eventos
-- de CABECERA en log_cambios (aprobación, anulación, edición).
-- Mantener la columna solo para cabecera de valorizacion_venta;
-- valorizacion_venta_detalle ya tiene su propia log_cambios.
--
-- EJECUCIÓN (conectado a tu BD):
--   mysql -u <user> -p <db_name> < sql/valorizacion_venta_cabecera_log.sql
-- =====================================================================

ALTER TABLE valorizacion_venta
    ADD COLUMN id_empleado_aprobacion INT NULL AFTER id_empleado_registro,
    ADD COLUMN id_empleado_anulacion INT NULL AFTER id_empleado_aprobacion,
    ADD COLUMN fecha_hora_aprobacion DATETIME NULL AFTER monto_flete,
    ADD COLUMN fecha_hora_anulacion DATETIME NULL AFTER fecha_hora_aprobacion,
    ADD COLUMN motivo_anulacion TEXT NULL AFTER fecha_hora_anulacion,
    ADD COLUMN evidencias_anulacion JSON NULL AFTER motivo_anulacion,
    ADD COLUMN log_cambios JSON NULL AFTER evidencias_anulacion;
