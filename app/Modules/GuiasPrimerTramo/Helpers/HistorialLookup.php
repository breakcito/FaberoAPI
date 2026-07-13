<?php

namespace App\Modules\GuiasPrimerTramo\Helpers;

use Illuminate\Support\Facades\DB;

/**
 * Resuelve IDs de FK a etiquetas legibles para el historial.
 * Usa cache estático por request para minimizar queries.
 *
 * Devuelve `null` si el ID no existe o es 0/null.
 */
class HistorialLookup
{
    /**
     * Mapa campo → callable que devuelve el nombre legible.
     *
     * @var array<string, callable(int): ?string>
     */
    private static array $resolvers = [];

    /**
     * Cache de IDs ya resueltos para evitar N+1.
     *
     * @var array<string, array<int, string>>
     */
    private static array $cache = [];

    /**
     * Mapa campo → nombre de tabla origen del FK (para invalidar cache si se refresca la página).
     * Aquí no hace falta invalidar porque el helper vive un solo request.
     */
    private const MAPA_CABECERA = [
        'id_sucursal',
        'id_proveedor',
        'id_concesion',
        'id_conductor',
        'id_vehiculo',
        'id_empresa_transporte',
        'id_vehiculo_carreta',
        'id_empresa_transporte_carreta',
    ];

    /**
     * Resuelve un ID individual según el campo.
     */
    public static function resolver(string $campo, mixed $id): mixed
    {
        if ($id === null || $id === '' || (int) $id === 0) {
            return null;
        }

        $id = (int) $id;

        if (isset(self::$cache[$campo][$id])) {
            return self::$cache[$campo][$id];
        }

        $callable = self::resolverPara($campo);
        if ($callable === null) {
            return null;
        }

        $nombre = $callable($id);
        self::$cache[$campo][$id] = $nombre;

        return $nombre;
    }

    /**
     * Enriquece un diff campo-a-campo reemplazando IDs por nombres legibles.
     * Si la resolución falla, deja el valor original (int).
     *
     * @param  array<string, array{anterior: mixed, nuevo: mixed}>  $diff
     * @param  array<int, string>  $camposAplicables  Si se omite, se aplica a MAPA_CABECERA.
     * @return array<string, array{anterior: mixed, nuevo: mixed}>
     */
    public static function enrichDiff(array $diff, ?array $camposAplicables = null): array
    {
        $campos = $camposAplicables ?? self::MAPA_CABECERA;

        foreach ($diff as $campo => $cambio) {
            if (! in_array($campo, $campos, true)) {
                continue;
            }

            $diff[$campo] = [
                'anterior' => self::resolver($campo, $cambio['anterior'] ?? null),
                'nuevo' => self::resolver($campo, $cambio['nuevo'] ?? null),
            ];
        }

        return $diff;
    }

    /**
     * Resuelve el id_lote_mineral al correlativo del lote.
     */
    public static function loteMineralCorrelativo(int $idLoteMineral): ?string
    {
        $row = DB::selectOne('SELECT correlativo FROM lote_mineral WHERE id = :id', ['id' => $idLoteMineral]);

        return $row?->correlativo ?? null;
    }

    /**
     * Devuelve el callable que resuelve un campo específico.
     *
     * @return callable(int): ?string|null
     */
    private static function resolverPara(string $campo): ?callable
    {
        return match ($campo) {
            'id_sucursal' => static fn (int $id) => self::fetchField('sucursal', 'id', $id, 'nombre'),
            'id_proveedor' => static fn (int $id) => self::fetchField('proveedor', 'id', $id, 'razon_social'),
            'id_concesion' => static fn (int $id) => self::fetchField('concesion', 'id', $id, 'nombre'),
            'id_conductor' => static function (int $id): ?string {
                $row = DB::selectOne('SELECT nombre, apellido FROM conductor WHERE id = :id', ['id' => $id]);

                return $row ? trim($row->nombre.' '.$row->apellido) : null;
            },
            'id_vehiculo', 'id_vehiculo_carreta' => static function (int $id): ?string {
                $row = DB::selectOne('SELECT serie_placa, numero_placa FROM vehiculo WHERE id = :id', ['id' => $id]);

                return $row ? self::formatoPlaca($row->serie_placa, $row->numero_placa) : null;
            },
            'id_empresa_transporte', 'id_empresa_transporte_carreta' => static fn (int $id) => self::fetchField('empresa_transporte', 'id', $id, 'razon_social'),
            default => null,
        };
    }

    /**
     * Trae un campo simple de una tabla. Devuelve el valor (string) o null.
     */
    private static function fetchField(string $tabla, string $pk, int $id, string $columna): ?string
    {
        $row = DB::selectOne("SELECT {$columna} AS v FROM {$tabla} WHERE {$pk} = :id", ['id' => $id]);

        return $row?->v ?? null;
    }

    /**
     * Formato "ABC-123" si hay serie, sino solo la placa.
     */
    private static function formatoPlaca(?string $serie, ?string $placa): ?string
    {
        if (! $placa) {
            return null;
        }

        return $serie ? "{$serie}-{$placa}" : $placa;
    }
}
