<?php

namespace App\Controllers;

use App\Shared\Responses\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Response;

class ArchivoController extends Controller
{
    /**
     * Endpoint para descarga de archivos.
     */
    public function download_archivo(Request $request): Response
    {
        $pathRelativo = $request->input('path_relativo');

        if (!$pathRelativo) {
            return response()->json(ApiResponse::error('Ruta de archivo (path_relativo) requerida'), 400);
        }

        $fullPath = storage_path('app/public/' . ltrim($pathRelativo, '/'));

        if (!file_exists($fullPath)) {
            return response()->json(ApiResponse::error('Archivo no encontrado en el servidor'), 404);
        }

        return response()->download($fullPath);
    }

    /**
     * Sirve una imagen del storage con headers CORS correctos.
     * Usado por react-pdf para cargar logos de empresas en PDFs.
     */
    public function serve_imagen(Request $request, string $path): Response
    {
        $fullPath = storage_path('app/public/' . ltrim($path, '/'));

        if (!file_exists($fullPath)) {
            return response()->json(['error' => 'Imagen no encontrada'], 404);
        }

        // Detectar MIME por extensión (más confiable en Windows que mime_content_type)
        $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
        $mimeType = match ($ext) {
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
            'svg'  => 'image/svg+xml',
            default => 'image/jpeg',
        };

        $contenido = file_get_contents($fullPath);

        return response($contenido, 200, [
            'Content-Type'                => $mimeType,
            'Content-Length'              => strlen($contenido),
            'Access-Control-Allow-Origin' => '*',
            'Cache-Control'               => 'public, max-age=3600',
        ]);
    }
}
