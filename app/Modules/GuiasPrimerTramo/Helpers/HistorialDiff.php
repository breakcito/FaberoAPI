<?php

namespace App\Modules\GuiasPrimerTramo\Helpers;

class HistorialDiff
{
    /**
     * Calcula el diff campo-a-campo entre dos estados, considerando solo los campos rastreados.
     *
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @param  array<int, string>  $tracked
     * @return array<string, array{anterior: mixed, nuevo: mixed}> Solo campos que efectivamente cambiaron.
     */
    public static function calcular(array $old, array $new, array $tracked): array
    {
        $cambios = [];
        foreach ($tracked as $campo) {
            $valorAnterior = $old[$campo] ?? null;
            $valorNuevo = $new[$campo] ?? null;

            if (self::equalsLoose($valorAnterior, $valorNuevo)) {
                continue;
            }

            $cambios[$campo] = [
                'anterior' => $valorAnterior,
                'nuevo' => $valorNuevo,
            ];
        }

        return $cambios;
    }

    /**
     * Compara dos valores con tolerancia:
     * - boolean ↔ int (false==0, true==1)
     * - string numérico ↔ int/float (MySQL devuelve "0" para TINYINT 0)
     * - arrays / JSON-decoded
     * - nulls
     */
    private static function equalsLoose(mixed $a, mixed $b): bool
    {
        if ($a === $b) {
            return true;
        }

        if (is_bool($a) || is_bool($b)) {
            return (bool) $a === (bool) $b;
        }

        if (is_array($a) || is_array($b)) {
            return json_encode($a) === json_encode($b);
        }

        if ($a === null || $b === null) {
            return $a === $b;
        }

        return (string) $a === (string) $b;
    }

    /**
     * Calcula el diff entre dos snapshots de evidencias (array de objetos/strings).
     * Devuelve listas de archivos agregados y eliminados comparando por 'nombre' o por valor escalar.
     *
     * @param  mixed  $oldJson  JSON/array previo (puede ser null)
     * @param  mixed  $newJson  JSON/array nuevo (puede ser null)
     * @return array{agregados: array<int, string>, eliminados: array<int, string>, comunes: array<int, string>}
     */
    public static function diffEvidencias(mixed $oldJson, mixed $newJson): array
    {
        $oldKeys = self::extraerNombresEvidencia(self::decoded($oldJson));
        $newKeys = self::extraerNombresEvidencia(self::decoded($newJson));

        return [
            'agregados' => array_values(array_diff($newKeys, $oldKeys)),
            'eliminados' => array_values(array_diff($oldKeys, $newKeys)),
            'comunes' => array_values(array_intersect($oldKeys, $newKeys)),
        ];
    }

    /**
     * Decodifica JSON a array. Si ya es array, lo devuelve igual. Si no se puede decodificar, devuelve [].
     *
     * @return array<int, mixed>
     */
    private static function decoded(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    /**
     * Extrae los nombres legibles de cada evidencia.
     * Preferencia: `nombre` (original uploaded) > nombre derivado del path/url > json_encode.
     *
     * @param  array<int, mixed>  $evidencias
     * @return array<int, string>
     */
    private static function extraerNombresEvidencia(array $evidencias): array
    {
        $claves = [];
        foreach ($evidencias as $e) {
            if (is_array($e)) {
                $valor = $e['nombre']
                    ?? $e['name']
                    ?? $e['nombre_original']
                    ?? null;

                if ($valor === null) {
                    $path = $e['ruta'] ?? $e['path'] ?? $e['url'] ?? null;
                    $valor = $path !== null ? self::basenameFromUrl($path) : null;
                }

                $claves[] = $valor ?? json_encode($e);
            } else {
                $claves[] = is_string($e) ? self::basenameFromUrl($e) : (string) $e;
            }
        }

        return $claves;
    }

    /**
     * Extrae el nombre de archivo (basename) a partir de una URL o path.
     * Acepta tanto URLs completas (parse_url) como paths locales.
     */
    private static function basenameFromUrl(string $urlOrPath): ?string
    {
        $parsed = parse_url($urlOrPath, PHP_URL_PATH);
        $path = $parsed !== null && $parsed !== false ? $parsed : $urlOrPath;
        $base = basename($path);

        return $base !== '' ? $base : null;
    }
}
