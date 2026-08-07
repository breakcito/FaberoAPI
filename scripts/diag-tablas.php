<?php

/**
 * Script de diagnóstico SOLO LECTURA para evaluar las tablas `vehiculo` y `empleado`.
 *
 * No realiza INSERT/UPDATE/DELETE. Sirve para revisar el esquema actual,
 * verificar si las columnas nuevas (`placa`, `autoriza_ingreso_unidades`)
 * ya fueron creadas, y ver muestras de los datos existentes.
 *
 * Ejecución:
 *     cd FaberoAPI
 *     php scripts/diag-tablas.php
 */

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

/**
 * Imprime una sección con título.
 */
function titulo(string $texto): void
{
    $linea = str_repeat('=', 70);
    echo "\n".$linea."\n";
    echo strtoupper($texto)."\n";
    echo $linea."\n";
}

/**
 * Imprime el esquema (DESCRIBE) de una tabla en formato legible.
 */
function describirTabla(string $tabla): void
{
    echo "\n>> Estructura de `$tabla`:\n\n";
    $columnas = DB::select("DESCRIBE `$tabla`");

    if (empty($columnas)) {
        echo "  (La tabla no existe o no tiene columnas)\n";

        return;
    }

    printf("  %-30s %-20s %-8s %-8s %-30s\n",
        'Campo', 'Tipo', 'Null', 'Key', 'Default / Extra');
    echo '  '.str_repeat('-', 100)."\n";

    foreach ($columnas as $col) {
        $default = $col->Default ?? '';
        $extra = trim(($col->Key ?? '').' '.($col->Extra ?? ''));

        printf("  %-30s %-20s %-8s %-8s %-30s\n",
            $col->Field,
            $col->Type,
            $col->Null,
            $col->Key,
            trim($default.' '.$extra)
        );
    }
}

/**
 * Imprime una tabla con N filas de una consulta arbitraria.
 */
function mostrarMuestra(string $sql, array $params = [], int $limite = 10): void
{
    echo "\n>> Muestra de datos (máx $limite filas):\n\n";

    $rows = DB::select($sql, $params);

    if (empty($rows)) {
        echo "  (sin resultados)\n\n";

        return;
    }

    // Cabeceras dinámicas según las claves del primer registro.
    $headers = array_keys((array) $rows[0]);
    $anchos = [];

    foreach ($headers as $h) {
        $max = strlen((string) $h);
        foreach ($rows as $r) {
            $val = (string) ($r->$h ?? 'NULL');
            $max = max($max, min(strlen($val), 40));
        }
        $anchos[$h] = $max;
    }

    // Línea de cabecera.
    $lineaHdr = '  ';
    $sepHdr = '  ';
    foreach ($headers as $h) {
        $lineaHdr .= str_pad($h, $anchos[$h] + 2);
        $sepHdr .= str_repeat('-', $anchos[$h] + 2);
    }
    echo $lineaHdr."\n".$sepHdr."\n";

    foreach ($rows as $r) {
        $linea = '  ';
        foreach ($headers as $h) {
            $val = (string) ($r->$h ?? 'NULL');
            if (strlen($val) > 40) {
                $val = substr($val, 0, 37).'...';
            }
            $linea .= str_pad($val, $anchos[$h] + 2);
        }
        echo $linea."\n";
    }
    echo "\n";
}

/**
 * Reporte de checks booleanos sobre la presencia de columnas.
 */
function checkColumnas(): void
{
    titulo('CHECKS DE COLUMNAS NUEVAS');

    $checks = [
        ['vehiculo', 'placa'],
        ['vehiculo', 'serie_placa'],
        ['vehiculo', 'numero_placa'],
        ['empleado', 'autoriza_ingreso_unidades'],
    ];

    foreach ($checks as [$tabla, $col]) {
        $existe = collect(DB::select(
            "SELECT COLUMN_NAME
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?
             LIMIT 1",
            [$tabla, $col]
        ))->isNotEmpty();

        $estado = $existe ? '[OK]     ' : '[FALTA]  ';
        echo sprintf("  %s %s.%s\n", $estado, $tabla, $col);
    }
}

/**
 * Conteos rápidos.
 */
function conteos(): void
{
    titulo('CONTEOS');

    $tablas = ['vehiculo', 'empleado', 'empresa_transporte', 'marca', 'tipo_vehiculo'];

    foreach ($tablas as $t) {
        try {
            $total = DB::table($t)->count();
            echo sprintf("  %-25s %d filas\n", $t, $total);
        } catch (\Throwable $e) {
            echo sprintf("  %-25s ERROR: %s\n", $t, $e->getMessage());
        }
    }
}

/**
 * Distribución rápida para detectar placas vacías / 'FICT' / etc.
 */
function distribucionPlaca(): void
{
    titulo('DISTRIBUCIÓN EN `vehiculo.placa`');

    try {
        $total = (int) DB::table('vehiculo')->count();
        $nulls = (int) DB::table('vehiculo')->whereNull('placa')->count();
        $fict = (int) DB::table('vehiculo')->where('placa', 'FICT')->count();
        $conDatos = $total - $nulls;
        echo "  Total:                {$total}\n";
        echo "  Placa NULL:           {$nulls}\n";
        echo "  Placa = 'FICT':       {$fict}\n";
        echo "  Con datos:            {$conDatos}\n";
    } catch (\Throwable $e) {
        echo "  (no se pudo consultar `vehiculo.placa`: ".$e->getMessage().")\n";
    }

    // Distribución serie/numero_placa si aún existen
    try {
        $conSerie = (int) DB::table('vehiculo')
            ->whereNotNull('serie_placa')
            ->where('serie_placa', '<>', '')
            ->count();
        echo "  Con serie_placa no vacía: {$conSerie}\n";
    } catch (\Throwable $e) {
        // columna no existe, ignorar
    }
}

// ============================================================================
// EJECUCIÓN
// ============================================================================

titulo('DIAGNÓSTICO DE TABLAS (solo lectura)');
echo ' BD: '.config('database.connections.mysql.database')."\n";
echo ' Host: '.config('database.connections.mysql.host')."\n";
echo ' Fecha: '.date('Y-m-d H:i:s')."\n";

checkColumnas();
conteos();

titulo('TABLA: vehiculo');
describirTabla('vehiculo');

try {
    mostrarMuestra(
        'SELECT id, id_marca, id_empresa_transporte, id_tipo_vehiculo,
                placa,
                numero_constancia_mtc, capacidad, tara, estado
         FROM vehiculo
         ORDER BY id DESC
         LIMIT ?',
        [10]
    );
} catch (\Throwable $e) {
    echo "  No se pudo obtener muestra de vehiculo: ".$e->getMessage()."\n";
}

distribucionPlaca();

titulo('TABLA: empleado');
describirTabla('empleado');

try {
    mostrarMuestra(
        'SELECT id, id_empresa, id_cargo, nombre, apellido, dni,
                autoriza_ingreso_unidades, estado
         FROM empleado
         ORDER BY id DESC
         LIMIT ?',
        [10]
    );
} catch (\Throwable $e) {
    echo "  No se pudo obtener muestra de empleado: ".$e->getMessage()."\n";
}

echo "\n".str_repeat('=', 70)."\n";
echo "FIN DEL DIAGNÓSTICO\n";
echo str_repeat('=', 70)."\n\n";
