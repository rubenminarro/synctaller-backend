<?php

namespace Database\Seeders;

use App\Models\Mechanic;
use App\Models\WorkOrderCommission;
use App\Services\WorkOrderCommissionService;
use Illuminate\Database\Seeder;

/**
 * Liquidación de comisiones (solo el administrador). Las comisiones se congelan
 * solas al completar cada OT (WorkOrderSeeder); acá se liquidan las de los
 * meses ya cerrados, el primer día del mes siguiente, y quedan pendientes las
 * del mes en curso.
 */
class CommissionSeeder extends Seeder
{
    public function run(WorkOrderCommissionService $commissionService): void
    {
        $admin = ExampleScenarios::user('admin@mail.com.py');
        $currentMonth = now()->startOfMonth();

        foreach (Mechanic::orderBy('id')->get() as $mechanic) {
            $months = WorkOrderCommission::where('mechanic_id', $mechanic->id)
                ->whereNull('settled_at')
                ->where('earned_at', '<', $currentMonth)
                ->pluck('earned_at')
                ->map(fn ($earnedAt) => $earnedAt->copy()->startOfMonth())
                ->unique(fn ($month) => $month->toDateString())
                ->sort();

            // Idempotente: solo quedan meses cerrados con comisiones sin liquidar.
            foreach ($months as $month) {
                ExampleScenarios::travel(
                    $month->copy()->addMonth()->setTime(10, 0)->toImmutable(),
                    fn () => $commissionService->settle($mechanic, $month->copy(), $month->copy()->endOfMonth(), $admin->id)
                );
            }
        }
    }
}
