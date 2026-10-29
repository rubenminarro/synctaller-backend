<?php

namespace App\Services;

use App\Enums\MaintenanceStatus;
use App\Enums\WorkOrderItemStatus;
use App\Enums\WorkOrderItemType;
use App\Exceptions\MaintenanceException;
use App\Models\MaintenancePlan;
use App\Models\Vehicle;
use App\Models\VehicleMaintenance;
use App\Models\WorkOrder;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Mantenimiento preventivo (FASE 19). Un plan vence por km o por fecha, lo
 * que ocurra primero. El km actual se estima con el promedio de km/día entre
 * lecturas (recepciones y entregas, ver VehicleHistoryService::mileage).
 */
class MaintenanceService
{
    public function __construct(
        protected VehicleHistoryService $historyService
    ) {
    }

    public function createPlan(array $data): MaintenancePlan
    {
        // refresh: is_active toma el valor por defecto de la base.
        return MaintenancePlan::create($data)->refresh();
    }

    /**
     * Si cambian los intervalos del plan, se recalcula el próximo vencimiento
     * de los vehículos que usan los intervalos del plan.
     */
    public function updatePlan(MaintenancePlan $plan, array $data): MaintenancePlan
    {
        return DB::transaction(function () use ($plan, $data) {
            $plan->fill($data);

            if ($plan->interval_km === null && $plan->interval_days === null) {
                throw new MaintenanceException(
                    'El plan debe tener un intervalo en km o en días.',
                    422,
                    'MAINTENANCE_INTERVAL_REQUIRED'
                );
            }

            $plan->save();

            if ($plan->wasChanged(['interval_km', 'interval_days'])) {
                $plan->vehicleMaintenances()->with('plan')->get()->each(function (VehicleMaintenance $maintenance) {
                    $this->recalculate($maintenance);
                    $maintenance->save();
                });
            }

            return $plan->refresh();
        });
    }

    public function deletePlan(MaintenancePlan $plan): void
    {
        if ($plan->vehicleMaintenances()->exists()) {
            throw new MaintenanceException(
                'El plan tiene vehículos con seguimiento: desactívelo en lugar de eliminarlo.',
                409,
                'MAINTENANCE_PLAN_IN_USE'
            );
        }

        $plan->delete();
    }

    /**
     * Registro manual (trabajo hecho en otro taller o carga inicial).
     */
    public function recordDone(Vehicle $vehicle, MaintenancePlan $plan, array $data): VehicleMaintenance
    {
        if (!$plan->is_active) {
            throw new MaintenanceException(
                'El plan de mantenimiento está inactivo.',
                422,
                'MAINTENANCE_PLAN_INACTIVE'
            );
        }

        return DB::transaction(fn () => $this->register(
            $vehicle,
            $plan,
            Carbon::parse($data['done_at']),
            isset($data['mileage']) ? (int) $data['mileage'] : null,
            null,
            $data['notes'] ?? null,
            true
        ));
    }

    /**
     * Al completar la OT: cada servicio completado que cumple un plan activo
     * registra el mantenimiento con la fecha de cierre y el km de la recepción.
     * Un registro más viejo que el último conocido se ignora.
     */
    public function registerFromWorkOrder(WorkOrder $workOrder): void
    {
        $workOrder->loadMissing(['items.budgetItem', 'reception']);

        $serviceIds = $workOrder->items
            ->filter(fn ($item) => $item->type === WorkOrderItemType::SERVICE && $item->status === WorkOrderItemStatus::COMPLETED)
            ->map(fn ($item) => $item->budgetItem?->service_id)
            ->filter()
            ->unique();

        if ($serviceIds->isEmpty()) {
            return;
        }

        $vehicle = Vehicle::withTrashed()->findOrFail($workOrder->reception->vehicle_id);

        MaintenancePlan::active()->whereIn('service_id', $serviceIds)->get()->each(
            fn (MaintenancePlan $plan) => $this->register(
                $vehicle,
                $plan,
                Carbon::parse($workOrder->completed_at ?? now()),
                $workOrder->reception->mileage !== null ? (int) $workOrder->reception->mileage : null,
                $workOrder->id,
                null,
                false
            )
        );
    }

    /**
     * Intervalos propios del vehículo (null = vuelve a los del plan).
     */
    public function configure(Vehicle $vehicle, MaintenancePlan $plan, array $data): VehicleMaintenance
    {
        return DB::transaction(function () use ($vehicle, $plan, $data) {
            $maintenance = $this->lockOrNew($vehicle, $plan);

            foreach (['interval_km', 'interval_days', 'notes'] as $field) {
                if (array_key_exists($field, $data)) {
                    $maintenance->{$field} = $data[$field];
                }
            }

            $this->recalculate($maintenance);
            $maintenance->save();

            return $maintenance->load('plan');
        });
    }

