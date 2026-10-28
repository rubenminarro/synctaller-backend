<?php

namespace Tests\Feature\Documents;

use App\Enums\DocumentSeries;
use App\Exceptions\AppException;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Services\DocumentSequenceService;
use App\Services\ServiceCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Códigos internos por serie (E3): contador bloqueado por serie, continuidad con
 * los datos existentes, rollback, salto de códigos usados y 409 ante un duplicado.
 * La concurrencia real solo se reproduce en MySQL (tests/Manual/document_sequence_concurrency.php).
 */
class DocumentSequenceTest extends TestCase
{
    use RefreshDatabase;

    private ServiceCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = ServiceCategory::create(['code' => 'mech', 'name' => 'Mecánica']);
    }

    private function sequences(): DocumentSequenceService
    {
        return app(DocumentSequenceService::class);
    }

    private function createService(): Service
    {
        return app(ServiceCatalogService::class)->create([
            'service_category_id' => $this->category->id,
            'name' => 'Servicio',
            'standard_price' => 100000,
        ]);
    }

    /**
     * Servicio cargado sin pasar por la serie (datos existentes o escritos a mano).
     */
    private function insertService(int $id, string $code): void
    {
        DB::table('services')->insert([
            'id' => $id,
            'code' => $code,
            'service_category_id' => $this->category->id,
            'name' => "Servicio {$code}",
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_codes_keep_their_format_and_are_consecutive(): void
    {
        $this->assertSame('SRV-000001', $this->createService()->code);
        $this->assertSame('SRV-000002', $this->createService()->code);
        $this->assertSame(3, DB::table('document_sequences')->where('key', 'service')->value('next_value'));
    }

    public function test_first_use_continues_after_the_existing_records(): void
    {
        // Datos anteriores a la serie, incluido uno eliminado: sigue el máximo id + 1, como antes.
        $this->insertService(7, 'SRV-000007');
        Service::findOrFail(7)->delete();

        $this->assertSame('SRV-000008', $this->createService()->code);
    }

    public function test_codes_already_in_use_are_skipped(): void
    {
        $this->assertSame('SRV-000001', $this->createService()->code);

        $this->insertService(50, 'SRV-000002');
        $this->insertService(51, 'SRV-000003');

        $this->assertSame('SRV-000004', $this->createService()->code);
    }

    public function test_a_failed_creation_does_not_consume_the_code(): void
    {
        $this->assertSame('SRV-000001', $this->createService()->code);

        try {
            DB::transaction(fn () => $this->sequences()->create(
                DocumentSeries::SERVICE,
                fn (string $code) => throw new RuntimeException("Alta fallida con {$code}")
            ));
            $this->fail('El alta debía fallar.');
        } catch (RuntimeException $e) {
            $this->assertSame('Alta fallida con SRV-000002', $e->getMessage());
        }

        $this->assertSame('SRV-000002', $this->createService()->code);
    }

    public function test_series_are_independent(): void
    {
        $this->createService();
        $this->createService();

        $this->assertSame('REP-000001', $this->sequences()->next(DocumentSeries::PART));
        $this->assertSame('SRV-000003', $this->createService()->code);
    }

    public function test_a_duplicated_code_responds_409_instead_of_500(): void
    {
        $this->createService();

        try {
            // Ignora el código de la serie y usa uno ya existente.
            $this->sequences()->create(DocumentSeries::SERVICE, fn () => Service::create([
                'service_category_id' => $this->category->id,
                'name' => 'Duplicado',
                'code' => 'SRV-000001',
            ]));
            $this->fail('Debía rechazar el código repetido.');
        } catch (AppException $e) {
            $this->assertSame(409, $e->getStatus());
            $this->assertSame('DOCUMENT_CODE_CONFLICT', $e->getErrorCode());
        }
    }
}
