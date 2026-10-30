<?php

namespace Tests\Feature\Claims;

use App\Models\Insurer;
use App\Models\InvoiceStamp;
use App\Models\Reception;
use App\Services\BudgetService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * Reportes del módulo de aseguradoras (M11): cuentas por cobrar por tipo de
 * destinatario (vencido y antigüedad), resumen del siniestro de lo presupuestado
 * a lo cobrado (bloques según permiso) y reporte de siniestros. Usa el Ejemplo 1:
 * presupuestado 2.750.000 (2.600.000 a la aseguradora), autorizado 1.800.000,
 * aceptado por el dueño 950.000; reparto aseguradora 1.800.000 y dueño 970.000;
 * facturas con IVA 1.980.000 (aseguradora, a crédito) y 1.067.000 (dueño, contado).
 */
class InsurerReportsTest extends ClaimTestCase
{
    /** @return array{0: array, 1: \App\Models\Claim, 2: array, 3: array} OT, siniestro, factura de la aseguradora y del dueño (emitidas) */
    private function invoicedExample(): array
    {
        if (!InvoiceStamp::exists()) {
            $this->createStamp()->assertCreated();
        }
        [$workOrder, , $claim] = $this->completedClaimWorkOrder();
        $allocation = $this->postJson($this->url($workOrder))->assertCreated()->json('data');
        $this->postJson($this->url($workOrder, $allocation['id'], 'confirm'))->assertOk();

        $invoices = [];
        foreach ([Insurer::findOrFail($claim->insurer_id)->client_id, Reception::findOrFail($workOrder['reception_id'])->client_id] as $clientId) {
            $id = $this->postJson($this->url($workOrder, $allocation['id'], 'invoices'), ['client_id' => $clientId])->assertCreated()->json('data.id');
            $invoices[] = $this->postJson("/api/invoices/{$id}/issue")->assertOk()->json('data');
        }

        return [$workOrder, $claim, ...$invoices];
    }

    private function period(): string
    {
        return 'date_from=' . now()->subMonth()->toDateString() . '&date_to=' . now()->toDateString();
    }