    /**
     * Km actual: la última lectura más el promedio de km/día desde entonces.
     *
     * @return array{mileage: ?int, estimated: bool, daily_average: ?float, last_reading_at: ?string}
     */
    public function estimatedMileage(Vehicle $vehicle): array
    {
        $readings = $this->historyService->mileage($vehicle)->reject(fn ($r) => $r['decreased'])->values();

        if ($readings->isEmpty()) {
            return ['mileage' => $vehicle->mileage, 'estimated' => false, 'daily_average' => null, 'last_reading_at' => null];
        }

        $first = $readings->first();
        $last = $readings->last();
        $span = Carbon::parse($first['date'])->diffInDays(Carbon::parse($last['date']));
        $average = $span > 0 && $last['mileage'] > $first['mileage']
            ? ($last['mileage'] - $first['mileage']) / $span
            : null;

        $sinceLast = (int) Carbon::parse($last['date'])->diffInDays(today());
        $estimated = $average !== null && $sinceLast > 0;

        return [
            'mileage' => $estimated ? $last['mileage'] + (int) round($average * $sinceLast) : $last['mileage'],
            'estimated' => $estimated,
            'daily_average' => $average !== null ? round($average, 2) : null,
            'last_reading_at' => $last['date'],
        ];
    }

    /**
     * Estado de cada plan activo en el vehículo (y de los inactivos con seguimiento).
     */
    public function forVehicle(Vehicle $vehicle): array
    {
        $mileage = $this->estimatedMileage($vehicle);

        $tracked = VehicleMaintenance::where('vehicle_id', $vehicle->id)->with('plan')->get()->keyBy('maintenance_plan_id');

        $plans = MaintenancePlan::query()
            ->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $tracked->keys()))
            ->orderBy('name')
            ->get();

        return [
            'current_mileage' => $mileage,
            'maintenances' => $plans->map(function (MaintenancePlan $plan) use ($tracked, $mileage, $vehicle) {
                $maintenance = $tracked->get($plan->id) ?? new VehicleMaintenance([
                    'vehicle_id' => $vehicle->id,
                    'maintenance_plan_id' => $plan->id,
                ]);
                $maintenance->setRelation('plan', $plan);

                return $this->present($maintenance, $mileage['mileage']);
            })->values(),
        ];
    }

    /**
     * Mantenimientos vencidos o por vencer de todos los vehículos (planes activos).
     */
    public function alerts(?string $status): Collection
    {
        $wanted = $status
            ? [MaintenanceStatus::from($status)]
            : [MaintenanceStatus::OVERDUE, MaintenanceStatus::DUE_SOON];

        return VehicleMaintenance::query()
            ->whereNotNull('last_done_at')
            ->whereHas('plan', fn ($q) => $q->where('is_active', true))
            ->whereIn('vehicle_id', Vehicle::select('id'))
            ->with(['plan', 'vehicle.client'])
            ->get()
            ->groupBy('vehicle_id')
            ->flatMap(function (Collection $maintenances) {
                $vehicle = $maintenances->first()->vehicle;
                $mileage = $this->estimatedMileage($vehicle)['mileage'];

                return $maintenances->map(fn (VehicleMaintenance $m) => [
                    'vehicle' => [
                        'id' => $vehicle->id,
                        'plate' => $vehicle->plate,
                        'estimated_mileage' => $mileage,
                    ],
                    'client' => $vehicle->client ? [
                        'id' => $vehicle->client->id,
                        'full_name' => $vehicle->client->display_name,
                        'phone' => $vehicle->client->phone,
                        'email' => $vehicle->client->email,
                    ] : null,
                    ...$this->present($m, $mileage),
                ]);
            })
            ->filter(fn (array $alert) => in_array($alert['status']['value'], array_map(fn ($s) => $s->value, $wanted), true))
            ->sortBy([
                fn ($a, $b) => MaintenanceStatus::from($b['status']['value'])->severity() <=> MaintenanceStatus::from($a['status']['value'])->severity(),
                fn ($a, $b) => ($a['remaining_days'] ?? PHP_INT_MAX) <=> ($b['remaining_days'] ?? PHP_INT_MAX),
            ])
            ->values();
    }

    public function present(VehicleMaintenance $maintenance, ?int $currentMileage): array
    {
        $plan = $maintenance->plan;
        $computed = $this->status($maintenance, $currentMileage);

        return [
            'maintenance_plan_id' => $plan->id,
            'plan' => $plan->name,
            'service_id' => $plan->service_id,
            'is_active' => $plan->is_active,
            'interval_km' => $maintenance->effectiveIntervalKm(),
            'interval_days' => $maintenance->effectiveIntervalDays(),
            'is_custom_interval' => $maintenance->interval_km !== null || $maintenance->interval_days !== null,
            'last_done_at' => $maintenance->last_done_at?->toDateString(),
            'last_done_mileage' => $maintenance->last_done_mileage,
            'work_order_id' => $maintenance->work_order_id,
            'next_due_at' => $maintenance->next_due_at?->toDateString(),
            'next_due_mileage' => $maintenance->next_due_mileage,
            'status' => ['value' => $computed['status']->value, 'label' => $computed['status']->label()],
            'remaining_km' => $computed['remaining_km'],
            'remaining_days' => $computed['remaining_days'],
            'notes' => $maintenance->notes,
        ];
    }

    /**
     * @return array{status: MaintenanceStatus, remaining_km: ?int, remaining_days: ?int}
     */
    private function status(VehicleMaintenance $maintenance, ?int $currentMileage): array
    {
        $remainingKm = null;
        $remainingDays = null;
        $statuses = [];

        if ($maintenance->last_done_at === null) {
            return ['status' => MaintenanceStatus::UNKNOWN, 'remaining_km' => null, 'remaining_days' => null];
        }

        if ($maintenance->next_due_mileage !== null && $currentMileage !== null) {
            $remainingKm = $maintenance->next_due_mileage - $currentMileage;
            $statuses[] = match (true) {
                $remainingKm <= 0 => MaintenanceStatus::OVERDUE,
                $remainingKm <= (int) config('maintenance.due_soon_km', 1000) => MaintenanceStatus::DUE_SOON,
                default => MaintenanceStatus::OK,
            };
        }

        if ($maintenance->next_due_at !== null) {
            $remainingDays = (int) today()->diffInDays($maintenance->next_due_at, false);
            $statuses[] = match (true) {
                $remainingDays < 0 => MaintenanceStatus::OVERDUE,
                $remainingDays <= (int) config('maintenance.due_soon_days', 30) => MaintenanceStatus::DUE_SOON,
                default => MaintenanceStatus::OK,
            };
        }

        $status = collect($statuses)->sortByDesc(fn (MaintenanceStatus $s) => $s->severity())->first()
            ?? MaintenanceStatus::UNKNOWN;

        return ['status' => $status, 'remaining_km' => $remainingKm, 'remaining_days' => $remainingDays];
    }

    private function register(
        Vehicle $vehicle,
        MaintenancePlan $plan,
        CarbonInterface $doneAt,
        ?int $mileage,
        ?int $workOrderId,
        ?string $notes,
        bool $strict
    ): VehicleMaintenance {
        $maintenance = $this->lockOrNew($vehicle, $plan);

        if ($maintenance->last_done_at && $doneAt->copy()->startOfDay()->lt($maintenance->last_done_at)) {
            if (!$strict) {
                return $maintenance;
            }

            throw new MaintenanceException(
                "Ya hay un registro posterior ({$maintenance->last_done_at->toDateString()}).",
                422,
                'MAINTENANCE_DONE_OUT_OF_ORDER'
            );
        }

        if ($strict && $mileage !== null && $maintenance->last_done_mileage !== null && $mileage < $maintenance->last_done_mileage) {
            throw new MaintenanceException(
                "El kilometraje ({$mileage}) es menor al del último registro ({$maintenance->last_done_mileage}).",
                422,
                'MAINTENANCE_MILEAGE_BELOW_LAST'
            );
        }

        $maintenance->fill([
            'last_done_at' => $doneAt->toDateString(),
            'last_done_mileage' => $mileage,
            'work_order_id' => $workOrderId,
        ]);

        if ($notes !== null) {
            $maintenance->notes = $notes;
        }

        $this->recalculate($maintenance);
        $maintenance->save();

        return $maintenance->load('plan');
    }

    private function lockOrNew(Vehicle $vehicle, MaintenancePlan $plan): VehicleMaintenance
    {
        $maintenance = VehicleMaintenance::where('vehicle_id', $vehicle->id)
            ->where('maintenance_plan_id', $plan->id)
            ->lockForUpdate()
            ->first()
            ?? new VehicleMaintenance(['vehicle_id' => $vehicle->id, 'maintenance_plan_id' => $plan->id]);

        return $maintenance->setRelation('plan', $plan);
    }

    /**
     * Próximo vencimiento = último realizado + intervalo efectivo.
     */
    private function recalculate(VehicleMaintenance $maintenance): void
    {
        $days = $maintenance->effectiveIntervalDays();
        $km = $maintenance->effectiveIntervalKm();

        $maintenance->next_due_at = $maintenance->last_done_at && $days
            ? $maintenance->last_done_at->copy()->addDays($days)->toDateString()
            : null;

        $maintenance->next_due_mileage = $maintenance->last_done_mileage !== null && $km
            ? $maintenance->last_done_mileage + $km
            : null;
    }
}
