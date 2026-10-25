<?php

namespace Tests\Feature\Receptions;

use App\Models\ServiceCategory;

class DiagnosticItemTemplateTest extends ReceptionTestCase
{
    private function templatePayload(array $overrides = []): array
    {
        return [
            'title' => 'Pastillas de freno delanteras',
            'description' => 'Medir el espesor de las pastillas.',
            'severity' => 'high',
            'requires_repair' => false,
            'requires_replacement' => true,
            'estimated_time' => 60,
            'recommendation' => 'Reemplazar las pastillas.',
            ...$overrides,
        ];
    }

    private function createTemplate(array $overrides = []): array
    {
        return $this->postJson('/api/diagnostic-item-templates', $this->templatePayload($overrides))
            ->assertCreated()
            ->json('data');
    }

    private function createDiagnostic(): array
    {
        return $this->postJson('/api/diagnostics', [
            'reception_id' => $this->createReception()['id'],
            'customer_complaint' => 'Ruido al frenar',
            'priority' => 'high',
            'requires_parts' => true,
            'requires_repair' => false,
        ])->assertCreated()->json('data');
    }

    public function test_admin_can_manage_the_catalog(): void
    {
        $this->actingAsAdmin();

        $category = ServiceCategory::create(['code' => 'brakes', 'name' => 'Frenos']);

        $template = $this->createTemplate(['service_category_id' => $category->id]);
        $this->createTemplate(['title' => 'Batería', 'severity' => 'medium']);

        $this->assertMatchesRegularExpression('/^DIA-\d{6}$/', $template['code']);
        $this->assertSame('active', $template['status']['value']);
        $this->assertSame('Frenos', $template['category']['name']);

        $this->getJson('/api/diagnostic-item-templates?search=pastillas')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/diagnostic-item-templates?category_id={$category->id}")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/diagnostic-item-templates')->assertOk()->assertJsonPath('data.0.title', 'Batería');

        $this->patchJson("/api/diagnostic-item-templates/{$template['id']}", ['estimated_time' => 45, 'service_category_id' => null])
            ->assertOk()
            ->assertJsonPath('data.estimated_time', 45)
            ->assertJsonPath('data.service_category_id', null)
            ->assertJsonPath('data.title', 'Pastillas de freno delanteras');

        $this->postJson("/api/diagnostic-item-templates/{$template['id']}/deactivate")->assertOk()->assertJsonPath('data.status.value', 'inactive');
        $this->postJson("/api/diagnostic-item-templates/{$template['id']}/deactivate")
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'DIAGNOSTIC_ITEM_TEMPLATE_INVALID_STATUS_TRANSITION');
        $this->getJson('/api/diagnostic-item-templates?status=active')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson("/api/diagnostic-item-templates/{$template['id']}/activate")->assertOk()->assertJsonPath('data.status.value', 'active');

        $this->deleteJson("/api/diagnostic-item-templates/{$template['id']}")->assertOk();
        $this->getJson("/api/diagnostic-item-templates/{$template['id']}")->assertNotFound();

