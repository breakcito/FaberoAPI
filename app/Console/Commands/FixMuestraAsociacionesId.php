<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixMuestraAsociacionesId extends Command
{
    protected $signature = 'muestras:fix-asociaciones-id
        {--dry-run : Solo mostrar qué se actualizaría sin modificar}';

    protected $description = 'Restaura id_muestra_externa en analisis_mineral para muestras ya asociadas con código antiguo (que seteaba id_muestra_externa = null). Busca el valor_original en log_cambios JSON[*].cambios[].valor_anterior.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // Traer análisis migrados sin id_muestra_externa
        $rows = DB::select('
            SELECT id, log_cambios
            FROM analisis_mineral
            WHERE sin_lote = 0
              AND id_muestra_externa IS NULL
        ');

        if (count($rows) === 0) {
            $this->info('No hay análisis pendientes de restaurar. Nada que hacer.');

            return self::SUCCESS;
        }

        $this->info('Encontrados '.count($rows).' análisis sin id_muestra_externa.');

        $fixed = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $log = is_string($row->log_cambios) ? json_decode($row->log_cambios, true) : $row->log_cambios;
            if (! is_array($log)) {
                $skipped++;
                continue;
            }

            $found = null;
            foreach ($log as $entry) {
                if (! isset($entry['cambios']) || ! is_array($entry['cambios'])) {
                    continue;
                }
                foreach ($entry['cambios'] as $cambio) {
                    if (($cambio['campo_bd'] ?? null) === 'id_muestra_externa'
                        && isset($cambio['valor_anterior'])
                        && $cambio['valor_anterior'] !== null) {
                        $found = (int) $cambio['valor_anterior'];
                        break 2;
                    }
                }
            }

            if ($found === null) {
                $this->warn("Análisis {$row->id}: no se encontró id_muestra_externa en log_cambios — saltado.");
                $skipped++;
                continue;
            }

            // Verificar que la muestra existe
            $exists = DB::table('muestra_externa')->where('id', $found)->exists();
            if (! $exists) {
                $this->warn("Análisis {$row->id}: muestra_externa id={$found} no existe — saltado.");
                $skipped++;
                continue;
            }

            if ($dryRun) {
                $this->line("  [dry-run] Análisis {$row->id} → muestra_externa id={$found}");
                $fixed++;
            } else {
                DB::table('analisis_mineral')->where('id', $row->id)->update(['id_muestra_externa' => $found]);
                $this->line("  Análisis {$row->id} → muestra_externa id={$found}");
                $fixed++;
            }
        }

        $this->info("Resultado: {$fixed} restaurados, {$skipped} saltados.".($dryRun ? ' (dry-run, no se modificó nada)' : ''));

        return self::SUCCESS;
    }
}