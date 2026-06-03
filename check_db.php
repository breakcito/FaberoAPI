<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

try {
    $columns = DB::select("DESCRIBE prestamo_almacen_detalle_log");
    echo json_encode($columns, JSON_PRETTY_PRINT);
} catch (\Exception $e) {
    echo $e->getMessage();
}
