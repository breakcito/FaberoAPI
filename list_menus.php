<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->handle(Illuminate\Http\Request::capture());

use App\Models\Menu;
use App\Models\Modulo;
use App\Models\Submenu;

try {
    echo "=== MENUS ===\n";
    foreach (Menu::all() as $m) {
        echo "ID: {$m->id} | Nombre: {$m->nombre} | Path: {$m->path} | Estado: {$m->estado}\n";
    }

    echo "\n=== SUBMENUS ===\n";
    foreach (Submenu::all() as $s) {
        echo "ID: {$s->id} | Menu ID: {$s->id_menu} | Nombre: {$s->nombre} | Path: {$s->path} | Estado: {$s->estado}\n";
    }

    echo "\n=== MODULOS ===\n";
    foreach (Modulo::all() as $mo) {
        echo "ID: {$mo->id} | Submenu ID: {$mo->id_submenu} | Nombre: {$mo->nombre} | Path: {$mo->path} | Estado: {$mo->estado}\n";
    }
} catch (\Exception $e) {
    echo $e->getMessage()."\n";
}
