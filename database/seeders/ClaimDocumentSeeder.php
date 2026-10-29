<?php

namespace Database\Seeders;

use App\Models\Claim;
use App\Services\ClaimDocumentService;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;

/**
 * Documentos de ejemplo (M4) del siniestro abierto (C07): la denuncia en PDF y
 * una foto, por ClaimDocumentService con el recepcionista del escenario.
 * Archivos chicos generados acá (en el disco claim_documents). Idempotente:
 * omite el siniestro que ya tiene documentos.
 */
class ClaimDocumentSeeder extends Seeder
{
    /** PNG de 1×1 px. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    public function run(ClaimDocumentService $documentService): void
    {
        $scenario = collect(ExampleScenarios::all())->firstWhere('key', 'C07');
        $claim = Claim::where('reception_id', ExampleScenarios::reception($scenario)?->id)->first();

        if (!$claim || $claim->documents()->withTrashed()->exists()) {
            return;
        }

        $user = ExampleScenarios::user($scenario['receptionist']);
        $documents = [
            ['denuncia-SDS-2026-0457.pdf', "%PDF-1.4\n% Denuncia del siniestro (ejemplo)\n%%EOF\n", 'loss_report', 'Denuncia presentada por el cliente.'],
            ['paragolpes.png', base64_decode(self::PNG), 'photo', 'Foto del daño al ingreso.'],
        ];

        ExampleScenarios::travel(ExampleScenarios::at($scenario, 0, 12), function () use ($documents, $documentService, $claim, $user) {
            foreach ($documents as [$name, $content, $type, $notes]) {
                $path = tempnam(sys_get_temp_dir(), 'seed');
                file_put_contents($path, $content);

                $documentService->store($claim, new UploadedFile($path, $name, null, null, true), ['type' => $type, 'notes' => $notes], $user);

                @unlink($path);
            }
        });
    }
}
