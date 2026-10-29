<?php

namespace Tests\Feature\Seeders;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Mechanic;
use App\Models\Purchase;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\MaintenanceService;
use Database\Seeders\ClientsSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\VehiclesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Los seeders cubren cada rol y cada estado de los módulos, y son idempotentes:
 * ejecutarlos de nuevo no duplica ni falla.
 *
 * Siembra una sola vez (y una más para la idempotencia): sembrar es lo caro. La
 * cola se falsea como en desarrollo (QUEUE_CONNECTION=database): los avisos a
 * clientes quedan encolados en lugar de armar cada email con su PDF.
 */
class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    private const TABLES = [
        'users', 'roles', 'permissions', 'mechanics', 'brands', 'vehicle_models', 'service_categories', 'services',
        'maintenance_plans', 'check_list_items', 'diagnostic_item_templates', 'clients', 'vehicles', 'receptions', 'reception_check_lists',
        'reception_check_list_items', 'diagnostics', 'diagnostic_items', 'diagnostic_item_photos', 'budgets',
        'budget_items', 'budget_client_acceptances', 'insurers', 'insurer_contacts', 'claims', 'claim_documents', 'insurer_authorizations', 'insurer_authorization_items', 'internal_exceptions', 'part_categories', 'suppliers', 'parts', 'part_stocks', 'inventory_movements', 'invoice_stamps',
        'work_orders', 'work_order_items', 'work_order_parts', 'work_order_closings', 'notifications',
        'invoices', 'invoice_items', 'payments', 'vehicle_deliveries', 'client_notifications',
        'purchases', 'purchase_items', 'supplier_payments', 'vehicle_maintenances', 'work_order_commissions',
    ];

    public function test_seeders_cover_every_state_and_are_idempotent(): void
    {
        Storage::fake('public');
        Storage::fake('claim_documents');
        Queue::fake();

        $this->seed(DatabaseSeeder::class);
        $first = $this->counts();

        $this->assertUsersAndCatalogs();
        $this->assertWorkshopFlow();
        $this->assertBilling();
        $this->assertPurchasesAndInventory();
        $this->assertNotificationsCommissionsAndMaintenance();

        $this->seed(DatabaseSeeder::class);

        $this->assertSame($first, $this->counts());
    }

    private function assertUsersAndCatalogs(): void
    {
        // Usuarios por rol.
        $this->assertSame(
            ['administrador' => 2, 'cajero' => 2, 'deposito' => 2, 'jefe-taller' => 2, 'mechanic' => 6, 'recepcionista' => 3],
            User::with('roles')->get()->countBy(fn (User $user) => $user->roles->first()->name)->sortKeys()->all()
        );

        // Mecánicos: cada estado, todos vinculados a un usuario con rol mechanic.
        $this->assertEqualsCanonicalizing(
            ['active', 'inactive', 'on_leave'],
            DB::table('mechanics')->distinct()->pluck('status')->all()
        );
        $this->assertSame(0, Mechanic::whereDoesntHave('user', fn ($query) => $query->role('mechanic'))->count());

        // Servicios y repuestos en cada estado; repuestos bajo el mínimo y sin stock.
        $this->assertEqualsCanonicalizing(['active', 'inactive'], DB::table('services')->distinct()->pluck('status')->all());
        $this->assertEqualsCanonicalizing(['active', 'inactive', 'discontinued'], DB::table('parts')->distinct()->pluck('status')->all());
        $this->assertEqualsCanonicalizing(['active', 'inactive'], DB::table('diagnostic_item_templates')->distinct()->pluck('status')->all());
        $this->assertGreaterThanOrEqual(30, DB::table('diagnostic_item_templates')->count());
        $this->assertGreaterThanOrEqual(2, DB::table('part_stocks')->join('parts', 'parts.id', '=', 'part_stocks.part_id')
            ->where('part_stocks.quantity_on_hand', '>', 0)
            ->whereColumn('part_stocks.quantity_on_hand', '<', 'parts.minimum_stock')
            ->count());
        $this->assertFalse(DB::table('part_stocks')->join('parts', 'parts.id', '=', 'part_stocks.part_id')
            ->where('parts.sku', 'LUB-20W50-001')->where('part_stocks.quantity_on_hand', '>', 0)->exists());

        // Casos especiales de clientes y vehículos; cada vehículo usa un modelo de su propia marca.
        $this->assertNull(Client::where('document_number', ClientsSeeder::WITHOUT_EMAIL)->value('email'));
        $this->assertFalse((bool) Client::where('document_number', ClientsSeeder::NOTIFICATIONS_DISABLED)->value('notifications_enabled'));
        $this->assertEqualsCanonicalizing(['person', 'company'], DB::table('clients')->distinct()->pluck('kind')->all());
        $company = Client::where('document_number', ClientsSeeder::COMPANY)->sole();
        $this->assertNull($company->first_name);
        $this->assertSame('Transportes del Este S.A.', $company->display_name);

        // Aseguradoras (M2): una activa y una inactiva, con su cliente empresa sin avisos y con contacto principal.
        $this->assertSame(['active' => 1, 'inactive' => 1], $this->statusCounts('insurers'));
        $insurer = \App\Models\Insurer::whereHas('client', fn ($q) => $q->where('document_number', \Database\Seeders\InsurerSeeder::ACTIVE))->sole();
        $this->assertSame('company', $insurer->client->kind->value);
        $this->assertFalse($insurer->client->notifications_enabled);
        $this->assertSame(1, $insurer->contacts()->where('is_primary', true)->count());

        // Siniestros (M3): el estado sale de la sincronización con la recepción (C05 y C07 abiertas, H07 entregada, X01 cancelada).
        $this->assertSame(['cancelled' => 1, 'closed' => 1, 'open' => 2], $this->statusCounts('claims'));
        // Responsabilidad por línea (M5): los presupuestos de los siniestros abiertos (C05 y C07) los paga la aseguradora.
        $this->assertEqualsCanonicalizing(['owner', 'insurer'], DB::table('budget_items')->distinct()->pluck('responsibility')->all());
        // Documentos (M4): denuncia y foto de C07 y la ampliación de la autorización de C05, en el disco privado.
        $this->assertEqualsCanonicalizing(['loss_report', 'photo', 'authorization'], DB::table('claim_documents')->pluck('type')->all());
        // Autorizaciones (M6): C05 con la versión 2 vigente (la 1, sustituida) y la de C07 invalidada al reabrir.
        $this->assertSame(['current' => 1, 'invalidated' => 1, 'superseded' => 1], $this->statusCounts('insurer_authorizations'));
        $this->assertSame(1, DB::table('claim_documents')->whereNotNull('insurer_authorization_id')->count());
        // Bloqueo de la OT (M7): C05 aprobado con la versión 1 (el dueño paga la mitad de la instalación) y re-aceptado
        // con la 2 (ya no paga nada); la excepción para iniciar sin autorización quedó sin usar. El rechazo de C07 también
        // guarda su autorización vigente.
        $c05 = DB::table('budget_client_acceptances')->whereNotNull('insurer_authorization_id')
            ->whereIn('kind', ['approval', 're_acceptance'])->orderBy('id')->get();
        $this->assertSame(['approval', 're_acceptance'], $c05->pluck('kind')->all());
        $this->assertSame(1, bccomp((string) $c05[0]->owner_amount, '0', 2));
        $this->assertSame(0, bccomp((string) $c05[1]->owner_amount, '0', 2));
        $this->assertSame(['start_without_authorization'], DB::table('internal_exceptions')->whereNull('consumed_at')->pluck('type')->all());
        DB::table('claim_documents')->pluck('path')->each(fn (string $path) => Storage::disk('claim_documents')->assertExists($path));
        $this->assertTrue((bool) Vehicle::where('chassis', VehiclesSeeder::WITHOUT_PLATE_CHASSIS)->value('no_plate'));
        $this->assertGreaterThanOrEqual(30, Client::count());
        $this->assertGreaterThanOrEqual(45, Vehicle::count());
        $this->assertSame(0, DB::table('vehicles')
            ->join('vehicle_models', 'vehicle_models.id', '=', 'vehicles.vehicle_model_id')
            ->whereColumn('vehicle_models.brand_id', '!=', 'vehicles.brand_id')
            ->count());
    }

    private function assertWorkshopFlow(): void
    {
        // Estados del flujo del taller (ExampleScenarios): las recepciones los derivan de presupuestos y OT.
        $this->assertSame(
            ['cancelled' => 2, 'completed' => 4, 'delivered' => 13, 'in_progress' => 9, 'pending' => 3, 'repair' => 4],
            $this->statusCounts('receptions')
        );
        $this->assertSame(
            ['approved' => 25, 'cancelled' => 2, 'draft' => 2, 'rejected' => 1, 'sent' => 2],
            $this->statusCounts('budgets')
        );
        $this->assertSame(
            ['cancelled' => 1, 'completed' => 17, 'in_progress' => 3, 'paused' => 1, 'pending' => 2],
            $this->statusCounts('work_orders')
        );
        // Cada respuesta del cliente queda registrada: una aprobación por presupuesto aprobado y un rechazo por cada
        // presupuesto rechazado (también los reabiertos, que vuelven a borrador con su rechazo en el historial).
        $acceptances = DB::table('budget_client_acceptances')->selectRaw('kind, count(*) as total')->groupBy('kind')
            ->pluck('total', 'kind')->map(fn ($total) => (int) $total)->all();
        $this->assertSame(25, $acceptances['approval'] ?? 0);
        $this->assertGreaterThanOrEqual(2, $acceptances['rejection'] ?? 0);
        $this->assertSame(0, DB::table('budgets')->where('status', 'approved')
            ->whereNotExists(fn ($query) => $query->from('budget_client_acceptances')
                ->whereColumn('budget_client_acceptances.budget_id', 'budgets.id')
                ->where('kind', 'approval'))
            ->count());

        $this->assertEqualsCanonicalizing(['pending', 'in_progress', 'completed', 'cancelled'], array_keys($this->statusCounts('diagnostics')));
        $this->assertEqualsCanonicalizing(['pending', 'in_progress', 'completed', 'cancelled'], array_keys($this->statusCounts('work_order_items')));
        $this->assertEqualsCanonicalizing(
            ['awaiting_approval', 'pending', 'reserved', 'consumed', 'returned', 'cancelled'],
            array_keys($this->statusCounts('work_order_parts'))
        );
        $this->assertEqualsCanonicalizing(
            ['pending', 'ok', 'observation', 'repair_required', 'replace_required', 'not_applicable'],
            array_keys($this->statusCounts('diagnostic_items'))
        );
        $this->assertEqualsCanonicalizing(['low', 'medium', 'high', 'critical'], DB::table('diagnostic_items')->distinct()->pluck('severity')->all());
        // Cada ítem sale del catálogo y conserva su título.
        $this->assertSame(0, DB::table('diagnostic_items')
            ->join('diagnostic_item_templates', 'diagnostic_item_templates.id', '=', 'diagnostic_items.diagnostic_item_template_id')
            ->whereColumn('diagnostic_items.title', '!=', 'diagnostic_item_templates.title')
            ->count());

        // Cada OT completada tiene su cierre económico; los datos se reparten en los últimos meses.
        $this->assertSame(17, DB::table('work_order_closings')->count());
        $this->assertTrue(DB::table('receptions')->where('reception_date', '<', now()->subDays(80))->exists());
        $this->assertFalse(DB::table('work_orders')->where('created_at', '>', now())->exists());
    }

    private function assertBilling(): void
    {
        // Facturación, cobros y entregas (ExampleScenarios::BILLING).
        $this->assertSame(['cancelled' => 1, 'draft' => 1, 'issued' => 15], $this->statusCounts('invoices'));
        $this->assertSame(['credit' => 5, 'cash' => 12], DB::table('invoices')->selectRaw('`condition`, count(*) as total')
            ->groupBy('condition')->orderByDesc('condition')->pluck('total', 'condition')->map(fn ($total) => (int) $total)->all());
        $this->assertSame(
            ['paid' => 10, 'partial' => 2, 'unpaid' => 3],
            Invoice::where('status', 'issued')->get()->countBy(fn (Invoice $invoice) => $invoice->paymentStatus()->value)->sortKeys()->all()
        );
        $this->assertSame(2, Invoice::where('status', 'issued')->where('condition', 'credit')->whereDate('due_date', '<', today())
            ->get()->filter(fn (Invoice $invoice) => bccomp($invoice->balance(), '0', 2) > 0)->count());
        $this->assertSame(['cancelled' => 1, 'registered' => 13], $this->statusCounts('payments'));
        $this->assertEqualsCanonicalizing(['cash', 'card', 'transfer', 'check'], DB::table('payments')->distinct()->pluck('method')->all());
        $this->assertTrue(DB::table('payments')->where('change_amount', '>', 0)->exists());
        $this->assertTrue(DB::table('payments')->whereNotNull('receipt_number')->exists());
        $this->assertSame(['cancelled' => 1, 'delivered' => 13], $this->statusCounts('vehicle_deliveries'));
    }

    private function assertPurchasesAndInventory(): void
    {
        // Compras y pagos a proveedores (PurchaseSeeder, SupplierPaymentSeeder).
        $this->assertSame(['cancelled' => 1, 'draft' => 2, 'received' => 5], $this->statusCounts('purchases'));
        $this->assertEqualsCanonicalizing(['0', '5', '10'], DB::table('purchase_items')->distinct()->pluck('tax_rate')->map(fn ($rate) => (string) $rate)->all());
        $this->assertSame(['cancelled' => 1, 'registered' => 5], $this->statusCounts('supplier_payments'));

        // Cuentas por pagar: dos compras a crédito vencidas con saldo (P3 parcial, P4 sin pagar) y una por vencer (P6).
        $payable = Purchase::where('status', 'received')->where('condition', 'credit')->get()
            ->filter(fn (Purchase $purchase) => bccomp($purchase->balance(), '0', 2) > 0);
        $this->assertSame(2, $payable->filter(fn (Purchase $purchase) => $purchase->due_date->lt(today()))->count());
        $this->assertSame(1, $payable->filter(fn (Purchase $purchase) => $purchase->due_date->gte(today()))->count());

        // Cada tipo de movimiento, y el kardex de cada repuesto (saldo acumulado por orden de carga) en orden cronológico.
        $this->assertEqualsCanonicalizing(
            ['initial', 'purchase', 'purchase_cancellation', 'adjustment_in', 'adjustment_out', 'loss', 'supplier_return', 'work_order_out', 'work_order_return'],
            DB::table('inventory_movements')->distinct()->pluck('type')->all()
        );
        foreach (DB::table('inventory_movements')->orderBy('id')->get()->groupBy('part_id') as $movements) {
            $dates = $movements->pluck('created_at')->all();
            $sorted = $dates;
            sort($sorted);
            $this->assertSame($sorted, $dates);
        }
    }

    private function assertNotificationsCommissionsAndMaintenance(): void
    {
        // Avisos a clientes: cada estado y cada tipo; omitidos los de los clientes sin email o dados de baja (C15, C16).
        $this->assertEqualsCanonicalizing(['sent', 'failed', 'skipped', 'pending'], array_keys($this->statusCounts('client_notifications')));
        $this->assertSame(2, DB::table('client_notifications')->where('status', 'skipped')->count());
        $this->assertSame(1, DB::table('client_notifications')->whereNotNull('resent_from_id')->count());
        $this->assertEqualsCanonicalizing(
            ['budget_sent', 'vehicle_ready', 'invoice_overdue', 'maintenance_due_soon', 'maintenance_overdue'],
            DB::table('client_notifications')->distinct()->pluck('type')->all()
        );

        // Avisos internos de cada tipo, leídos y sin leer.
        $this->assertEqualsCanonicalizing(
            ['budget_approved', 'budget_rejected', 'work_order_completed', 'part_out_of_stock', 'extra_part_awaiting_approval', 'accounts_payable_overdue'],
            DB::table('notifications')->distinct()->pluck('type')->all()
        );
        $this->assertTrue(DB::table('notifications')->whereNotNull('read_at')->exists());
        $this->assertTrue(DB::table('notifications')->whereNull('read_at')->exists());

        // Comisiones: liquidadas las de meses cerrados; pendientes solo las del mes en curso.
        $this->assertTrue(DB::table('work_order_commissions')->whereNotNull('settled_at')->exists());
        $this->assertSame(0, DB::table('work_order_commissions')->whereNull('settled_at')->where('earned_at', '<', now()->startOfMonth())->count());

        // Mantenimiento: vencidos y por vencer (alertas).
        $this->assertEqualsCanonicalizing(
            ['overdue', 'due_soon'],
            app(MaintenanceService::class)->alerts(null)->pluck('status.value')->unique()->values()->all()
        );
    }

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        return collect(self::TABLES)->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])->all();
    }

    /**
     * @return array<string, int>
     */
    private function statusCounts(string $table): array
    {
        return DB::table($table)->selectRaw('status, count(*) as total')->groupBy('status')->orderBy('status')
            ->pluck('total', 'status')->map(fn ($total) => (int) $total)->all();
    }
}
