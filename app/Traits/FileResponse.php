<?php

namespace App\Traits;

use Illuminate\Http\Response;

/**
 * Descarga de archivos generados (PDF, CSV). Los errores siguen saliendo
 * en JSON con el contrato de ApiResponse.
 */
trait FileResponse
{
    /**
     * @param array{filename: string, content: string} $file
     */
    protected function fileResponse(array $file, string $contentType): Response
    {
        return response($file['content'], 200, [
            'Content-Type' => $contentType,
            'Content-Disposition' => 'attachment; filename="' . $file['filename'] . '"',
        ]);
    }

    /**
     * Descarga de un archivo subido por un usuario (documentos de siniestros): siempre como adjunto y sin que el
     * navegador adivine el tipo, para que un archivo nunca se ejecute al abrirlo.
     *
     * @param array{filename: string, content: string, mime: string} $file
     */
    protected function storedFileResponse(array $file): Response
    {
        return response($file['content'], 200, [
            'Content-Type' => $file['mime'],
            'Content-Disposition' => 'attachment; filename="' . $file['filename'] . '"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
