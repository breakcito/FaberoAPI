<?php

namespace App\Shared\Enums\ContabilidadCompra;

/**
 * Catálogo 10 SUNAT — "Códigos de Tipo de Nota de Débito Electrónica".
 *
 * Anexo N° 8 (R.S. 097-2012/SUNAT). El *value* del enum es el código SUNAT;
 * la descripción va en `descripcion()`.
 *
 * NO reutilizar este enum para notas de crédito: el Catálogo 09 tiene códigos
 * homónimos con otro significado (ver MotivoNotaCreditoComprobante).
 */
enum MotivoNotaDebitoComprobante: string
{
    case InteresesMora = '01';
    case AumentoValor = '02';
    case PenalidadesOtrosConceptos = '03';
    case AjustesOperacionExportacion = '11';
    case AjustesAfectosIvap = '12';

    /**
     * Descripción oficial del motivo.
     */
    public function descripcion(): string
    {
        return match ($this) {
            self::InteresesMora => 'Intereses por mora',
            self::AumentoValor => 'Aumento en el valor',
            self::PenalidadesOtrosConceptos => 'Penalidades / otros conceptos',
            self::AjustesOperacionExportacion => 'Ajustes de operaciones de exportación',
            self::AjustesAfectosIvap => 'Ajustes afectos al IVAP',
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
