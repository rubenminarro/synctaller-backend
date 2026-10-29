<?php

namespace Tests\Feature\Claims;

use App\Exceptions\InsurerAuthorizationException;
use App\Models\Budget;
use App\Models\Claim;
use App\Models\InsurerAuthorization;
use App\Models\InsurerAuthorizationItem;
use App\Models\Reception;
use App\Models\User;
use App\Services\BudgetService;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Role;

/**
 * Autorizaciones de la aseguradora (M6): una línea por ítem a su cargo, con tope
 * en lo presupuestado e importes sin IVA; versiones que se sustituyen, evidencia
 * obligatoria si sube un importe, e invalidación al reabrir o cancelar.
 */
class InsurerAuthorizationTest extends ClaimTestCase
{
    /** Presupuesto enviado: dos líneas de la aseguradora (500.000 y 2 × 100.000) y una del dueño (80.000). */
    private function sentBudget(?Reception $reception = null): Budget
    {
        $reception ??= $this->openReception();
        $this->postJson('/api/claims', $this->claimBody($reception->id, $this->insurer()))->assertCreated();
        $budgetId = app(BudgetService::class)->create(['reception_id' => $reception->id], User::firstOrFail()->id)->id;

        foreach ([
            ['description' => 'Chapería de puerta', 'quantity' => 1, 'unit_price' => 500000, 'responsibility' => 'insurer'],
            ['description' => 'Pintura de paragolpes', 'quantity' => 2, 'unit_price' => 100000, 'responsibility' => 'insurer'],
            ['description' => 'Lavado de motor', 'quantity' => 1, 'unit_price' => 80000],
        ] as $line) {
            $this->postJson("/api/budgets/{$budgetId}/items", ['type' => 'labor', ...$line])->assertCreated();
        }

        $this->postJson("/api/budgets/{$budgetId}/send")->assertOk();

        return Budget::with('items')->findOrFail($budgetId);
    }

    /** Cuerpo con todas las líneas de la aseguradora autorizadas, salvo las indicadas por posición. */
    private function body(Budget $budget, array $lines = [], array $overrides = []): array
    {
        $items = $budget->items->where('responsibility.value', 'insurer')->values()
            ->map(fn ($item, $i) => ['budget_item_id' => $item->id, 'decision' => 'authorized', ...($lines[$i] ?? [])])
            ->all();

        return [
            'amounts_include_tax' => false,
            'decided_at' => now()->toDateString(),
            'channel' => 'email',
            'authorization_number' => 'AUT-001',
            'items' => $items,
            ...$overrides,
        ];
    }

    private function register(Budget $budget, array $body)
    {
        return $this->postJson("/api/budgets/{$budget->id}/insurer-authorizations", $body);
    }

