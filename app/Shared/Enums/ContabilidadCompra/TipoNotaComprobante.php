<?php

namespace App\Shared\Enums\ContabilidadCompra;

enum TipoNotaComprobante: string
{
    /** Disminuye el monto a pagar del comprobante (Catálogo 09 SUNAT). */
    case Credito = 'Credito';

    /** Aumenta el monto a pagar del comprobante (Catálogo 10 SUNAT). */
    case Debito = 'Debito';

    /**
     * Descripción legible del tipo de nota.
     */
    public function descripcion(): string
    {
        return match ($this) {
            self::Credito => 'Nota de Crédito',
            self::Debito => 'Nota de Débito',
        };
    }

    /**
     * Signo que aplica la nota sobre el monto neto del comprobante.
     * Crédito resta, débito suma.
     */
    public function signo(): int
    {
        return match ($this) {
            self::Credito => -1,
            self::Debito => 1,
        };
    }

    /**
     * Series SUNAT típicas para este tipo de nota (referencia, no validación).
     */
    public function seriesReferencia(): string
    {
        return match ($this) {
            self::Credito => 'FC01, FC02, FC03, FC04',
            self::Debito => 'FD01, FD02, FD03',
        };
    }
}
