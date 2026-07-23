<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->handle(Illuminate\Http\Request::capture());

use Illuminate\Support\Facades\DB;

$tables = ['ticket_balanza', 'lote_mineral'];

foreach ($tables as $table) {
    echo "=== TABLE: $table ===\n";
    try {
        $cols = DB::select("DESCRIBE $table");
        foreach ($cols as $col) {
            echo "Field: {$col->Field} | Type: {$col->Type} | Null: {$col->Null} | Key: {$col->Key} | Default: {$col->Default} | Extra: {$col->Extra}\n";
        }
    } catch (\Exception $e) {
        echo 'Error: '.$e->getMessage()."\n";
    }
    echo "\n";
}