    private function document(Budget $budget): int
    {
        $path = tempnam(sys_get_temp_dir(), 'auth');
        file_put_contents($path, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << >>\n%%EOF\n");
        $claim = Claim::where('reception_id', $budget->reception_id)->latest('id')->firstOrFail();

        return $this->post("/api/claims/{$claim->id}/documents", [
            'file' => new UploadedFile($path, 'autorizacion.pdf', null, null, true),
            'type' => 'authorization',
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
    }

    public function test_an_authorization_is_registered_line_by_line_without_tax(): void
    {
        $this->actingAsAdmin();
        $budget = $this->sentBudget();

        // La chapería, parcial: 330.000 con IVA son 300.000 sin IVA. La pintura, autorizada entera.
        $data = $this->register($budget, $this->body($budget, [['decision' => 'partial', 'authorized_total' => 330000, 'notes' => 'Tope del baremo.']], [
            'amounts_include_tax' => true,
            'adjuster_name' => 'Ramón Giménez',
        ]))
            ->assertCreated()
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.status.value', 'current')
            ->assertJsonPath('data.result.value', 'partial')
            ->assertJsonPath('data.result.label', 'Parcial')
            ->assertJsonPath('data.authorized_total', '500000.00')
            ->assertJsonPath('data.channel.value', 'email')
            ->assertJsonPath('data.items.0.description', 'Chapería de puerta')
            ->assertJsonPath('data.items.0.budgeted_total', '500000.00')
            ->assertJsonPath('data.items.0.decision.value', 'partial')
            ->assertJsonPath('data.items.0.authorized_quantity', '1.00')
            ->assertJsonPath('data.items.0.authorized_total', '300000.00')
            ->assertJsonPath('data.items.1.decision.value', 'authorized')
            ->assertJsonPath('data.items.1.authorized_quantity', '2.00')
            ->assertJsonPath('data.items.1.authorized_unit_price', '100000.00')
            ->assertJsonPath('data.items.1.authorized_total', '200000.00')
            ->json('data');

        $this->assertCount(2, $data['items']);

        $this->getJson("/api/budgets/{$budget->id}")
            ->assertOk()
            ->assertJsonPath('data.insurer_authorization.id', $data['id'])
            ->assertJsonPath('data.insurer_authorization.result.value', 'partial')
            ->assertJsonPath('data.insurer_authorization.authorized_total', '500000.00');

        // Todas autorizadas → total; ninguna cubierta → rechazada (bajar no exige evidencia).
        $other = $this->sentBudget();
        $this->register($other, $this->body($other))->assertCreated()->assertJsonPath('data.result.value', 'full');
        $this->register($budget, $this->body($budget, [['decision' => 'not_covered'], ['decision' => 'not_covered']]))
            ->assertCreated()
            ->assertJsonPath('data.result.value', 'rejected')
            ->assertJsonPath('data.authorized_total', '0.00')
            ->assertJsonPath('data.items.0.authorized_unit_price', '0.00');
    }

    public function test_the_lines_must_match_the_insurer_items_and_not_exceed_them(): void
    {
        $this->actingAsAdmin();
        $budget = $this->sentBudget();
        $owner = $budget->items->firstWhere('responsibility.value', 'owner');
        $body = $this->body($budget);

        $this->register($budget, [...$body, 'items' => [$body['items'][0]]])
            ->assertStatus(422)->assertJsonPath('error_code', 'INSURER_AUTHORIZATION_ITEMS_INCOMPLETE');
        $this->register($budget, [...$body, 'items' => [...$body['items'], ['budget_item_id' => $owner->id, 'decision' => 'authorized']]])
            ->assertStatus(422)->assertJsonPath('error_code', 'INSURER_AUTHORIZATION_ITEM_MISMATCH');
        $this->register($budget, $this->body($budget, [['decision' => 'partial', 'authorized_total' => 500000.01]]))
            ->assertStatus(422)->assertJsonPath('error_code', 'INSURER_AUTHORIZATION_EXCEEDS_BUDGET');
        $this->register($budget, $this->body($budget, [[], ['decision' => 'partial', 'authorized_total' => 100000, 'authorized_quantity' => 3]]))
            ->assertStatus(422)->assertJsonPath('error_code', 'INSURER_AUTHORIZATION_EXCEEDS_BUDGET');

        // Validación: decisión, importe de la parcial, IVA y medio obligatorios.
        $this->register($budget, $this->body($budget, [['decision' => 'partial'], ['decision' => 'todo']], [
            'amounts_include_tax' => null,
            'channel' => 'fax',
            'decided_at' => now()->addDay()->toDateString(),
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.authorized_total', 'items.1.decision', 'amounts_include_tax', 'channel', 'decided_at']);
        $this->register($budget, [...$body, 'items' => [$body['items'][0], $body['items'][0]]])
            ->assertStatus(422)->assertJsonValidationErrors('items.1.budget_item_id');

        // Una parcial con menos cantidad: el precio unitario sale del importe.
        $this->register($budget, $this->body($budget, [[], ['decision' => 'partial', 'authorized_total' => 90000, 'authorized_quantity' => 1]]))
            ->assertCreated()
            ->assertJsonPath('data.items.1.authorized_quantity', '1.00')
            ->assertJsonPath('data.items.1.authorized_unit_price', '90000.00');
        $this->assertSame(1, InsurerAuthorization::count());
    }

    public function test_only_sent_or_approved_budgets_with_an_open_claim_and_insurer_lines(): void
    {
        $this->actingAsAdmin();
        $reception = $this->openReception();
        $draft = app(BudgetService::class)->create(['reception_id' => $reception->id], User::firstOrFail()->id);
        $this->register($draft, $this->body($draft, [], ['items' => [['budget_item_id' => 1, 'decision' => 'authorized']]]))
            ->assertStatus(422)->assertJsonPath('error_code', 'INSURER_AUTHORIZATION_BUDGET_NOT_AUTHORIZABLE');

        // Sin líneas de la aseguradora.
        $this->postJson("/api/budgets/{$draft->id}/items", ['type' => 'labor', 'description' => 'Lavado', 'quantity' => 1, 'unit_price' => 50000])->assertCreated();
        $this->postJson("/api/budgets/{$draft->id}/send")->assertOk();
        $this->register($draft->refresh(), ['amounts_include_tax' => false, 'decided_at' => now()->toDateString(), 'channel' => 'email', 'items' => [['budget_item_id' => 1, 'decision' => 'authorized']]])
            ->assertStatus(422)->assertJsonPath('error_code', 'BUDGET_WITHOUT_CLAIM');

        // Aprobado sí; con el siniestro cancelado, no.
        $budget = $this->sentBudget();
        $this->postJson("/api/budgets/{$budget->id}/approve")->assertOk();
        $this->register($budget, $this->body($budget))->assertCreated();

        $claim = Claim::where('reception_id', $budget->reception_id)->sole();
        $this->postJson("/api/claims/{$claim->id}/cancel", ['reason' => 'La aseguradora rechazó la cobertura.'])->assertOk();
        $this->register($budget, $this->body($budget))
            ->assertStatus(422)->assertJsonPath('error_code', 'BUDGET_WITHOUT_CLAIM');

        // Con siniestro abierto pero sin líneas de la aseguradora.
        $other = $this->openReception();
        $this->postJson('/api/claims', $this->claimBody($other->id, $this->insurer()))->assertCreated();
        $ownerOnly = app(BudgetService::class)->create(['reception_id' => $other->id], User::firstOrFail()->id);
        $this->postJson("/api/budgets/{$ownerOnly->id}/items", ['type' => 'labor', 'description' => 'Lavado', 'quantity' => 1, 'unit_price' => 50000])->assertCreated();
        $this->postJson("/api/budgets/{$ownerOnly->id}/send")->assertOk();
        $this->register($ownerOnly, ['amounts_include_tax' => false, 'decided_at' => now()->toDateString(), 'channel' => 'email', 'items' => [['budget_item_id' => 1, 'decision' => 'authorized']]])
            ->assertStatus(422)->assertJsonPath('error_code', 'INSURER_AUTHORIZATION_WITHOUT_INSURER_ITEMS');
    }

    public function test_a_new_version_supersedes_the_current_one_and_raising_requires_evidence(): void
    {
        $this->actingAsAdmin();
        $budget = $this->sentBudget();
        $partial = [['decision' => 'partial', 'authorized_total' => 300000]];

        $first = $this->register($budget, $this->body($budget, $partial))->assertCreated()->json('data.id');

        // Igual o menor: sin evidencia.
        $second = $this->register($budget, $this->body($budget, [...$partial, ['decision' => 'not_covered']]))
            ->assertCreated()->assertJsonPath('data.version', 2)->json('data.id');
        $this->assertSame('superseded', InsurerAuthorization::find($first)->status->value);
        $this->assertSame($second, InsurerAuthorization::find($first)->superseded_by_id);

        // Sube la pintura (de 0 a 200.000): exige un documento del siniestro, sin vincular.
        $this->register($budget, $this->body($budget, $partial))
            ->assertStatus(422)->assertJsonPath('error_code', 'INSURER_AUTHORIZATION_EVIDENCE_REQUIRED');

        $foreign = $this->document($this->sentBudget());
        $this->register($budget, $this->body($budget, $partial, ['document_ids' => [$foreign]]))
            ->assertStatus(422)->assertJsonPath('error_code', 'INSURER_AUTHORIZATION_DOCUMENT_MISMATCH');

        $documentId = $this->document($budget);
        $third = $this->register($budget, $this->body($budget, $partial, ['document_ids' => [$documentId]]))
            ->assertCreated()
            ->assertJsonPath('data.version', 3)
            ->assertJsonPath('data.documents.0.id', $documentId)
            ->assertJsonPath('data.documents.0.insurer_authorization_id', fn ($id) => $id !== null)
            ->json('data.id');

        // El mismo documento no respalda dos autorizaciones, ni se da de baja.
        $this->register($budget, $this->body($budget, [], ['document_ids' => [$documentId]]))
            ->assertStatus(422)->assertJsonPath('error_code', 'INSURER_AUTHORIZATION_DOCUMENT_MISMATCH');
        $claim = Claim::where('reception_id', $budget->reception_id)->sole();
        $this->deleteJson("/api/claims/{$claim->id}/documents/{$documentId}", ['reason' => 'Error de carga.'])
            ->assertStatus(409)->assertJsonPath('error_code', 'CLAIM_DOCUMENT_IN_USE');

        $this->getJson("/api/budgets/{$budget->id}/insurer-authorizations")
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.status.value', 'superseded')
            ->assertJsonPath('data.2.status.value', 'current');
        $this->getJson("/api/budgets/{$budget->id}/insurer-authorizations/{$third}")->assertOk()->assertJsonPath('data.version', 3);

        // Una autorización de otro presupuesto no se ve por este.
        $this->getJson("/api/budgets/{$this->sentBudget()->id}/insurer-authorizations/{$third}")->assertNotFound();
    }

    public function test_reopening_or_cancelling_invalidates_the_current_authorization(): void
    {
        $this->actingAsAdmin();

        $budget = $this->sentBudget();
        $id = $this->register($budget, $this->body($budget))->json('data.id');
        $this->postJson("/api/budgets/{$budget->id}/reject")->assertOk();
        $this->assertSame('current', InsurerAuthorization::find($id)->status->value);
        $this->postJson("/api/budgets/{$budget->id}/reopen")->assertOk()->assertJsonMissingPath('data.insurer_authorization.id');
        $this->assertSame('invalidated', InsurerAuthorization::find($id)->status->value);
        $this->assertSame('Presupuesto reabierto.', InsurerAuthorization::find($id)->invalidation_reason);

        // Reabierto, el ítem se puede borrar: la autorización conserva su copia.
        $item = $budget->items->first();
        $this->deleteJson("/api/budgets/{$budget->id}/items/{$item->id}")->assertOk();
        $this->getJson("/api/budgets/{$budget->id}/insurer-authorizations/{$id}")
            ->assertOk()
            ->assertJsonPath('data.items.0.budget_item_id', null)
            ->assertJsonPath('data.items.0.description', 'Chapería de puerta');

        $cancelled = $this->sentBudget();
        $id = $this->register($cancelled, $this->body($cancelled))->json('data.id');
        $this->postJson("/api/budgets/{$cancelled->id}/cancel")->assertOk();
        $this->assertSame('invalidated', InsurerAuthorization::find($id)->status->value);

        $reception = $this->openReception();
        $byReception = $this->sentBudget($reception);
        $id = $this->register($byReception, $this->body($byReception))->json('data.id');
        $this->postJson("/api/receptions/{$reception->id}/cancel", ['reason' => 'El cliente desistió.'])->assertOk();
        $this->assertSame('Recepción cancelada: El cliente desistió.', InsurerAuthorization::find($id)->invalidation_reason);
    }

    public function test_authorizations_are_immutable(): void
    {
        $this->actingAsAdmin();
        $budget = $this->sentBudget();
        $authorization = InsurerAuthorization::findOrFail($this->register($budget, $this->body($budget))->json('data.id'));

        foreach ([
            fn () => $authorization->update(['authorized_total' => 1]),
            fn () => $authorization->delete(),
            fn () => InsurerAuthorizationItem::firstOrFail()->update(['authorized_total' => 1]),
        ] as $change) {
            try {
                $change();
                $this->fail('La autorización no debería modificarse.');
            } catch (InsurerAuthorizationException $e) {
                $this->assertSame('INSURER_AUTHORIZATION_IMMUTABLE', $e->getErrorCode());
            }
        }

        // Una vez sustituida, ni siquiera cambia su estado.
        $this->register($budget, $this->body($budget))->assertCreated();
        $this->expectException(InsurerAuthorizationException::class);
        $authorization->refresh()->update(['status' => 'current']);
    }

    public function test_permissions(): void
    {
        $this->actingAsAdmin();
        $budget = $this->sentBudget();
        $id = $this->register($budget, $this->body($budget))->json('data.id');

        $this->actingAsRole('cajero');
        $this->getJson("/api/budgets/{$budget->id}/insurer-authorizations")->assertOk();
        $this->getJson("/api/budgets/{$budget->id}/insurer-authorizations/{$id}")->assertOk();
        $this->register($budget, $this->body($budget))->assertForbidden();

        $this->actingAsRole('jefe-taller');
        $this->register($budget, $this->body($budget))->assertCreated();

        $this->actingAsRole('recepcionista');
        $this->register($budget, $this->body($budget))->assertCreated();
        $this->getJson("/api/budgets/{$budget->id}")->assertOk()->assertJsonPath('data.insurer_authorization.version', 3);

        foreach (['mechanic', 'deposito'] as $role) {
            $this->actingAsRole($role);
            $this->getJson("/api/budgets/{$budget->id}/insurer-authorizations")->assertForbidden();
        }

        // Quien ve el presupuesto sin el permiso no recibe la autorización.
        Role::create(['name' => 'solo-presupuestos', 'guard_name' => 'api'])->givePermissionTo('budgets.show');
        $this->actingAsRole('solo-presupuestos');
        $this->getJson("/api/budgets/{$budget->id}")->assertOk()->assertJsonMissingPath('data.insurer_authorization');
    }
}
