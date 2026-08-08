<?php

namespace App\Modules\VisitaVehiculo\Data;

use App\Shared\Enums\_Generic\EstadoVisita;
use Illuminate\Support\Facades\DB;

class VisitaVehiculoData
{
    /**
     * Listar vehículos acompañantes de una visita.
     */
    public static function get_visitas_vehiculo(int $idRecepcionVisita): array
    {
        $rows = DB::select('
            SELECT
                id, id_recepcion_visita, placa, cantidad_personas, url_foto, created_at
            FROM visita_vehiculo
            WHERE id_recepcion_visita = :id
            ORDER BY id ASC
        ', ['id' => $idRecepcionVisita]);

        foreach ($rows as $r) {
            $r->url_foto = $r->url_foto ? json_decode($r->url_foto, true) : null;
        }

        return $rows;
    }

    /**
     * Obtener un vehículo visitante por ID.
     */
    public static function get_visita_vehiculo_by_id(int $id): ?array
    {
        $row = DB::selectOne('
            SELECT
                id, id_recepcion_visita, placa, cantidad_personas, url_foto, created_at
            FROM visita_vehiculo
            WHERE id = :id
            LIMIT 1
        ', ['id' => $id]);

        if (! $row) {
            return null;
        }

        $row->url_foto = $row->url_foto ? json_decode($row->url_foto, true) : null;

        return (array) $row;
    }

    /**
     * Crear un vehículo visitante. Si se pasa $cantidadPersonas > 0,
     * también genera $cantidadPersonas filas en recepcion_visita_detalle
     * con es_conductor=1 en la primera y 0 en las demás.
     *
     * @param  array  $visitantes  Lista opcional con datos por persona para crear visitantes en el mismo flujo.
     *                             Cada item: ['nombre','apellido','dni','telefono','url_foto_documento'(array)].
     *                             Si la longitud no coincide con $cantidadPersonas, se generan
     *                             visitantes vacíos que se llenan después.
     */
    public static function crear_visita_vehiculo(array $data, int $cantidadPersonas = 0, array $visitantes = []): int
    {
        return DB::transaction(function () use ($data, $cantidadPersonas, $visitantes) {
            $urlFoto = $data['url_foto'] ?? null;
            $urlFotoJson = is_array($urlFoto) ? json_encode($urlFoto) : $urlFoto;

            $id = DB::table('visita_vehiculo')->insertGetId([
                'id_recepcion_visita' => (int) $data['id_recepcion_visita'],
                'placa' => $data['placa'],
                'cantidad_personas' => $cantidadPersonas > 0 ? $cantidadPersonas : 1,
                'url_foto' => $urlFotoJson,
                'created_at' => now()->toDateTimeString(),
            ]);

            // Generar detalles (visitantes) automáticamente
            $total = max($cantidadPersonas, 1);
            for ($i = 0; $i < $total; $i++) {
                $esConductor = $i === 0;
                $v = $visitantes[$i] ?? [];
                $idVisitante = self::obtenerOCrearVisitante($v);

                $urlFotoDoc = $v['url_foto_documento'] ?? null;
                $urlFotoDocJson = is_array($urlFotoDoc) ? json_encode($urlFotoDoc) : $urlFotoDoc;

                DB::table('recepcion_visita_detalle')->insert([
                    'id_recepcion_visita' => (int) $data['id_recepcion_visita'],
                    'id_visitante' => $idVisitante,
                    'id_visita_vehiculo' => $id,
                    'es_conductor' => $esConductor ? 1 : 0,
                    'url_foto_documento' => $urlFotoDocJson,
                    'estado' => EstadoVisita::EnPlanta->value,
                ]);
            }

            return $id;
        });
    }

    /**
     * Eliminar un vehículo visitante y sus detalles asociados.
     */
    public static function eliminar_visita_vehiculo(int $id): bool
    {
        return DB::transaction(function () use ($id) {
            DB::table('recepcion_visita_detalle')
                ->where('id_visita_vehiculo', $id)
                ->delete();

            return DB::table('visita_vehiculo')->where('id', $id)->delete() >= 0;
        });
    }

    /**
     * Obtiene un visitante existente por DNI o crea uno nuevo.
     *
     * @param  array  $datos  ['nombre','apellido','dni','telefono']
     */
    private static function obtenerOCrearVisitante(array $datos): int
    {
        $dni = $datos['dni'] ?? null;
        $nombre = $datos['nombre'] ?? '';
        $apellido = $datos['apellido'] ?? '';
        $telefono = $datos['telefono'] ?? null;

        if (! empty($dni)) {
            $existing = DB::selectOne(
                'SELECT id FROM visitante WHERE dni = :dni LIMIT 1',
                ['dni' => $dni]
            );
            if ($existing) {
                DB::table('visitante')->where('id', $existing->id)->update([
                    'nombre' => $nombre ?: '',
                    'apellido' => $apellido,
                    'telefono' => $telefono,
                ]);

                return (int) $existing->id;
            }
        }

        return DB::table('visitante')->insertGetId([
            'nombre' => $nombre ?: '',
            'apellido' => $apellido,
            'dni' => $dni,
            'telefono' => $telefono,
            'created_at' => now()->toDateTimeString(),
        ]);
    }
}
