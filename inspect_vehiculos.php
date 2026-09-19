<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
use Illuminate\Support\Facades\DB;

echo "=== Sin filtro (JOIN a tipo_vehiculo para ver es_carreta) ===\n";
$rows = DB::select('SELECT v.id, v.placa, tv.es_carreta, tv.nombre AS tipo FROM vehiculo v LEFT JOIN tipo_vehiculo tv ON tv.id = v.id_tipo_vehiculo ORDER BY v.id');
foreach ($rows as $r) {
    echo sprintf("id=%d placa=%s es_carreta=%s tipo=%s\n", $r->id, $r->placa, var_export($r->es_carreta, true), $r->tipo);
}

echo "\n=== VehiculosData::get_vehiculos() sin filtro ===\n";
$rows1 = App\Modules\Vehiculos\Data\VehiculosData::get_vehiculos();
echo "Total: " . count($rows1) . "\n";

echo "\n=== VehiculosData::get_vehiculos(null, true) solo_no_carreta ===\n";
$rows2 = App\Modules\Vehiculos\Data\VehiculosData::get_vehiculos(null, true);
echo "Total: " . count($rows2) . "\n";
foreach ($rows2 as $r) {
    echo sprintf("id=%d placa=%s es_carreta=%s\n", $r->id, $r->placa, var_export($r->es_carreta, true));
}

echo "\n=== Via servicio ===\n";
$res = App\Modules\Vehiculos\Services\VehiculosService::get_vehiculos(true);
print_r($res);
