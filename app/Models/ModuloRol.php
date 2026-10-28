<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabla pivote de permisos por rol.
 *
 * Un permiso se otorga a una HOJA del arbol de navegacion:
 * - id_modulo  -> permiso de un modulo (vista final)
 * - id_submenu -> permiso de un submenu hoja (vista directa)
 * - id_menu    -> permiso de un menu hoja (vista directa)
 *
 * Los contenedores (es_desplegable = 1) no admiten permisos directos:
 * su presencia en el menu se deriva de los permisos de sus hijos.
 *
 * Cada fila tiene EXACTAMENTE una FK de nodo. Cuando se elimina un nodo del
 * arbol hay que limpiar sus permisos (y los de sus descendientes) para no
 * dejar filas huerfanas apuntando a ids inexistentes.
 */
class ModuloRol extends Model
{
    protected $table = 'modulo_rol';

    public $timestamps = false;

    protected $fillable = [
        'id_menu',
        'id_submenu',
        'id_modulo',
        'id_rol',
    ];

    // ------------------------------------------------------------------
    // Relaciones
    // ------------------------------------------------------------------

    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class, 'id_menu');
    }

    public function submenu(): BelongsTo
    {
        return $this->belongsTo(Submenu::class, 'id_submenu');
    }

    public function modulo(): BelongsTo
    {
        return $this->belongsTo(Modulo::class, 'id_modulo');
    }

    // ------------------------------------------------------------------
    // Limpieza de permisos
    // ------------------------------------------------------------------

    /**
     * Limpia los permisos de un nodo del arbol y los de sus descendientes.
     * - menu    -> permiso directo + submenus + modulos de esos submenus
     * - submenu -> permiso directo + modulos hijos
     * - modulo  -> permiso directo
     *
     * Devuelve la cantidad de filas eliminadas.
     */
    public static function limpiar_permisos_de_nodo(string $tipo_nodo, int $id): int
    {
        return match ($tipo_nodo) {
            'menu' => self::limpiar_permisos_de_menu($id),
            'submenu' => self::limpiar_permisos_de_submenu($id),
            'modulo' => self::limpiar_permisos_de_modulo($id),
            default => 0,
        };
    }

    /**
     * Elimina los permisos del modulo y de sus descendientes (no tiene).
     */
    public static function limpiar_permisos_de_modulo(int $id_modulo): int
    {
        return self::where('id_modulo', $id_modulo)->delete();
    }

    /**
     * Elimina los permisos del submenu (acceso directo) y los de todos sus
     * modulos hijos.
     */
    public static function limpiar_permisos_de_submenu(int $id_submenu): int
    {
        $borrados = self::where('id_submenu', $id_submenu)->delete();

        $borrados += self::whereIn('id_modulo', function ($q) use ($id_submenu) {
            $q->select('id')->from('modulo')->where('id_submenu', $id_submenu);
        })->delete();

        return $borrados;
    }

    /**
     * Elimina los permisos del menu (acceso directo), los de sus submenus y
     * los de los modulos de esos submenus.
     */
    public static function limpiar_permisos_de_menu(int $id_menu): int
    {
        $borrados = self::where('id_menu', $id_menu)->delete();

        $borrados += self::whereIn('id_submenu', function ($q) use ($id_menu) {
            $q->select('id')->from('submenu')->where('id_menu', $id_menu);
        })->delete();

        $borrados += self::whereIn('id_modulo', function ($q) use ($id_menu) {
            $q->select('md.id')
                ->from('modulo as md')
                ->join('submenu as s', 's.id', '=', 'md.id_submenu')
                ->where('s.id_menu', $id_menu);
        })->delete();

        return $borrados;
    }

    /**
     * Elimina los permisos cuya FK apunte a un nodo que ya no existe
     * (huerfanos). Util para limpiar restos de borrados previos.
     */
    public static function eliminar_permisos_huerfanos(): int
    {
        $borrados = self::whereNotNull('id_menu')
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')->from('menu')->whereColumn('menu.id', 'modulo_rol.id_menu');
            })
            ->delete();

        $borrados += self::whereNotNull('id_submenu')
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')->from('submenu')->whereColumn('submenu.id', 'modulo_rol.id_submenu');
            })
            ->delete();

        $borrados += self::whereNotNull('id_modulo')
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')->from('modulo')->whereColumn('modulo.id', 'modulo_rol.id_modulo');
            })
            ->delete();

        return $borrados;
    }
}
