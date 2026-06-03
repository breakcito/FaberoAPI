<?php

namespace App\Shared\Helpers;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class ArchivoHelper
{
    /**
     * Guarda un array de archivos de forma segura en el disco público.
     *
     * @param  string  $carpetaDestino  Carpeta dentro de storage/app/public (ej. "pagos").
     * @param  UploadedFile[]  $archivos  Archivos recibidos en el request.
     * @return array Retorna: { url (URL pública), nombre_original (nombre original del archivo), extension (docx, jpg, etc) }
     * 
     * Comando de ayuda: php artisan storage:link
     */
    public static function guardarArchivos(string $carpetaDestino, array $archivos): array
    {
        $resultados = [];

        // Agrupamos los archivos en subcarpetas por fecha (ej. "pagos/28-02-26")
        $rutaDestino = trim($carpetaDestino, '/') . '/' . date('d-m-y');

        foreach ($archivos as $archivo) {
            if (!$archivo instanceof UploadedFile || !$archivo->isValid()) {
                continue;
            }

            // Extraemos el nombre original y lo limpiamos
            $nombreOriginal = pathinfo($archivo->getClientOriginalName(), PATHINFO_FILENAME);
            $nombreLimpio = Str::slug($nombreOriginal);
            $extension = $archivo->getClientOriginalExtension() ?: ($archivo->guessExtension() ?? 'bin');

            // Usamos store() en el disco 'public'
            $pathRelativo = $archivo->store($rutaDestino, ['disk' => 'public']);

            if ($pathRelativo) {
                $resultados[] = [
                    'url' => asset('storage/' . $pathRelativo),
                    'path_relativo' => $pathRelativo,
                    'nombre_original' => $nombreLimpio,
                    'extension' => $extension,
                ];
            }
        }

        return $resultados;
    }
}
