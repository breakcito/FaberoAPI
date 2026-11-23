<?php

namespace App\Shared\Helpers;

/**
 * Campos que se registran en una PROGRAMACIÓN (de recepción o de despacho) y
 * que, por lo tanto, quedan BLOQUEADOS para edición posterior.
 *
 * Contexto:
 *  - "Registro de unidad" (ingreso directo): el operador elige todo al
 *    momento, así que esos datos quedan editables.
 *  - "Programación de recepción" / "Programación de despacho": los datos los
 *    registró (y autoriza) otra persona, por eso no se pueden tocar después
 *    de confirmar la llegada.
 *
 * NO se bloquean:
 *  - `tipo_ingreso`: lo fija el proceso de la unidad, no es un dato de la
 *    programación.
 *  - `fecha_estimada_llegada`: es una estimación de agenda, no un dato de la
 *    unidad.
 *  - `observacion`: la programación no registra observación, así que el
 *    operador siempre puede anotarla al cierre de la unidad.
 */
class CamposBloqueadosHelper
{
    /**
     * Campos que pueden quedar bloqueados (y editarse si no lo están).
     *
     * @var array<int, string>
     */
    public const CAMPOS = [
        'id_empresa_transporte',
        'id_vehiculo',
        'id_vehiculo_carreta',
        'id_conductor',
        'id_tipo_vehiculo',
        'id_proveedor_minero',
        'guia_remitente',
        'guia_transportista',
    ];

    /**
     * Campos de TEXTO dentro de `CAMPOS`: se guardan como string (serie y
     * número de la guía), no como id referenciado.
     *
     * @var array<int, string>
     */
    public const CAMPOS_TEXTO = [
        'guia_remitente',
        'guia_transportista',
    ];

    /**
     * Campos que se registran en una programación (y por tanto se bloquean).
     *
     * Incluye los editables (`CAMPOS`) más las guías, que también se registran
     * en el formulario de programación pero se gestionan por su propio flujo.
     *
     * @var array<int, string>
     */
    public const CAMPOS_REGISTRADOS_EN_PROGRAMACION = [
        'id_empresa_transporte',
        'id_vehiculo',
        'id_vehiculo_carreta',
        'id_conductor',
        'id_tipo_vehiculo',
        'id_proveedor_minero',
        'guia_remitente',
        'guia_transportista',
    ];

    /**
     * Etiquetas legibles para el log de cambios y los mensajes de error.
     *
     * @var array<string, string>
     */
    public const ETIQUETAS = [
        'id_empresa_transporte' => 'Empresa de transporte',
        'id_vehiculo' => 'Vehículo',
        'id_vehiculo_carreta' => 'Vehículo carreta',
        'id_conductor' => 'Conductor',
        'id_tipo_vehiculo' => 'Tipo de vehículo',
        'id_proveedor_minero' => 'Proveedor minero',
        'guia_remitente' => 'Guía remitente',
        'guia_transportista' => 'Guía transportista',
    ];

    /**
     * Calcula los campos bloqueados de una fila de programación.
     *
     * Solo se bloquean los campos que efectivamente tienen valor (los que se
     * registraron); los que quedaron vacíos se pueden editar después.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    public static function calcular(array $data): array
    {
        $bloqueados = [];

        foreach (self::CAMPOS_REGISTRADOS_EN_PROGRAMACION as $campo) {
            if (! empty($data[$campo])) {
                $bloqueados[] = $campo;
            }
        }

        return $bloqueados;
    }

    /**
     * Normaliza el valor guardado en la columna JSON (array o null).
     *
     * @return array<int, string>
     */
    public static function normalizar(mixed $valor): array
    {
        if (is_string($valor)) {
            $valor = json_decode($valor, true);
        }

        if (! is_array($valor)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn ($c) => is_string($c) ? $c : null, $valor)
        ));
    }

    /**
     * Etiqueta legible de un campo bloqueado.
     */
    public static function etiqueta(string $campo): string
    {
        return self::ETIQUETAS[$campo] ?? $campo;
    }
}
