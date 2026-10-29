<?php

namespace Tests\Feature\Claims;

use App\Enums\ClaimStatus;
use App\Models\AuditLog;
use App\Models\Claim;
use App\Models\ClaimDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Documentos privados de siniestros (M4, decisión E): validación del archivo,
 * almacenamiento con nombre interno y sha256, descarga auditada, baja lógica,
 * aislamiento por siniestro y permisos.
 */
class ClaimDocumentTest extends ClaimTestCase
{
    /** PNG de 1×1 px. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('claim_documents');
    }

    private function claim(): Claim
    {
        $this->actingAsAdmin();
        $id = $this->postJson('/api/claims', $this->claimBody($this->openReception()->id, $this->insurer()))->json('data.id');

        return Claim::findOrFail($id);
    }

    /**
     * Archivo subido real (no UploadedFile::fake(), que informa el tipo según la extensión del nombre): el tipo MIME
     * sale del contenido, como en producción.
     */
    private function file(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'claimdoc');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function pdf(string $name = 'denuncia.pdf'): UploadedFile
    {
        return $this->file($name, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << >>\n%%EOF\n");
    }

    private function png(string $name = 'foto.png'): UploadedFile
    {
        return $this->file($name, base64_decode(self::PNG));
    }

    private function upload(Claim $claim, UploadedFile $file, string $type = 'loss_report')
    {
        return $this->post("/api/claims/{$claim->id}/documents", ['file' => $file, 'type' => $type, 'notes' => 'Original.'], ['Accept' => 'application/json']);
    }

    public function test_a_document_is_stored_privately_with_an_internal_name(): void
    {
        $claim = $this->claim();
        $file = $this->pdf();

        $data = $this->upload($claim, $file)
            ->assertCreated()
            ->assertJsonPath('data.type.label', 'Denuncia')
            ->assertJsonPath('data.original_name', 'denuncia.pdf')
            ->assertJsonPath('data.mime', 'application/pdf')
            ->assertJsonPath('data.sha256', hash_file('sha256', $file->getRealPath()))
            ->assertJsonMissingPath('data.path')
            ->json('data');

        $document = ClaimDocument::findOrFail($data['id']);
        $this->assertMatchesRegularExpression("#^claims/{$claim->id}/[0-9a-f-]{36}\\.pdf$#", $document->path);
        Storage::disk('claim_documents')->assertExists($document->path);

        // La ruta interna no sale ni en la auditoría.
        $created = AuditLog::where('auditable_type', 'claim_document')->where('event', 'created')->sole();
        $this->assertArrayNotHasKey('path', $created->new_values ?? []);

        $this->upload($claim, $this->png(), 'photo')->assertCreated()->assertJsonPath('data.mime', 'image/png');
        $this->getJson("/api/claims/{$claim->id}/documents")->assertOk()->assertJsonCount(2, 'data')->assertJsonMissingPath('data.0.path');
    }

    public function test_dangerous_or_mismatched_files_are_rejected(): void
    {
        $claim = $this->claim();

        $svg = $this->file('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $html = $this->file('denuncia.pdf', '<html><body><script>alert(1)</script></body></html>');

        foreach ([$svg, $html, $this->png('foto.pdf'), $this->pdf('denuncia.png')] as $file) {
            $this->upload($claim, $file)->assertStatus(422)->assertJsonValidationErrors('file');
        }

        $this->upload($claim, UploadedFile::fake()->create('grande.pdf', 10241, 'application/pdf'))
            ->assertStatus(422)->assertJsonValidationErrors('file');
        $this->upload($claim, $this->pdf(), 'otro')->assertStatus(422)->assertJsonValidationErrors('type');

        $this->assertSame(0, ClaimDocument::count());
        $this->assertSame([], Storage::disk('claim_documents')->allFiles());
    }

    public function test_the_download_is_an_audited_attachment(): void
    {
        $claim = $this->claim();
        $id = $this->upload($claim, $this->pdf('denuncia "final".pdf'))->json('data.id');

        $response = $this->get("/api/claims/{$claim->id}/documents/{$id}/download")->assertOk();

        $this->assertStringStartsWith('%PDF-1.4', $response->getContent());
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('attachment; filename="denuncia final.pdf"', $response->headers->get('Content-Disposition'));

        $log = AuditLog::where('event', 'downloaded')->sole();
        $this->assertSame('claim_document', $log->auditable_type);
        $this->assertSame($id, $log->auditable_id);
        $this->assertSame($claim->id, $log->new_values['claim_id']);
    }

    public function test_a_deleted_document_is_no_longer_available_but_the_file_is_kept(): void
    {
        $claim = $this->claim();
        $id = $this->upload($claim, $this->pdf())->json('data.id');
        $path = ClaimDocument::findOrFail($id)->path;

        $this->actingAsRole('recepcionista');
        $this->deleteJson("/api/claims/{$claim->id}/documents/{$id}", ['reason' => 'Duplicado.'])->assertForbidden();

        $this->actingAsAdmin();
        $this->deleteJson("/api/claims/{$claim->id}/documents/{$id}", [])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->deleteJson("/api/claims/{$claim->id}/documents/{$id}", ['reason' => 'Duplicado.'])->assertOk();

        $this->get("/api/claims/{$claim->id}/documents/{$id}/download", ['Accept' => 'application/json'])->assertNotFound();
        $this->getJson("/api/claims/{$claim->id}/documents")->assertOk()->assertJsonCount(0, 'data');
        Storage::disk('claim_documents')->assertExists($path);
        $this->assertSame('Duplicado.', ClaimDocument::withTrashed()->findOrFail($id)->deletion_reason);
    }

    public function test_documents_belong_to_their_claim_and_follow_its_status(): void
    {
        $claim = $this->claim();
        $id = $this->upload($claim, $this->pdf())->json('data.id');
        $other = $this->claim();

        $this->get("/api/claims/{$other->id}/documents/{$id}/download", ['Accept' => 'application/json'])->assertNotFound();

        // Cerrado (entregado): se siguen aceptando documentos. Cancelado: no.
        $other->update(['status' => ClaimStatus::CLOSED]);
        $this->upload($other, $this->pdf(), 'adjuster_report')->assertCreated();

        $this->postJson("/api/claims/{$claim->id}/cancel", ['reason' => 'Error de carga.'])->assertOk();
        $this->upload($claim, $this->pdf())->assertStatus(422)->assertJsonPath('error_code', 'CLAIM_NOT_EDITABLE');
    }

    public function test_permissions_by_role(): void
    {
        $claim = $this->claim();
        $id = $this->upload($claim, $this->pdf())->json('data.id');

        $this->actingAsRole('jefe-taller');
        $this->upload($claim, $this->png(), 'photo')->assertCreated();

        $this->actingAsRole('cajero');
        $this->getJson("/api/claims/{$claim->id}/documents")->assertOk();
        $this->get("/api/claims/{$claim->id}/documents/{$id}/download")->assertOk();
        $this->upload($claim, $this->pdf())->assertForbidden();

        $this->actingAsMechanic();
        $this->getJson("/api/claims/{$claim->id}/documents")->assertForbidden();
        $this->get("/api/claims/{$claim->id}/documents/{$id}/download", ['Accept' => 'application/json'])->assertForbidden();

        $this->actingAsRole('deposito');
        $this->getJson("/api/claims/{$claim->id}/documents")->assertForbidden();
    }
}
