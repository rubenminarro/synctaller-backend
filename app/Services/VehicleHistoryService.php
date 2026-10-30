<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Enums\VehicleDeliveryStatus;
use App\Enums\WorkOrderPartStatus;
use App\Enums\WorkOrderStatus;
use App\Models\Invoice;
use App\Models\Reception;
use App\Models\Vehicle;
use App\Models\VehicleDelivery;
use App\Models\WorkOrder;
use App\Models\WorkOrderClosing;
use App\Models\WorkOrderPart;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Historial del vehículo (FASE 18). Solo lectura: reúne recepciones,
 * diagnósticos, presupuestos, OT, repuestos, facturas, pagos y entregas.
 * Los costos y el margen se incluyen solo si $withEconomics.
 */
class VehicleHistoryService
{
    public function summary(Vehicle $vehicle, bool $withEconomics): array
    {
        $receptions = Reception::where('vehicle_id', $vehicle->id);
        $workOrderIds = WorkOrder::whereIn('reception_id', (clone $receptions)->select('id'))->pluck('id');

        $invoices = Invoice::query()
            ->whereIn('work_order_id', $workOrderIds)
            ->where('status', InvoiceStatus::ISSUED)
            ->with(['payments', 'creditNotes'])
            ->get();

        $summary = [
            'visits' => (clone $receptions)->count(),
            'first_visit' => $this->date((clone $receptions)->min('reception_date')),
            'last_visit' => $this->date((clone $receptions)->max('reception_date')),
            'last_mileage' => $this->mileage($vehicle)->last()['mileage'] ?? $vehicle->mileage,
            'work_orders_completed' => WorkOrder::whereIn('id', $workOrderIds)->where('status', WorkOrderStatus::COMPLETED)->count(),
            'last_service_at' => WorkOrder::whereIn('id', $workOrderIds)->where('status', WorkOrderStatus::COMPLETED)->max('completed_at'),
            'invoiced_total' => $invoices->reduce(fn (string $sum, Invoice $i) => bcadd($sum, $i->total, 2), '0.00'),
            'balance_due' => $invoices->reduce(fn (string $sum, Invoice $i) => bcadd($sum, $i->balance(), 2), '0.00'),
            // Facturado y saldo por destinatario (M9): el dueño del vehículo y los demás (por ejemplo, una aseguradora).
            'by_recipient' => collect(['owner' => true, 'others' => false])->map(function (bool $owner) use ($invoices, $vehicle) {
                $group = $invoices->filter(fn (Invoice $i) => ($i->client_id === $vehicle->client_id) === $owner);

                return [
                    'invoiced_total' => $group->reduce(fn (string $sum, Invoice $i) => bcadd($sum, $i->total, 2), '0.00'),
                    'balance_due' => $group->reduce(fn (string $sum, Invoice $i) => bcadd($sum, $i->balance(), 2), '0.00'),
                ];
            })->all(),
        ];

        if ($withEconomics) {
            $closings = WorkOrderClosing::whereIn('work_order_id', $workOrderIds)->get();

            $summary['economics'] = [
                'sale_total' => $closings->reduce(fn (string $s, $c) => bcadd($s, $c->sale_total, 2), '0.00'),
                'cost_total' => $closings->reduce(fn (string $s, $c) => bcadd($s, $c->cost_total, 2), '0.00'),
                'gross_margin' => $closings->reduce(fn (string $s, $c) => bcadd($s, $c->gross_margin, 2), '0.00'),
            ];
        }

        return $summary;
    }

    /**
     * Una entrada por recepción, de la más reciente a la más antigua.
     */
    public function timeline(Vehicle $vehicle, int $perPage): LengthAwarePaginator
    {
        return Reception::query()
            ->where('vehicle_id', $vehicle->id)
            ->with([
                'serviceCategories',
                'diagnostics.items',
                'budgets',
                'workOrders.mechanic.user',
                'workOrders.items',
                'workOrders.parts.part',
                'workOrders.closing',
                'workOrders.currentInvoice.payments',
                'deliveries' => fn ($q) => $q->where('status', VehicleDeliveryStatus::DELIVERED),
            ])
            ->orderByDesc('reception_date')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * Repuestos realmente usados (cantidad neta > 0) en las OT del vehículo.
     */
    public function parts(Vehicle $vehicle, int $perPage): LengthAwarePaginator
    {
        return WorkOrderPart::query()
            ->whereIn('status', [WorkOrderPartStatus::CONSUMED, WorkOrderPartStatus::RETURNED])
            ->whereColumn('quantity_consumed', '>', 'quantity_returned')
            ->whereHas('workOrder.reception', fn ($q) => $q->where('vehicle_id', $vehicle->id))
            ->with(['part', 'workOrder'])
            ->orderByDesc('consumed_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * MySQL devuelve 'Y-m-d' y SQLite 'Y-m-d H:i:s' para una columna date.
     */
    private function date(?string $value): ?string
    {
        return $value ? Carbon::parse($value)->toDateString() : null;
    }

    /**
     * Lecturas de kilometraje (ingresos y entregas) en orden cronológico
     * (mismo día: por la hora en que se registró).
     * decreased = la lectura es menor que la anterior (posible error de carga).
     */
    public function mileage(Vehicle $vehicle): Collection
    {
        $receptions = Reception::query()
            ->where('vehicle_id', $vehicle->id)
            ->whereNotNull('mileage')
            ->get()
            ->map(fn (Reception $r) => [
                'date' => $r->reception_date->toDateString(),
                'mileage' => (int) $r->mileage,
                'source' => 'reception',
                'reference_id' => $r->id,
                'order' => $r->reception_date->toDateString() . ' ' . $r->created_at?->format('H:i:s') . ' 0',
            ]);

        $deliveries = VehicleDelivery::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('status', VehicleDeliveryStatus::DELIVERED)
            ->get()
            ->map(fn (VehicleDelivery $d) => [
                'date' => $d->delivered_at->toDateString(),
                'mileage' => (int) $d->final_mileage,
                'source' => 'delivery',
                'reference_id' => $d->id,
                'order' => $d->delivered_at->format('Y-m-d H:i:s') . ' 1',
            ]);

        $previous = null;

        return $receptions->concat($deliveries)
            ->sortBy('order')
            ->values()
            ->map(function (array $reading) use (&$previous) {
                unset($reading['order']);
                $reading['decreased'] = $previous !== null && $reading['mileage'] < $previous;
                $previous = $reading['mileage'];

                return $reading;
            });
    }
}
