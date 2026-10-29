<?php

namespace Database\Seeders;

use App\Models\Insurer;
use App\Services\ClaimService;
use Illuminate\Database\Seeder;

/**
 * Siniestros de ejemplo (M3), por ClaimService con el recepcionista del
 * escenario, al llegar el vehículo. Nacen abiertos; el estado final sale de la
 * sincronización con la recepción: C05 y C07 siguen abiertos (open), H07 se entrega
 * (closed) y X01 se cancela (cancelled). Va después de ReceptionSeeder y antes
 * de la entrega y la cancelación. Idempotente: omite la recepción que ya tiene
 * un siniestro.
 */
class ClaimSeeder extends Seeder
{
    /** [escenario => datos del siniestro] */
    private const CLAIMS = [
        'C05' => ['claim_type' => 'own', 'claim_number' => 'SDS-2026-0472', 'policy_number' => 'POL-781240',
            'loss_description' => 'Cayó en un bache profundo en la ruta.', 'adjuster_name' => 'Ramón Giménez',
            'deductible_amount' => 0],
        'C07' => ['claim_type' => 'own', 'claim_number' => 'SDS-2026-0457', 'policy_number' => 'POL-778812',
            'loss_description' => 'Roce con un poste al estacionar.', 'adjuster_name' => 'Ramón Giménez',
            'deductible_amount' => 0],
        'H07' => ['claim_type' => 'third_party', 'claim_number' => 'SDS-2026-0311',
            'insured_name' => 'Pedro Cáceres', 'insured_document' => '3456789',
            'loss_description' => 'Lo chocaron de atrás en un semáforo.', 'adjuster_name' => 'Ramón Giménez',
            'deductible_amount' => 0],
        'X01' => ['claim_type' => 'own', 'claim_number' => 'SDS-2026-0388', 'deductible_amount' => null],
    ];

    public function run(ClaimService $claimService): void
    {
        $insurer = Insurer::whereHas('client', fn ($q) => $q->where('document_number', InsurerSeeder::ACTIVE))->firstOrFail();

        foreach (ExampleScenarios::all() as $scenario) {
            $data = self::CLAIMS[$scenario['key']] ?? null;
            $reception = $data ? ExampleScenarios::reception($scenario) : null;

            if (!$reception || $reception->claims()->exists()) {
                continue;
            }

            ExampleScenarios::travel(ExampleScenarios::at($scenario, 0, 11), fn () => $claimService->create([
                ...$data,
                'reception_id' => $reception->id,
                'insurer_id' => $insurer->id,
                'loss_date' => ExampleScenarios::at($scenario, -2)->toDateString(),
            ], ExampleScenarios::user($scenario['receptionist'])));
        }
    }
}
