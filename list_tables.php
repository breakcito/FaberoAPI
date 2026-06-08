<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->handle(Illuminate\Http\Request::capture());

use Illuminate\Support\Facades\DB;

try {
    $tables = DB::select('SHOW TABLES');
    foreach ($tables as $table) {
        print_r($table);
    }
} catch (\Exception $e) {
    echo $e->getMessage()."\n";
}