    public function test_receivables_are_split_by_recipient_with_aging(): void
    {
        $this->actingAsAdmin();
        [, $claim, $insurer, $owner] = $this->invoicedExample();

        // Hoy: el contado vence hoy y el crédito a 30 días; nada vencido.
        $this->getJson('/api/reports/receivables')
            ->assertOk()
            ->assertJsonPath('data.totals.invoices_count', 2)
            ->assertJsonPath('data.totals.balance', '3047000.00')
            ->assertJsonPath('data.totals.overdue', '0.00')
            ->assertJsonPath('data.by_recipient.0.recipient_type', 'insurer')
            ->assertJsonPath('data.by_recipient.0.balance', '1980000.00')
            ->assertJsonPath('data.by_recipient.1.recipient_type', 'customer')
            ->assertJsonPath('data.by_recipient.1.recipient_label', 'Cliente')
            ->assertJsonPath('data.by_recipient.1.balance', '1067000.00')
            ->assertJsonPath('data.clients.0.recipient_type', 'insurer')
            ->assertJsonMissingPath('data.invoices');

        // El dueño paga una parte; 45 días después: el dueño, 45 días vencido; la aseguradora, 15.
        $this->postJson("/api/invoices/{$owner['id']}/payments", ['amount' => 67000, 'method' => 'cash'])->assertCreated();
        $this->travel(45)->days();

        $this->getJson('/api/reports/receivables')
            ->assertOk()
            ->assertJsonPath('data.totals.balance', '2980000.00')
            ->assertJsonPath('data.totals.overdue', '2980000.00')
            ->assertJsonPath('data.totals.days_1_30', '1980000.00')
            ->assertJsonPath('data.totals.days_31_60', '1000000.00')
            ->assertJsonPath('data.by_recipient.0.days_1_30', '1980000.00')
            ->assertJsonPath('data.by_recipient.1.days_31_60', '1000000.00');

        // Filtros: tipo de destinatario y cliente (con el detalle de sus facturas).
        $this->getJson('/api/reports/receivables?recipient_type=insurer')
            ->assertOk()
            ->assertJsonCount(1, 'data.by_recipient')
            ->assertJsonCount(1, 'data.clients')
            ->assertJsonPath('data.totals.balance', '1980000.00');
        $this->getJson("/api/reports/receivables?client_id={$owner['client_id']}")
            ->assertOk()
            ->assertJsonPath('data.invoices.0.invoice_id', $owner['id'])
            ->assertJsonPath('data.invoices.0.days_overdue', 45)
            ->assertJsonPath('data.invoices.0.claim_code', $claim->code)
            ->assertJsonPath('data.invoices.0.paid_total', '67000.00')
            ->assertJsonPath('data.invoices.0.balance', '1000000.00');
        $this->getJson('/api/reports/receivables?recipient_type=nadie')->assertStatus(422)->assertJsonValidationErrors('recipient_type');

        // Una aseguradora dada de baja sigue siendo aseguradora; el reporte de clientes también trae el tipo.
        DB::table('insurers')->where('id', $claim->insurer_id)->update(['deleted_at' => now()]);
        $this->getJson('/api/reports/receivables?recipient_type=insurer')->assertOk()->assertJsonPath('data.totals.balance', '1980000.00');
        $clients = collect($this->getJson('/api/reports/clients')->assertOk()->json('data.receivables.clients'))->keyBy('client_id');
        $this->assertSame('insurer', $clients[$insurer['client_id']]['recipient_type']);
        $this->assertSame('customer', $clients[$owner['client_id']]['recipient_type']);

        // Exportación.
        $csv = $this->get('/api/reports/receivables?format=csv')
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="reporte-cuentas-por-cobrar.csv"')
            ->getContent();
        $this->assertStringContainsString('Cuentas por cobrar', $csv);
        $this->assertStringContainsString('Por destinatario', $csv);
        $this->assertStringContainsString('31 a 60 días', $csv);
        $this->get('/api/reports/receivables?format=pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_the_claim_summary_goes_from_budgeted_to_collected(): void
    {
        $this->actingAsAdmin();
        if (!InvoiceStamp::exists()) {
            $this->createStamp()->assertCreated();
        }
        [$workOrder, , $claim] = $this->completedClaimWorkOrder();
        $allocation = $this->postJson($this->url($workOrder))->assertCreated()->json('data');

        // Sin reparto confirmado: lo avisa.
        $this->getJson("/api/claims/{$claim->id}/summary")
            ->assertOk()
            ->assertJsonPath('data.alerts.0', "La OT {$workOrder['code']} no tiene un reparto confirmado.");

        $this->postJson($this->url($workOrder, $allocation['id'], 'confirm'))->assertOk();
        $insurerClientId = Insurer::findOrFail($claim->insurer_id)->client_id;
        $insurerInvoice = $this->postJson($this->url($workOrder, $allocation['id'], 'invoices'), ['client_id' => $insurerClientId])->json('data');
        $this->postJson("/api/invoices/{$insurerInvoice['id']}/issue")->assertOk();

        // Un complementario en borrador se lista, pero no suma (solo los aprobados).
        $complementary = app(BudgetService::class)->create(['reception_id' => $workOrder['reception_id']], User::firstOrFail()->id);
        $this->postJson("/api/budgets/{$complementary->id}/items", ['type' => 'labor', 'description' => 'Pulido', 'quantity' => 1, 'unit_price' => 90000])->assertCreated();

        $data = $this->getJson("/api/claims/{$claim->id}/summary")->assertOk()->json('data');

        $this->assertSame($claim->code, $data['claim']['code']);
        $this->assertSame('0.00', $data['claim']['deductible_amount']);
        $this->assertSame(['budgeted_total' => '2750000.00', 'insurer_amount' => '2600000.00', 'owner_amount' => '150000.00',
            'authorized_total' => '1800000.00', 'accepted_owner_amount' => '950000.00'], $data['budgets']['totals']);
        $this->assertCount(2, $data['budgets']['items']);
        $this->assertSame('draft', $data['budgets']['items'][1]['status']);
        $this->assertSame(1, $data['budgets']['items'][0]['authorization_version']);

        $this->assertSame('1800000.00', $data['allocations']['totals']['insurer_amount']);
        $this->assertSame('970000.00', $data['allocations']['totals']['owner_amount']);
        $this->assertSame('0.00', $data['allocations']['totals']['workshop_amount']);
        $this->assertFalse($data['allocations']['totals']['deductible_fiscal_pending']);
        $this->assertTrue($data['allocations']['work_orders'][0]['requires_allocation']);

        $byRecipient = collect($data['billing']['by_recipient'])->keyBy('recipient_type');
        $this->assertSame('1980000.00', $byRecipient['insurer']['net_invoiced']);
        $this->assertSame('1980000.00', $byRecipient['insurer']['balance']);
        $this->assertSame('0.00', $byRecipient['customer']['net_invoiced']);
        $this->assertSame(["Al reparto de la OT {$workOrder['code']} le faltan porciones por facturar o emitir."], $data['alerts']);

        // El dueño facturado y cobrado: el siniestro queda sin alertas.
        $ownerInvoice = $this->postJson($this->url($workOrder, $allocation['id'], 'invoices'), ['client_id' => Reception::findOrFail($workOrder['reception_id'])->client_id])->json('data');
        $this->postJson("/api/invoices/{$ownerInvoice['id']}/issue")->assertOk();
        $this->postJson("/api/invoices/{$ownerInvoice['id']}/payments", ['amount' => 1067000, 'method' => 'cash'])->assertCreated();

        $data = $this->getJson("/api/claims/{$claim->id}/summary")->assertOk()->json('data');
        $byRecipient = collect($data['billing']['by_recipient'])->keyBy('recipient_type');
        $this->assertSame('1067000.00', $byRecipient['customer']['collected']);
        $this->assertSame('0.00', $byRecipient['customer']['balance']);
        $this->assertSame('3047000.00', $data['billing']['totals']['net_invoiced']);
        $this->assertSame('1980000.00', $data['billing']['totals']['balance']);
        $this->assertCount(2, $data['billing']['invoices']);
        $this->assertSame([], $data['alerts']);

        // Deducible mayor a 0 sin facturas a la aseguradora: no aplica (bloqueado); con deducible desconocido, alerta.
        $other = $this->openReception();
        $id = $this->postJson('/api/claims', $this->claimBody($other->id, $this->insurer(), ['deductible_amount' => null]))->json('data.id');
        $this->getJson("/api/claims/{$id}/summary")
            ->assertOk()
            ->assertJsonPath('data.alerts.0', 'El deducible del siniestro es desconocido: el reparto no se confirma y la recepción no se entrega hasta informarlo.')
            ->assertJsonPath('data.budgets.totals.budgeted_total', '0.00')
            ->assertJsonPath('data.billing.totals.net_invoiced', '0.00');
    }

    public function test_summary_blocks_follow_the_permissions(): void
    {
        $this->actingAsAdmin();
        [, $claim] = $this->invoicedExample();
        $url = "/api/claims/{$claim->id}/summary";

        $this->actingAsRole('recepcionista');
        $this->getJson($url)->assertOk()->assertJsonStructure(['data' => ['claim', 'budgets', 'allocations', 'billing', 'alerts']]);

        // El jefe de taller no ve facturas.
        $this->actingAsRole('jefe-taller');
        $this->getJson($url)->assertOk()->assertJsonStructure(['data' => ['budgets', 'allocations']])->assertJsonMissingPath('data.billing');

        Role::create(['name' => 'solo-siniestros', 'guard_name' => 'api'])->givePermissionTo('claims.show');
        $this->actingAsRole('solo-siniestros');
        $this->getJson($url)->assertOk()->assertJsonMissingPath('data.allocations')->assertJsonMissingPath('data.billing')
            ->assertJsonPath('data.budgets.totals.authorized_total', '1800000.00');

        foreach (['mechanic', 'deposito'] as $role) {
            $this->actingAsRole($role);
            $this->getJson($url)->assertForbidden();
        }

        // Reportes nuevos: solo el cajero (y el administrador).
        $this->actingAsRole('cajero');
        $this->getJson('/api/reports/receivables')->assertOk();
        $this->getJson('/api/reports/claims')->assertOk();
        foreach (['recepcionista', 'jefe-taller'] as $role) {
            $this->actingAsRole($role);
            $this->getJson('/api/reports/receivables')->assertForbidden();
            $this->getJson('/api/reports/claims')->assertForbidden();
        }
    }

    public function test_the_claims_report_lists_each_claim_of_the_period(): void
    {
        $this->actingAsAdmin();
        [, $claim] = $this->invoicedExample();
        $cancelled = $this->postJson('/api/claims', $this->claimBody($this->openReception()->id, $this->insurer()))->json('data.id');
        $this->postJson("/api/claims/{$cancelled}/cancel", ['reason' => 'Error de carga.'])->assertOk();

        $data = $this->getJson('/api/reports/claims?' . $this->period())->assertOk()->json('data');
        $this->assertSame(2, $data['totals']['claims_count']);
        $this->assertSame('1800000.00', $data['totals']['authorized_total']);
        $this->assertSame('1980000.00', $data['totals']['balance_insurer']);

        $row = collect($data['claims'])->firstWhere('claim_id', $claim->id);
        $this->assertSame('Abierto', $row['status']);
        $this->assertSame('2600000.00', $row['budgeted_insurer']);
        $this->assertSame('1800000.00', $row['allocated_insurer']);
        $this->assertSame('970000.00', $row['allocated_owner']);
        $this->assertSame('1980000.00', $row['invoiced_insurer']);
        $this->assertSame('1067000.00', $row['invoiced_customer']);
        $this->assertSame(0, $row['alerts_count']);

        // Filtros: estado y aseguradora; fuera del período no aparece.
        $this->getJson('/api/reports/claims?status=cancelled&' . $this->period())->assertOk()->assertJsonPath('data.totals.claims_count', 1)
            ->assertJsonPath('data.claims.0.claim_id', $cancelled);
        $this->getJson("/api/reports/claims?insurer_id={$claim->insurer_id}&" . $this->period())->assertOk()->assertJsonPath('data.totals.claims_count', 1);
        $this->getJson('/api/reports/claims?date_from=2020-01-01&date_to=2020-01-31')->assertOk()->assertJsonPath('data.totals.claims_count', 0);
        $this->getJson('/api/reports/claims?status=todo')->assertStatus(422)->assertJsonValidationErrors('status');

        $csv = $this->get('/api/reports/claims?format=csv&' . $this->period())->assertOk()->getContent();
        $this->assertStringContainsString('Reporte de siniestros', $csv);
        $this->assertStringContainsString('Facturado neto a la aseguradora', $csv);
        $this->get('/api/reports/claims?format=pdf&' . $this->period())->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }
}