        // Eliminado, su título queda libre.
        $this->createTemplate();
    }

    public function test_catalog_validation(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/diagnostic-item-templates', [])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors(['title', 'severity']);

        $this->postJson('/api/diagnostic-item-templates', $this->templatePayload(['title' => 'Pastillas!', 'severity' => 'grave', 'estimated_time' => -1]))
            ->assertJsonValidationErrors(['title', 'severity', 'estimated_time']);

        $template = $this->createTemplate();

        $this->postJson('/api/diagnostic-item-templates', $this->templatePayload())->assertJsonValidationErrors(['title']);
        $this->patchJson("/api/diagnostic-item-templates/{$template['id']}", ['title' => null, 'severity' => null])
            ->assertJsonValidationErrors(['title', 'severity']);
        // Su propio título no choca consigo mismo.
        $this->patchJson("/api/diagnostic-item-templates/{$template['id']}", ['title' => 'Pastillas de freno delanteras'])->assertOk();
    }

    public function test_diagnostic_item_copies_the_catalog_values(): void
    {
        $this->actingAsAdmin();

        $template = $this->createTemplate();
        $diagnostic = $this->createDiagnostic();

        // Solo el catálogo: el resto se copia y el estado arranca pendiente.
        $item = $this->postJson('/api/diagnostic-items', [
            'diagnostic_id' => $diagnostic['id'],
            'diagnostic_item_template_id' => $template['id'],
        ])->assertCreated()
            ->assertJsonPath('data.title', 'Pastillas de freno delanteras')
            ->assertJsonPath('data.severity', 'high')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.requires_replacement', true)
            ->assertJsonPath('data.estimated_time', 60)
            ->assertJsonPath('data.recommendation', 'Reemplazar las pastillas.')
            ->assertJsonPath('data.template.code', $template['code'])
            ->json('data');

        // Lo enviado pisa lo del catálogo.
        $this->postJson('/api/diagnostic-items', [
            'diagnostic_id' => $diagnostic['id'],
            'diagnostic_item_template_id' => $template['id'],
            'severity' => 'critical',
            'status' => 'replace_required',
            'description' => 'Menos del 10% de vida útil.',
        ])->assertCreated()
            ->assertJsonPath('data.severity', 'critical')
            ->assertJsonPath('data.status', 'replace_required')
            ->assertJsonPath('data.description', 'Menos del 10% de vida útil.');

        // Cambiar o eliminar el catálogo no altera el ítem; el vínculo se conserva.
        $this->patchJson("/api/diagnostic-item-templates/{$template['id']}", ['title' => 'Pastillas delanteras', 'severity' => 'low'])->assertOk();
        $this->deleteJson("/api/diagnostic-item-templates/{$template['id']}")->assertOk();

        $this->getJson("/api/diagnostics/{$diagnostic['id']}")
            ->assertOk()
            ->assertJsonPath('data.items.0.title', 'Pastillas de freno delanteras')
            ->assertJsonPath('data.items.0.severity', 'high')
            ->assertJsonPath('data.items.0.template.title', 'Pastillas delanteras')
            ->assertJsonPath('data.items.0.template.deleted', true);

        $this->getJson("/api/diagnostic-items/{$item['id']}")->assertOk()->assertJsonPath('data.template.id', $template['id']);

        // Eliminado del catálogo, ya no se elige.
        $this->postJson('/api/diagnostic-items', [
            'diagnostic_id' => $diagnostic['id'],
            'diagnostic_item_template_id' => $template['id'],
        ])->assertJsonValidationErrors(['diagnostic_item_template_id']);
    }

    public function test_inactive_catalog_items_cannot_be_used(): void
    {
        $this->actingAsAdmin();

        $template = $this->createTemplate();
        $diagnostic = $this->createDiagnostic();

        $this->postJson("/api/diagnostic-item-templates/{$template['id']}/deactivate")->assertOk();

        $this->postJson('/api/diagnostic-items', [
            'diagnostic_id' => $diagnostic['id'],
            'diagnostic_item_template_id' => $template['id'],
        ])->assertStatus(422)->assertJsonPath('error_code', 'DIAGNOSTIC_ITEM_TEMPLATE_INACTIVE');

        $this->assertDatabaseCount('diagnostic_items', 0);
    }

    public function test_free_item_can_be_saved_to_the_catalog(): void
    {
        $this->actingAsAdmin();

        $diagnostic = $this->createDiagnostic();
        $free = [
            'diagnostic_id' => $diagnostic['id'],
            'title' => 'Nivel de refrigerante',
            'description' => 'Por debajo del mínimo.',
            'severity' => 'medium',
            'status' => 'observation',
            'requires_repair' => false,
            'requires_replacement' => true,
            'estimated_cost' => 116000,
            'estimated_time' => 15,
            'save_as_template' => true,
        ];

        $item = $this->postJson('/api/diagnostic-items', $free)
            ->assertCreated()
            ->assertJsonPath('data.template.title', 'Nivel de refrigerante')
            ->json('data');

        // Copia los campos sugeribles; no el estado ni el costo del ítem.
        $this->getJson("/api/diagnostic-item-templates/{$item['diagnostic_item_template_id']}")
            ->assertOk()
            ->assertJsonPath('data.severity', 'medium')
            ->assertJsonPath('data.estimated_time', 15)
            ->assertJsonPath('data.description', 'Por debajo del mínimo.')
            ->assertJsonMissingPath('data.estimated_cost');

        // El título ya está en el catálogo; elegido del catálogo no se vuelve a guardar.
        $this->postJson('/api/diagnostic-items', $free)->assertJsonValidationErrors(['title']);
        $this->postJson('/api/diagnostic-items', [...$free, 'title' => 'Otro', 'diagnostic_item_template_id' => $item['diagnostic_item_template_id']])
            ->assertJsonValidationErrors(['diagnostic_item_template_id']);

        // Sin save_as_template, el ítem libre no toca el catálogo.
        $this->postJson('/api/diagnostic-items', [...$free, 'save_as_template' => false])
            ->assertCreated()
            ->assertJsonPath('data.template', null);
        $this->assertDatabaseCount('diagnostic_item_templates', 1);
    }

    public function test_catalog_permissions(): void
    {
        $this->actingAsAdmin();

        $template = $this->createTemplate();
        $diagnostic = $this->createDiagnostic();

        // El jefe de taller administra el catálogo.
        $this->actingAsRole('jefe-taller');
        $this->patchJson("/api/diagnostic-item-templates/{$template['id']}", ['estimated_time' => 30])->assertOk();
        $this->createTemplate(['title' => 'Batería']);

        // El mecánico lo consulta y lo usa, pero no lo modifica ni guarda ítems en él.
        $this->actingAsMechanic();
        $this->getJson('/api/diagnostic-item-templates')->assertOk();
        $this->getJson("/api/diagnostic-item-templates/{$template['id']}")->assertOk();
        $this->postJson('/api/diagnostic-item-templates', $this->templatePayload(['title' => 'Alternador']))->assertForbidden();
        $this->patchJson("/api/diagnostic-item-templates/{$template['id']}", ['estimated_time' => 10])->assertForbidden();
        $this->postJson("/api/diagnostic-item-templates/{$template['id']}/deactivate")->assertForbidden();
        $this->deleteJson("/api/diagnostic-item-templates/{$template['id']}")->assertForbidden()->assertJsonPath('error_code', 'FORBIDDEN');

        $this->postJson('/api/diagnostic-items', [
            'diagnostic_id' => $diagnostic['id'],
            'diagnostic_item_template_id' => $template['id'],
        ])->assertCreated();

        $this->postJson('/api/diagnostic-items', [
            'diagnostic_id' => $diagnostic['id'],
            'title' => 'Alternador',
            'severity' => 'high',
            'status' => 'pending',
            'requires_repair' => true,
            'requires_replacement' => false,
            'save_as_template' => true,
        ])->assertForbidden()->assertJsonPath('error_code', 'FORBIDDEN');

        // Recepcionista: sin acceso al catálogo.
        $this->actingAsRole('recepcionista');
        $this->getJson('/api/diagnostic-item-templates')->assertForbidden();
    }
}
