<?php

namespace App\Shared\Enums\_Generic;

/**
 * Catálogo de permisos específicos por usuario.
 *
 * Se aplican ENCIMA de los permisos por rol (menu / submenu / modulo de
 * `modulo_rol`). Un usuario cuyo array `usuario.permisos` está vacío NO tiene
 * restricciones adicionales: solo manda su rol.
 *
 * Cuando el array trae entradas, cada código listado se evalúa: `decision`
 * true = permitido, false = denegado. Los códigos que no aparecen en el array
 * se completan con `decision = false`.
 */
enum CodigoPermiso: string
{
    case VerDashboard = 'ver_dashboard';

    /**
     * Etiqueta visible del permiso.
     */
    public function nombre(): string
    {
        return match ($this) {
            self::VerDashboard => 'Ver Dashboard',
        };
    }

    /**
     * Agrupación para presentar el catálogo en la UI.
     */
    public function grupo(): string
    {
        return match ($this) {
            self::VerDashboard => 'Indicadores',
        };
    }

    /**
     * Catálogo completo para la UI: [{codigo, nombre, grupo}, ...]
     *
     * @return array<int, array{codigo: string, nombre: string, grupo: string}>
     */
    public static function catalogo(): array
    {
        return array_map(
            fn (self $caso) => [
                'codigo' => $caso->value,
                'nombre' => $caso->nombre(),
                'grupo' => $caso->grupo(),
            ],
            self::cases()
        );
    }

    /**
     * Normaliza el array recibido a la forma [{codigo, nombre, decision}, ...].
     *
     * Completa con `decision = false` los códigos del catálogo que no vieram,
     * y descarta los códigos que no existen en el catálogo.
     *
     * @param  array<int, array<string, mixed>>  $permisos
     * @return array<int, array{codigo: string, nombre: string, decision: bool}>
     */
    public static function normalizar(array $permisos): array
    {
        $porCodigo = [];
        foreach ($permisos as $permiso) {
            if (is_array($permiso) && isset($permiso['codigo'])) {
                $porCodigo[(string) $permiso['codigo']] = $permiso;
            }
        }

        $normalizado = [];
        foreach (self::cases() as $caso) {
            $entrada = $porCodigo[$caso->value] ?? null;
            $normalizado[] = [
                'codigo' => $caso->value,
                'nombre' => is_array($entrada) && ! empty($entrada['nombre'])
                    ? (string) $entrada['nombre']
                    : $caso->nombre(),
                'decision' => is_array($entrada) ? (bool) ($entrada['decision'] ?? false) : false,
            ];
        }

        return $normalizado;
    }

    /**
     * Indica si un permiso está concedido.
     *
     * Si el código no aparece en el array, no se restringe (solo manda el rol).
     *
     * @param  array<int, array<string, mixed>>  $permisos
     */
    public static function permitido(array $permisos, self $codigo): bool
    {
        foreach ($permisos as $permiso) {
            if (! is_array($permiso)) {
                continue;
            }
            if (($permiso['codigo'] ?? null) === $codigo->value) {
                return (bool) ($permiso['decision'] ?? false);
            }
        }

        return true;
    }

    /**
     * Decodifica el JSON crudo de `usuario.permisos` a la forma normalizada.
     *
     * Un array VACÍO significa "sin restricciones específicas": solo manda el
     * rol. En ese caso se devuelve vacío (no se expande el catálogo).
     */
    public static function decodificar(mixed $json): array
    {
        if (is_string($json)) {
            $json = json_decode($json, true);
        }

        if (! is_array($json) || count($json) === 0) {
            return [];
        }

        return self::normalizar($json);
    }
}
