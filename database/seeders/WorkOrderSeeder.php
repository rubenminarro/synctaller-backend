<?php

namespace Database\Seeders;

use App\Enums\ClientDecisionChannel;
use App\Enums\WorkOrderItemType;
use App\Enums\WorkOrderPartStatus;
use App\Models\Part;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Models\WorkOrderPart;
use App\Services\WorkOrderCommissionService;
use App\Services\WorkOrderPartService;
use App\Services\WorkOrderService;
use Illuminate\Database\Seeder;

/**
 * Órdenes de trabajo de los escenarios con presupuesto aprobado
 * (ExampleScenarios), con todo su ciclo por los Services: ítems, reparto entre
 * mecánicos y horas, repuestos (reserva, consumo, devolución) y extras con la
 * respuesta del cliente. Estados finales: pendiente, en curso, pausada,
 * completada (con cierre económico y comisiones congeladas) y cancelada.
 *
 * Línea de tiempo (días desde la llegada): se crea el día 1 a la tarde, se
 * inicia el día 2 y se completa `done` días después del día 1. Con `day`, todo corre esos días más tarde (C05: la
 * OT se crea después de la segunda versión de la autorización y de la re-aceptación).
 */
class WorkOrderSeeder extends Seeder
{
    private const MANAGERS = ['jefetaller1@mail.com.py', 'jefetaller2@mail.com.py'];

    public function __construct(
        private readonly WorkOrderService $workOrderService,
        private readonly WorkOrderPartService $workOrderPartService,
        private readonly WorkOrderCommissionService $commissionService
    ) {
    }

    public function run(): void
    {
        foreach (ExampleScenarios::chronological() as $index => $scenario) {
            $budget = isset($scenario['work_order']) ? BudgetSeeder::of($scenario) : null;

            // Idempotente: un presupuesto con OT ya no se vuelve a procesar.
            if (!$budget || $budget->workOrder()->exists()) {
                continue;
            }

            $this->play($scenario, $budget, ExampleScenarios::user(self::MANAGERS[$index % 2]));
        }
    }

    private function play(array $scenario, $budget, $manager): void
    {
        $plan = $scenario['work_order'];
        $at = fn (int $offset, int $hour) => ExampleScenarios::at($scenario, ExampleScenarios::workOrderDay($scenario) + $offset, $hour);
        $mechanic = ExampleScenarios::mechanic($plan['mechanic']);

        $workOrder = ExampleScenarios::travel($at(1, 17), fn () => $this->workOrderService->createFromBudget($budget->refresh(), $mechanic, $manager->id));

        if ($plan['status'] === 'cancelled') {
            ExampleScenarios::travel($at(2, 11), fn () => $this->workOrderService->cancel($workOrder));

            return;
        }

        if ($plan['status'] === 'pending') {
            return;
        }

        ExampleScenarios::travel($at(2, 8), fn () => $this->workOrderService->start($workOrder));

        // Trabajo compartido: el primer ítem de mano de obra o servicio se reparte entre mecánicos.
        if (isset($plan['shared'])) {
            $item = $this->commissionableItems($workOrder)->first();
            $assignments = collect($plan['shared'])
                ->map(fn (int $share, string $code) => ['mechanic_id' => ExampleScenarios::mechanic($code)->id, 'share_percentage' => $share])
                ->values()
                ->all();
            ExampleScenarios::travel($at(2, 9), fn () => $this->commissionService->syncItemMechanics($workOrder, $item, $assignments));
        }

        $this->extras($scenario, $workOrder, $plan['extras'] ?? []);

        $items = $workOrder->items()->orderBy('id')->get();
        ExampleScenarios::travel($at(2, 10), function () use ($workOrder, $items, $plan) {
            // En curso parcial: se inician los dos primeros ítems (el primero se termina después); si no, todos.
            foreach (($plan['partial'] ?? false) ? $items->take(2) : $items as $item) {
                $this->workOrderService->startItem($workOrder, $item);
            }
        });

        if ($plan['status'] === 'paused') {
            ExampleScenarios::travel($at(2, 15), fn () => $this->workOrderService->pause($workOrder));

            return;
        }

        if ($plan['partial'] ?? false) {
            ExampleScenarios::travel($at(2, 16), fn () => $this->workOrderService->completeItem($workOrder, $items->first()));
        }

        if ($plan['status'] !== 'completed') {
            return;
        }

        $this->finish($scenario, $workOrder, $items, $manager, 1 + $plan['done']);
    }

