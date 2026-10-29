<?php

namespace App\Services;

use App\Enums\AuditEvent;
use App\Enums\ClaimStatus;
use App\Exceptions\ClaimException;
use App\Models\Claim;
use App\Models\ClaimDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Documentos privados de siniestros (M4, decisión E). Disco claim_documents sin
 * URL: el archivo se guarda con un nombre interno (UUID) y su sha256, se
 * descarga solo por la API (cada descarga queda auditada) y la baja es lógica
 * (el archivo se conserva). Las rutas se arman solo acá.
 */
class ClaimDocumentService
{
    public const DISK = 'claim_documents';

    public function __construct(
        protected AuditLogger $auditLogger
    ) {
    }

    /**
     * Ruta interna del archivo. T4 (storage por taller) cambiará solo la raíz del disco.
     */
    public static function pathFor(Claim $claim, string $fileName): string
    {
        return "claims/{$claim->id}/{$fileName}";
    }

    public function store(Claim $claim, UploadedFile $file, array $data, User $user): ClaimDocument
    {
        // Extensión según el tipo real del contenido (no la del nombre que mandó el cliente).
        $extension = $file->extension() === 'jpeg' ? 'jpg' : $file->extension();
        $path = self::pathFor($claim, Str::uuid() . ".{$extension}");
        $stored = false;

        try {
            return DB::transaction(function () use ($claim, $file, $data, $user, $path, &$stored) {

                $claim = Claim::whereKey($claim->id)->lockForUpdate()->firstOrFail();

                if ($claim->status === ClaimStatus::CANCELLED) {
                    throw new ClaimException(
                        'El siniestro está cancelado y no admite documentos nuevos.',
                        422,
                        'CLAIM_NOT_EDITABLE'
                    );
                }

                Storage::disk(self::DISK)->put($path, $file->get());
                $stored = true;

                return ClaimDocument::create([
                    'claim_id' => $claim->id,
                    'type' => $data['type'],
                    'original_name' => Str::limit($file->getClientOriginalName(), 250, ''),
                    'path' => $path,
                    'mime' => $file->getMimeType(),
                    'size' => $file->getSize(),
                    'sha256' => hash_file('sha256', $file->getRealPath()),
                    'notes' => $data['notes'] ?? null,
                    'uploaded_by' => $user->id,
                ])->load('uploader');
            });
        } catch (Throwable $e) {
            // Si el registro no se guardó, no queda un archivo huérfano.
            if ($stored) {
                Storage::disk(self::DISK)->delete($path);
            }

            throw $e;
        }
    }

    /**
     * Contenido para descargar. Deja el registro `downloaded` en la auditoría.
     *
     * @return array{filename: string, content: string, mime: string}
     */
    public function download(ClaimDocument $document, User $user): array
    {
        $disk = Storage::disk(self::DISK);

        if (!$disk->exists($document->path)) {
            throw new ClaimException(
                'El archivo del documento no está disponible.',
                404,
                'CLAIM_DOCUMENT_FILE_MISSING'
            );
        }

        $this->auditLogger->record(AuditEvent::DOWNLOADED, $document, null, [
            'claim_id' => $document->claim_id,
            'type' => $document->type->value,
            'original_name' => $document->original_name,
        ], $user);

        return [
            'filename' => self::safeFileName($document->original_name),
            'content' => $disk->get($document->path),
            'mime' => $document->mime,
        ];
    }

    /**
     * Baja lógica con motivo. El archivo se conserva (retención legal pendiente, G10) y deja de poder descargarse.
     */
    public function delete(ClaimDocument $document, string $reason, User $user): void
    {
        DB::transaction(function () use ($document, $reason, $user) {
            $document = ClaimDocument::whereKey($document->id)->lockForUpdate()->firstOrFail();

            // La evidencia de una autorización no se da de baja (M6).
            if ($document->insurer_authorization_id !== null) {
                throw new ClaimException(
                    'El documento respalda una autorización de la aseguradora y no puede darse de baja.',
                    409,
                    'CLAIM_DOCUMENT_IN_USE'
                );
            }

            $document->update(['deleted_by' => $user->id, 'deletion_reason' => $reason]);
            $document->delete();
        });
    }

    /**
     * Nombre para Content-Disposition: sin comillas, barras ni caracteres de control.
     */
    public static function safeFileName(string $name): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F"\\\\\/]+/u', '', $name);

        return trim($name) !== '' ? trim($name) : 'documento';
    }
}
