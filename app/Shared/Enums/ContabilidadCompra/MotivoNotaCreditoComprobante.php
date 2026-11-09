<?php

namespace App\Shared\Enums\ContabilidadCompra;

/**
 * Catálogo 09 SUNAT — "Códigos de Tipo de Nota de Crédito Electrónica".
 *
 * Anexo N° 8 (R.S. 097-2012/SUNAT, modificada por R.S. 193-2020/SUNAT).
 * El *value* del enum es el código SUNAT; la descripción va en `descripcion()`.
 *
 * NO reutilizar este enum para notas de débito: el Catálogo 10 tiene códigos
 * homónimos con otro significado (ver MotivoNotaDebitoComprobante).
 */
enum MotivoNotaCreditoComprobante: string
{
    case AnulacionOperacion = '01';
    case AnulacionErrorRuc = '02';
    case CorreccionErrorDescripcion = '03';
    case DescuentoGlobal = '04';
    case DescuentoItem = '05';
    case DevolucionTotal = '06';
    case DevolucionItem = '07';
    case Bonificacion = '08';
    case DisminucionValor = '09';
    case OtrosConceptos = '10';
    case AjustesOperacionExportacion = '11';
    case AjustesAfectosIvap = '12';
    case CorreccionMontoNetoPendiente = '13';

    /**
     * Descripción oficial del motivo.
     */
    public function descripcion(): string
    {
        return match ($this) {
            self::AnulacionOperacion => 'Anulación de la operación',
            self::AnulacionErrorRuc => 'Anulación por error en el RUC',
            self::CorreccionErrorDescripcion => 'Corrección por error en la descripción',
            self::DescuentoGlobal => 'Descuento global',
            self::DescuentoItem => 'Descuento por ítem',
            self::DevolucionTotal => 'Devolución total',
            self::DevolucionItem => 'Devolución por ítem',
            self::Bonificacion => 'Bonificación',
            self::DisminucionValor => 'Disminución en el valor',
            self::OtrosConceptos => 'Otros conceptos',
            self::AjustesOperacionExportacion => 'Ajustes de operaciones de exportación',
            self::AjustesAfectosIvap => 'Ajustes afectos al IVAP',
            self::CorreccionMontoNetoPendiente => 'Corrección del monto neto pendiente de pago y/o fechas de vencimiento',
        };
    }

    /**
     * @return array<string,string> código => descripción
     */
    public static function opciones(): array
    {
        $opciones = [];
        foreach (self::cases() as $caso) {
            $opciones[$caso->value] = $caso->descripcion();
        }

        return $opciones;
    }
}
