<?php

namespace App\Modules\InterfacesPesosBalanzas\Data;

use Illuminate\Support\Facades\DB;

/**
 * Interfaz con las balanzas físicas.
 *
 * La computadora de la balanza escribe el peso pesado en la columna `peso` de
 * `interfaces_pesosbalanzas`. El valor centinela `-1` significa "no hay peso
 * disponible" (la balanza ya entregó su lectura o nunca ha pesado).
 *
 * El módulo que registra el peso libera la balanza volviendo a dejar `-1`,
 * de modo que la balanza pueda publicar la siguiente lectura.
 */
class InterfacesPesosBalanzasData
{
    /**
     * Peso publicado por una balanza.
     *
     * La columna es varchar: se normaliza a número. El centinela `-1` (o
     * cualquier valor no numérico) se devuelve como `null`, es decir "no hay
     * peso que registrar".
     */
    public static function get_peso(int $codBalanza): ?float
    {
        $valor = DB::table('interfaces_pesosbalanzas')
            ->where('cod_balanza', $codBalanza)
            ->value('peso');

        if ($valor === null) {
            return null;
        }

        $texto = trim((string) $valor);
        if ($texto === '' || ! is_numeric($texto)) {
            return null;
        }

        $peso = (float) $texto;
        if ($peso <= 0) {
            return null;
        }

        return $peso;
    }

    /**
     * Descripción de la balanza (para mostrar en la interfaz).
     */
    public static function get_descripcion(int $codBalanza): ?string
    {
        $descripcion = DB::table('interfaces_pesosbalanzas')
            ->where('cod_balanza', $codBalanza)
            ->value('descripcion');

        return $descripcion !== null ? (string) $descripcion : null;
    }

    /**
     * Devuelve la balanza a su estado de espera: `peso = '-1'`.
     *
     * Se llama después de registrar el peso, para que la balanza sepa que
     * su lectura ya fue consumida y pueda publicar la siguiente.
     *
     * @return bool Si había una fila que actualizar.
     */
    public static function liberar(int $codBalanza): bool
    {
        return DB::table('interfaces_pesosbalanzas')
            ->where('cod_balanza', $codBalanza)
            ->update(['peso' => '-1']) > 0;
    }
}