    /**
     * Repuestos extra: los agrega depósito y la respuesta del cliente la registra la recepcionista.
     *
     * @param array<int, array{0: string, 1: int|float, 2: string}> $extras [SKU, cantidad, approve|reject|pending]
     */
    private function extras(array $scenario, WorkOrder $workOrder, array $extras): void
    {
        $storekeeper = ExampleScenarios::user('deposito1@mail.com.py');
        $receptionist = ExampleScenarios::user($scenario['receptionist']);
        $client = $workOrder->reception->client;

        foreach ($extras as [$sku, $quantity, $decision]) {
            $part = Part::where('sku', $sku)->firstOrFail();

            $line = ExampleScenarios::travel(ExampleScenarios::at($scenario, ExampleScenarios::workOrderDay($scenario) + 2, 11), fn () => $this->workOrderPartService->add($workOrder, [
                'part_id' => $part->id,
                'quantity' => $quantity,
                'unit_price' => $part->sale_price,
                'notes' => 'Encontrado durante el trabajo.',
            ], $storekeeper));

            $answer = [
                'channel' => ClientDecisionChannel::PHONE->value,
                'contact' => trim("{$client->first_name} {$client->last_name}"),
                'notes' => $decision === 'reject' ? 'El cliente prefiere hacerlo más adelante.' : 'Aprobado por teléfono.',
            ];

            match ($decision) {
                'approve' => ExampleScenarios::travel(ExampleScenarios::at($scenario, ExampleScenarios::workOrderDay($scenario) + 2, 13), fn () => $this->workOrderPartService->approve($workOrder, $line, $answer, $receptionist)),
                'reject' => ExampleScenarios::travel(ExampleScenarios::at($scenario, ExampleScenarios::workOrderDay($scenario) + 2, 13), fn () => $this->workOrderPartService->reject($workOrder, $line, $answer, $receptionist)),
                default => null,
            };
        }
    }

    /**
     * Cierre del trabajo: horas, consumo de repuestos (y una devolución si el escenario la pide), ítems terminados y
     * OT completada por el jefe de taller.
     */
    private function finish(array $scenario, WorkOrder $workOrder, $items, $manager, int $doneDay): void
    {
        $at = fn (int $hour) => ExampleScenarios::at($scenario, ExampleScenarios::workOrderDay($scenario) + $doneDay, $hour);
        $mechanicUser = $workOrder->mechanic->user;

        ExampleScenarios::travel($at(11), function () use ($workOrder, $mechanicUser) {
            $workOrder->parts()->whereIn('status', [WorkOrderPartStatus::PENDING->value, WorkOrderPartStatus::RESERVED->value])->get()
                ->each(fn (WorkOrderPart $line) => $this->workOrderPartService->consume($workOrder, $line, [
                    'quantity_used' => $line->quantity,
                    'notes' => null,
                ], $mechanicUser));
        });

        if (isset($scenario['work_order']['return'])) {
            $line = $workOrder->parts()->whereHas('part', fn ($query) => $query->where('sku', $scenario['work_order']['return']))->firstOrFail();
            ExampleScenarios::travel($at(12), fn () => $this->workOrderPartService->returnToStock($workOrder, $line, [
                'quantity' => $line->quantity_consumed,
                'reason' => 'No corresponde al modelo del vehículo.',
            ], ExampleScenarios::user('deposito1@mail.com.py')));
        }

        ExampleScenarios::travel($at(14), function () use ($workOrder, $items) {
            foreach ($this->commissionableItems($workOrder) as $item) {
                foreach ($item->mechanicAssignments as $assignment) {
                    $hours = $item->budgetItem?->estimated_hours ?? 1;
                    $this->commissionService->logHours($workOrder, $item, $assignment->mechanic, $hours);
                }
            }

            foreach ($items as $item) {
                $this->workOrderService->completeItem($workOrder, $item->refresh());
            }
        });

        ExampleScenarios::travel($at(17), fn () => $this->workOrderService->complete($workOrder, $manager->id));
    }

    /**
     * Ítems de mano de obra y servicio (los que generan comisión), con sus mecánicos.
     */
    private function commissionableItems(WorkOrder $workOrder)
    {
        return $workOrder->items()
            ->whereIn('type', [WorkOrderItemType::LABOR->value, WorkOrderItemType::SERVICE->value])
            ->with(['mechanicAssignments.mechanic', 'budgetItem'])
            ->orderBy('id')
            ->get();
    }
}
