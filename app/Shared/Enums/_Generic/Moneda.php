<?php
namespace App\Shared\Enums\_Generic;

/**
 * Enum de Monedas
 * Moneda::PEN->value; // "Soles"
 * Moneda::PEN->symbol(); // "S/"
 */
enum Moneda: string
{
    case PEN = 'Soles';
    case USD = 'Dólares';

    public function symbol(): string
    {
        return match ($this) {
            self::PEN => 'S/',
            self::USD => '$',
        };
    }
}