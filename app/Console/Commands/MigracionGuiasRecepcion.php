<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Rellena el modelo nuevo de recepción de unidad:
 *
 *  - `proveedor_recepcion`: proveedores de la carga de cada recepción.
 *  - `recepcion_unidad.guias`: guías por proveedor.
 *
 * Es idempotente: se puede ejecutar varias veces sin duplicar filas.
 * NO borra datos ni hace DDL (las tablas ya existen).
 */
class MigracionGuiasRecepcion extends Command
{
    protected $signature = 'migracion:guias-recepcion
        {--dry-run : Solo mostrar qué se migraría sin modificar nada}';

    protected $description = 'Migra los proveedores y las guías de las recepciones de unidad al modelo proveedor_recepcion + JSON guias.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $recepciones = DB::table('recepcion_unidad')
            ->orderBy('id')
            ->get(['id', 'guias', 'es_recepcion_ficticia']);

        if ($recepciones->isEmpty()) {
            $this->info('No hay recepciones de unidad para migrar.');

            return self::SUCCESS;
        }

        $proveedoresPorRecepcion = $this->mapear_proveedores();
        $guiasPorRecepcion = $this->mapear_guias();

        $proveedoresInsertados = 0;
        $guiasMigradas = 0;
        $recepcionesSinProveedor = 0;
        $recepcionesSinGuias = 0;
        $detalle = [];

        foreach ($recepciones as $recepcion) {
            $idRecepcion = (int) $recepcion->id;
            $proveedores = $proveedoresPorRecepcion[$idRecepcion] ?? [];

            if (empty($proveedores)) {
                $recepcionesSinProveedor++;
            } else {
                $existentes = DB::table('proveedor_recepcion')
                    ->where('id_recepcion_unidad', $idRecepcion)
                    ->pluck('id_proveedor_minero')
                    ->map(static fn ($id) => (int) $id)
                    ->all();

                $nuevos = array_values(array_diff($proveedores, $existentes));

                if (! empty($nuevos)) {
                    if (! $dryRun) {
                        $filas = [];
                        foreach ($nuevos as $idProveedor) {
                            $filas[] = [
                                'id_recepcion_unidad' => $idRecepcion,
                                'id_proveedor_minero' => $idProveedor,
                            ];
                        }
                        DB::table('proveedor_recepcion')->insert($filas);
                    }
                    $proveedoresInsertados += count($nuevos);
                }

                $detalle[] = 'Recepción '.$idRecepcion.' → proveedores: '.implode(', ', $proveedores);
            }

            // Guías: solo se rellenan si la recepción todavía no tiene array.
            $guias = $guiasPorRecepcion[$idRecepcion] ?? [];

            if (empty($guias)) {
                $recepcionesSinGuias++;

                continue;
            }

            $actuales = $recepcion->guias;
            if (is_string($actuales)) {
                $actuales = json_decode($actuales, true);
            }
            if (is_array($actuales) && ! empty($actuales)) {
                continue;
            }

            if (! $dryRun) {
                DB::table('recepcion_unidad')
                    ->where('id', $idRecepcion)
                    ->update(['guias' => json_encode($guias)]);
            }

            $guiasMigradas += count($guias);
        }

        foreach ($detalle as $linea) {
            $this->line('  '.$linea);
        }

        $this->info('Proveedores insertados en proveedor_recepcion: '.$proveedoresInsertados);
        $this->info('Guías migradas al array JSON `guias`: '.$guiasMigradas);
        $this->info('Recepciones sin proveedor recuperable: '.$recepcionesSinProveedor);
        $this->info('Recepciones sin guías recuperables: '.$recepcionesSinGuias);

        if ($recepcionesSinGuias > 0) {
            $this->warn(
                'Las guías de las recepciones sin datos no se pudieron recuperar: la tabla `guia_primer_tramo` está vacía '
                .'y las columnas `guia_remitente` / `guia_transportista` / `documentos_programacion` fueron eliminadas '
                .'de `recepcion_unidad` junto con su contenido. Esas recepciones quedan con `guias` en NULL.'
            );
        }

        if ($dryRun) {
            $this->info('Dry-run: no se modificó nada.');
        }

        return self::SUCCESS;
    }

    /**
     * Proveedores de cada recepción deducidos de los lotes y particiones que
     * recepta.
     *
     * - `lote_mineral.id_recepcion_unidad` → proveedor de la carga.
     * - `particion_lote_mineral.id_recepcion_unidad` → proveedor del lote
     *   padre (las recepciones ficticias de las particiones).
     *
     * @return array<int, array<int, int>>
     */
    private function mapear_proveedores(): array
    {
        $mapa = [];

        $lotes = DB::table('lote_mineral')
            ->whereNotNull('id_recepcion_unidad')
            ->whereNotNull('id_proveedor_minero')
            ->get(['id_recepcion_unidad', 'id_proveedor_minero']);

        foreach ($lotes as $lote) {
            $this->agregar_proveedor(
                $mapa,
                (int) $lote->id_recepcion_unidad,
                (int) $lote->id_proveedor_minero
            );
        }

        $particiones = DB::table('particion_lote_mineral as plm')
            ->join('lote_mineral as lm', 'lm.id', '=', 'plm.id_lote_mineral')
            ->whereNotNull('plm.id_recepcion_unidad')
            ->whereNotNull('lm.id_proveedor_minero')
            ->get([
                'plm.id_recepcion_unidad',
                'lm.id_proveedor_minero',
            ]);

        foreach ($particiones as $particion) {
            $this->agregar_proveedor(
                $mapa,
                (int) $particion->id_recepcion_unidad,
                (int) $particion->id_proveedor_minero
            );
        }

        return $mapa;
    }

    /**
     * Guías de cada recepción desde `guia_primer_tramo` (la fuente de verdad
     * de las guías registradas).
     *
     * @param  array<int, array<int, int>>  $mapa
     */
    private function agregar_proveedor(array &$mapa, int $idRecepcion, int $idProveedor): void
    {
        if ($idRecepcion <= 0 || $idProveedor <= 0) {
            return;
        }

        if (! isset($mapa[$idRecepcion])) {
            $mapa[$idRecepcion] = [];
        }

        if (! in_array($idProveedor, $mapa[$idRecepcion], true)) {
            $mapa[$idRecepcion][] = $idProveedor;
        }
    }

    /**
     * Guías de cada recepción registradas en `guia_primer_tramo`.
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function mapear_guias(): array
    {
        $guias = DB::table('guia_primer_tramo')
            ->whereNotNull('id_recepcion_unidad')
            ->where('estado', 'Activo')
            ->orderBy('id_recepcion_unidad')
            ->orderBy('id')
            ->get([
                'id_recepcion_unidad',
                'id_proveedor',
                'guia_remitente',
                'guia_transportista',
            ]);

        $mapa = [];
        foreach ($guias as $guia) {
            $idRecepcion = (int) $guia->id_recepcion_unidad;
            $idProveedor = (int) $guia->id_proveedor;

            if ($idRecepcion <= 0 || $idProveedor <= 0) {
                continue;
            }

            if (! isset($mapa[$idRecepcion])) {
                $mapa[$idRecepcion] = [];
            }

            $mapa[$idRecepcion][] = [
                'id_proveedor' => $idProveedor,
                'guia_remitente' => $guia->guia_remitente !== null ? (string) $guia->guia_remitente : '',
                'guia_transportista' => $guia->guia_transportista !== null ? (string) $guia->guia_transportista : '',
                'guia_remitente_archivo' => null,
                'guia_transportista_archivo' => null,
            ];
        }

        return $mapa;
    }
}
