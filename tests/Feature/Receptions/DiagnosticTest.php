<?php

namespace Tests\Feature\Receptions;

use App\Models\DiagnosticItemPhoto;
use App\Models\DiagnosticItemTemplate;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

class DiagnosticTest extends ReceptionTestCase
{
    private array $reception;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsAdmin();

        $this->reception = $this->createReception();
    }

    private function diagnosticPayload(array $overrides = []): array
    {
        return [
            'reception_id' => $this->reception['id'],
            'customer_complaint' => 'Ruido al frenar',
            'diagnosis' => 'Pastillas gastadas',
            'recommendation' => 'Cambiar pastillas',
            'priority' => 'high',
            'requires_parts' => true,
            'requires_repair' => true,
            ...$overrides,
        ];
    }

    /**
     * El ítem sale siempre del catálogo: `title` en $overrides elige (o crea) la entrada con ese título.
     */
    private function itemPayload(int $diagnosticId, array $overrides = []): array
    {
        $title = $overrides['title'] ?? 'Pastillas delanteras';
        unset($overrides['title']);

        $template = DiagnosticItemTemplate::firstOrCreate(
            ['title' => $title],
            ['code' => 'DIA-' . Str::upper(Str::random(6)), 'severity' => 'medium']
        );

        return [
            'diagnostic_id' => $diagnosticId,
            'diagnostic_item_template_id' => $template->id,
            'description' => 'Desgaste del 90%',
            'severity' => 'high',
            'status' => 'replace_required',
            'requires_repair' => false,
            'requires_replacement' => true,
            'estimated_cost' => 250000,
            'estimated_time' => 60,
            ...$overrides,
        ];
    }

    public function test_crud_of_diagnostics(): void
    {
        $mechanic = $this->createMechanic();

        $diagnostic = $this->postJson('/api/diagnostics', $this->diagnosticPayload(['mechanic_id' => $mechanic->id]))
            ->assertCreated()
            ->assertJsonPath('data.mechanic_id', $mechanic->id)
            ->json('data');

        $this->assertSame('pending', $diagnostic['status']['value'] ?? $diagnostic['status']);

        $this->getJson('/api/diagnostics')->assertOk()->assertJsonPath('meta.pagination.total', 1);
        $this->getJson("/api/diagnostics/{$diagnostic['id']}")->assertOk()->assertJsonPath('data.reception_id', $this->reception['id']);

        $this->patchJson("/api/diagnostics/{$diagnostic['id']}", ['diagnosis' => 'Pastillas y discos gastados'])
            ->assertOk()
            ->assertJsonPath('data.diagnosis', 'Pastillas y discos gastados');

        $this->deleteJson("/api/diagnostics/{$diagnostic['id']}")->assertOk();
        $this->getJson("/api/diagnostics/{$diagnostic['id']}")->assertNotFound()->assertJsonPath('error_code', 'RESOURCE_NOT_FOUND');
    }

    public function test_diagnostic_validation(): void
    {
        $this->postJson('/api/diagnostics', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reception_id', 'requires_parts', 'requires_repair']);

        $this->postJson('/api/diagnostics', $this->diagnosticPayload(['reception_id' => 999999]))->assertJsonValidationErrors(['reception_id']);
        $this->postJson('/api/diagnostics', $this->diagnosticPayload(['priority' => 'urgente']))->assertJsonValidationErrors(['priority']);
        $this->postJson('/api/diagnostics', $this->diagnosticPayload(['diagnosis' => '<script>']))->assertJsonValidationErrors(['diagnosis']);

        // mechanic_id es un mecánico: un usuario que no es mecánico no se acepta.
        $user = User::create([
            'name' => 'no_mecanico',
            'first_name' => 'Sin',
            'last_name' => 'Legajo',
            'email' => 'no.mecanico@test.local',
            'password' => bcrypt('password'),
        ]);
        $this->assertDatabaseMissing('mechanics', ['id' => $user->id]);

        $this->postJson('/api/diagnostics', $this->diagnosticPayload(['mechanic_id' => $user->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['mechanic_id']);
    }

    public function test_crud_of_diagnostic_items(): void
    {
        $diagnostic = $this->postJson('/api/diagnostics', $this->diagnosticPayload())->json('data');

        $item = $this->postJson('/api/diagnostic-items', $this->itemPayload($diagnostic['id']))
            ->assertCreated()
            ->assertJsonPath('data.title', 'Pastillas delanteras')
            ->assertJsonPath('data.requires_replacement', true)
            ->json('data');

        $this->getJson('/api/diagnostic-items')->assertOk();
        $this->getJson("/api/diagnostic-items/{$item['id']}")->assertOk();
        $this->getJson("/api/diagnostics/{$diagnostic['id']}")->assertOk()->assertJsonCount(1, 'data.items');

        $this->patchJson("/api/diagnostic-items/{$item['id']}", ['severity' => 'critical', 'estimated_cost' => 300000])
            ->assertOk()
            ->assertJsonPath('data.severity', 'critical');

        // El título y el ítem del catálogo quedan fijos.
        $this->patchJson("/api/diagnostic-items/{$item['id']}", ['title' => 'Otro', 'diagnostic_item_template_id' => $item['diagnostic_item_template_id']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['title', 'diagnostic_item_template_id']);

        $this->deleteJson("/api/diagnostic-items/{$item['id']}")->assertOk();
        $this->getJson("/api/diagnostic-items/{$item['id']}")->assertNotFound();
    }

    public function test_diagnostic_resources_expose_flags_and_vehicle_model(): void
    {
        $diagnostic = $this->postJson('/api/diagnostics', $this->diagnosticPayload(['requires_parts' => false]))
            ->assertCreated()
            ->json('data');

        $vehicle = $this->vehicle->load('vehicleModel');

        $this->getJson("/api/diagnostics/{$diagnostic['id']}")
            ->assertOk()
            ->assertJsonPath('data.requires_parts', false)
            ->assertJsonPath('data.requires_repair', true)
            ->assertJsonPath('data.reception.vehicle.model', $vehicle->vehicleModel->name);

        $this->getJson('/api/diagnostics')
            ->assertOk()
            ->assertJsonPath('data.0.reception.vehicle.model', $vehicle->vehicleModel->name)
            ->assertJsonPath('data.0.reception.mileage', 45000)
            ->assertJsonPath('data.0.reception.reception_date', now()->toDateString());
    }

    public function test_optional_fields_can_be_cleared_and_priority_cannot_be_null(): void
    {
        $mechanic = $this->createMechanic();

        // Sin prioridad queda en medium; en null se rechaza (columna NOT NULL).
        $this->postJson('/api/diagnostics', $this->diagnosticPayload(['priority' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['priority']);

        $payload = $this->diagnosticPayload(['mechanic_id' => $mechanic->id]);
        unset($payload['priority']);
        $diagnostic = $this->postJson('/api/diagnostics', $payload)
            ->assertCreated()
            ->assertJsonPath('data.priority', 'medium')
            ->json('data');

        $this->patchJson("/api/diagnostics/{$diagnostic['id']}", [
            'mechanic_id' => null,
            'customer_complaint' => null,
            'diagnosis' => null,
            'recommendation' => null,
        ])
            ->assertOk()
            ->assertJsonPath('data.mechanic_id', null)
            ->assertJsonPath('data.customer_complaint', null)
            ->assertJsonPath('data.diagnosis', null)
            ->assertJsonPath('data.recommendation', null);

        $this->patchJson("/api/diagnostics/{$diagnostic['id']}", ['priority' => null])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['priority']);

        $item = $this->postJson('/api/diagnostic-items', $this->itemPayload($diagnostic['id']))->assertCreated()->json('data');

        $this->patchJson("/api/diagnostic-items/{$item['id']}", ['severity' => null, 'status' => null])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['severity', 'status']);
    }

    public function test_diagnostic_item_validation(): void
    {
        $diagnostic = $this->postJson('/api/diagnostics', $this->diagnosticPayload())->json('data');

        // Sin ítem del catálogo no hay ítem (ya no se escribe el título a mano).
        $this->postJson('/api/diagnostic-items', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['diagnostic_id', 'diagnostic_item_template_id']);

        $this->postJson('/api/diagnostic-items', $this->itemPayload($diagnostic['id'], ['severity' => 'grave']))->assertJsonValidationErrors(['severity']);
        $this->postJson('/api/diagnostic-items', $this->itemPayload($diagnostic['id'], ['status' => 'roto']))->assertJsonValidationErrors(['status']);
        $this->postJson('/api/diagnostic-items', $this->itemPayload($diagnostic['id'], ['estimated_cost' => -1]))->assertJsonValidationErrors(['estimated_cost']);
        $this->postJson('/api/diagnostic-items', [...$this->itemPayload($diagnostic['id']), 'title' => 'Pastillas'])->assertJsonValidationErrors(['title']);
    }

    public function test_diagnostic_item_photos(): void
    {
        Storage::fake('public');

        $diagnostic = $this->postJson('/api/diagnostics', $this->diagnosticPayload())->json('data');
        $item = $this->postJson('/api/diagnostic-items', $this->itemPayload($diagnostic['id']))->json('data');
        $url = "/api/diagnostic-items/{$item['id']}/photos";

        $this->postJson($url, [])->assertStatus(422)->assertJsonValidationErrors(['photos']);

        $photo = $this->postJson($url, ['photos' => [
            ['file' => UploadedFile::fake()->image('pastilla.jpg'), 'description' => 'Pastilla gastada'],
        ]])
            ->assertCreated()
            ->json('data.0');

        $path = DiagnosticItemPhoto::findOrFail($photo['id'])->path;
        Storage::disk('public')->assertExists($path);

        $this->getJson($url)->assertOk()->assertJsonCount(1, 'data');

        $this->patchJson("{$url}/{$photo['id']}", ['description' => 'Pastilla al limite'])
            ->assertOk()
            ->assertJsonPath('data.description', 'Pastilla al limite');
        $this->patchJson("{$url}/{$photo['id']}", [])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'DIAGNOSTIC_ITEM_PHOTO_NOTHING_TO_UPDATE');

        // Foto de otro ítem en la URL.
        $other = $this->postJson('/api/diagnostic-items', $this->itemPayload($diagnostic['id'], ['title' => 'Discos']))->json('data');
        $this->deleteJson("/api/diagnostic-items/{$other['id']}/photos/{$photo['id']}")->assertNotFound();

        // Eliminar el ítem borra sus fotos.
        $this->deleteJson("/api/diagnostic-items/{$item['id']}")->assertOk();
        Storage::disk('public')->assertMissing($path);
    }

    public function test_closed_diagnostics_freeze_items_and_photos(): void
    {
        Storage::fake('public');

        $diagnostic = $this->postJson('/api/diagnostics', $this->diagnosticPayload())->json('data');
        $item = $this->postJson('/api/diagnostic-items', $this->itemPayload($diagnostic['id']))->assertCreated()->json('data');
        $photos = "/api/diagnostic-items/{$item['id']}/photos";
        $photo = $this->postJson($photos, ['photos' => [['file' => UploadedFile::fake()->image('a.jpg'), 'description' => 'Antes']]])->json('data.0');

        foreach (['completed', 'cancelled'] as $status) {
            $this->patchJson("/api/diagnostics/{$diagnostic['id']}", ['status' => $status])->assertOk();

            $this->postJson('/api/diagnostic-items', $this->itemPayload($diagnostic['id'], ['title' => 'Discos']))
                ->assertStatus(422)
                ->assertJsonPath('error_code', 'DIAGNOSTIC_NOT_EDITABLE');
            $this->patchJson("/api/diagnostic-items/{$item['id']}", ['severity' => 'low'])->assertJsonPath('error_code', 'DIAGNOSTIC_NOT_EDITABLE');
            $this->deleteJson("/api/diagnostic-items/{$item['id']}")->assertJsonPath('error_code', 'DIAGNOSTIC_NOT_EDITABLE');
            $this->postJson($photos, ['photos' => [['file' => UploadedFile::fake()->image('b.jpg')]]])->assertJsonPath('error_code', 'DIAGNOSTIC_NOT_EDITABLE');
            $this->patchJson("{$photos}/{$photo['id']}", ['description' => 'Después'])->assertJsonPath('error_code', 'DIAGNOSTIC_NOT_EDITABLE');
            $this->deleteJson("{$photos}/{$photo['id']}")->assertJsonPath('error_code', 'DIAGNOSTIC_NOT_EDITABLE');
        }

        // Vuelto a "en proceso", se corrige.
        $this->patchJson("/api/diagnostics/{$diagnostic['id']}", ['status' => 'in_progress'])->assertOk();
        $this->patchJson("/api/diagnostic-items/{$item['id']}", ['severity' => 'low'])->assertOk()->assertJsonPath('data.severity', 'low');
    }

    public function test_diagnostics_of_a_closed_reception_cannot_change(): void
    {
        $diagnostic = $this->postJson('/api/diagnostics', $this->diagnosticPayload())->json('data');
        $item = $this->postJson('/api/diagnostic-items', $this->itemPayload($diagnostic['id']))->json('data');

        $this->postJson("/api/receptions/{$this->reception['id']}/cancel", ['reason' => 'El cliente desistió'])->assertOk();

        $this->patchJson("/api/diagnostics/{$diagnostic['id']}", ['diagnosis' => 'Otro'])->assertStatus(422)->assertJsonPath('error_code', 'RECEPTION_CLOSED');
        $this->deleteJson("/api/diagnostics/{$diagnostic['id']}")->assertJsonPath('error_code', 'RECEPTION_CLOSED');
        $this->patchJson("/api/diagnostic-items/{$item['id']}", ['severity' => 'low'])->assertJsonPath('error_code', 'RECEPTION_CLOSED');
        $this->postJson('/api/diagnostic-items', $this->itemPayload($diagnostic['id'], ['title' => 'Discos']))->assertJsonPath('error_code', 'RECEPTION_CLOSED');
    }

    public function test_diagnostics_are_listed_by_diagnosis_date(): void
    {
        $older = $this->postJson('/api/diagnostics', $this->diagnosticPayload())->json('data.id');
        $newer = $this->postJson('/api/diagnostics', $this->diagnosticPayload())->json('data.id');

        // Cargados en otro orden que el de diagnóstico.
        \App\Models\Diagnostic::whereKey($older)->update(['diagnosed_at' => now()->subDays(5)]);
        \App\Models\Diagnostic::whereKey($newer)->update(['diagnosed_at' => now()->subDays(10)]);

        $ids = collect($this->getJson("/api/diagnostics?reception_id={$this->reception['id']}")->assertOk()->json('data'))->pluck('id')->all();

        $this->assertSame([$older, $newer], $ids);
    }

    public function test_diagnostic_permissions(): void
    {
        $diagnostic = $this->postJson('/api/diagnostics', $this->diagnosticPayload())->json('data');
        $item = $this->postJson('/api/diagnostic-items', $this->itemPayload($diagnostic['id']))->json('data');

        $this->actingAsMechanic();

        $this->getJson('/api/diagnostics')->assertOk();
        $this->postJson('/api/diagnostics', $this->diagnosticPayload())->assertCreated();
        $this->deleteJson("/api/diagnostics/{$diagnostic['id']}")->assertForbidden()->assertJsonPath('error_code', 'FORBIDDEN');
        $this->getJson("/api/diagnostic-items/{$item['id']}/photos")->assertOk();

        // Un usuario sin permisos no accede a nada del diagnóstico.
        Sanctum::actingAs(User::create([
            'name' => 'sin_rol',
            'first_name' => 'Sin',
            'last_name' => 'Rol',
            'email' => 'sin.rol@test.local',
            'password' => bcrypt('password'),
        ]));

        $this->getJson('/api/diagnostics')->assertForbidden();
        $this->getJson("/api/diagnostic-items/{$item['id']}/photos")->assertForbidden()->assertJsonPath('error_code', 'FORBIDDEN');
        $this->postJson("/api/diagnostic-items/{$item['id']}/photos", [])->assertForbidden();
    }
}
