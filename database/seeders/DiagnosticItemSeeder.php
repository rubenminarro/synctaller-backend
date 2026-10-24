<?php

namespace Database\Seeders;

use App\Enums\DiagnosticItemStatus;
use App\Enums\Severity;
use App\Models\Diagnostic;
use App\Models\DiagnosticItem;
use Illuminate\Database\Seeder;

/**
 * Ítems de los diagnósticos de cada escenario (ExampleScenarios), a partir de
 * plantillas que cubren todas las severidades y todos los estados de ítem.
 */
class DiagnosticItemSeeder extends Seeder
{
    /**
     * Plantillas de ítem por clave (los escenarios las nombran en sus diagnósticos).
     */
    public const TEMPLATES = [
        'oil_due' => ['title' => 'Aceite de motor vencido', 'description' => 'El aceite superó el kilometraje recomendado.', 'severity' => Severity::MEDIUM, 'status' => DiagnosticItemStatus::REPLACE_REQUIRED, 'requires_repair' => false, 'requires_replacement' => true, 'estimated_cost' => 380000, 'estimated_time' => 30, 'recommendation' => 'Cambio de aceite y filtro.'],
        'wipers_ok' => ['title' => 'Sistema de limpiaparabrisas', 'description' => 'El sistema funciona correctamente.', 'severity' => Severity::LOW, 'status' => DiagnosticItemStatus::NOT_APPLICABLE, 'requires_repair' => false, 'requires_replacement' => false, 'estimated_cost' => 0, 'estimated_time' => 0, 'recommendation' => 'No requiere intervención.'],
        'timing_belt' => ['title' => 'Correa de distribución', 'description' => 'Grietas en la correa y tensor con juego.', 'severity' => Severity::CRITICAL, 'status' => DiagnosticItemStatus::REPLACE_REQUIRED, 'requires_repair' => false, 'requires_replacement' => true, 'estimated_cost' => 1270000, 'estimated_time' => 240, 'recommendation' => 'Cambiar el kit de distribución completo.'],
        'spark_plugs' => ['title' => 'Bujías de encendido', 'description' => 'Electrodos desgastados y carbonizados.', 'severity' => Severity::MEDIUM, 'status' => DiagnosticItemStatus::REPLACE_REQUIRED, 'requires_repair' => false, 'requires_replacement' => true, 'estimated_cost' => 152000, 'estimated_time' => 40, 'recommendation' => 'Reemplazar el juego de bujías.'],
        'brake_pads' => ['title' => 'Pastillas delanteras desgastadas', 'description' => 'Las pastillas tienen menos del 10% de vida útil.', 'severity' => Severity::HIGH, 'status' => DiagnosticItemStatus::REPLACE_REQUIRED, 'requires_repair' => false, 'requires_replacement' => true, 'estimated_cost' => 400000, 'estimated_time' => 60, 'recommendation' => 'Reemplazar pastillas delanteras.'],
        'brake_fluid' => ['title' => 'Líquido de frenos', 'description' => 'Nivel bajo y humedad por encima de lo admitido.', 'severity' => Severity::MEDIUM, 'status' => DiagnosticItemStatus::OBSERVATION, 'requires_repair' => false, 'requires_replacement' => true, 'estimated_cost' => 45000, 'estimated_time' => 20, 'recommendation' => 'Reemplazar el líquido de frenos.'],
        'brake_disc' => ['title' => 'Disco de freno delantero alabeado', 'description' => 'Alabeo fuera de tolerancia.', 'severity' => Severity::HIGH, 'status' => DiagnosticItemStatus::REPLACE_REQUIRED, 'requires_repair' => false, 'requires_replacement' => true, 'estimated_cost' => 290000, 'estimated_time' => 60, 'recommendation' => 'Reemplazar el disco.'],
        'alignment' => ['title' => 'Alineación del tren delantero', 'description' => 'Convergencia fuera de rango.', 'severity' => Severity::MEDIUM, 'status' => DiagnosticItemStatus::REPAIR_REQUIRED, 'requires_repair' => true, 'requires_replacement' => false, 'estimated_cost' => 120000, 'estimated_time' => 60, 'recommendation' => 'Alinear y balancear.'],
        'battery' => ['title' => 'Batería', 'description' => 'No retiene carga.', 'severity' => Severity::HIGH, 'status' => DiagnosticItemStatus::REPLACE_REQUIRED, 'requires_repair' => false, 'requires_replacement' => true, 'estimated_cost' => 900000, 'estimated_time' => 30, 'recommendation' => 'Reemplazar la batería.'],
        'battery_ok' => ['title' => 'Batería', 'description' => 'Batería en buen estado.', 'severity' => Severity::LOW, 'status' => DiagnosticItemStatus::OK, 'requires_repair' => false, 'requires_replacement' => false, 'estimated_cost' => 0, 'estimated_time' => 10, 'recommendation' => 'Continuar con controles periódicos.'],
        'headlight' => ['title' => 'Lámparas delanteras', 'description' => 'Una lámpara quemada.', 'severity' => Severity::LOW, 'status' => DiagnosticItemStatus::OBSERVATION, 'requires_repair' => false, 'requires_replacement' => true, 'estimated_cost' => 25000, 'estimated_time' => 15, 'recommendation' => 'Reemplazar la lámpara.'],
        'rear_lights_check' => ['title' => 'Circuito de luces traseras', 'description' => 'Pendiente de revisión con el tester.', 'severity' => Severity::MEDIUM, 'status' => DiagnosticItemStatus::PENDING, 'requires_repair' => true, 'requires_replacement' => false, 'estimated_cost' => 0, 'estimated_time' => 45, 'recommendation' => null],
        'paint_dull' => ['title' => 'Pintura opaca', 'description' => 'Oxidación superficial del barniz.', 'severity' => Severity::LOW, 'status' => DiagnosticItemStatus::REPAIR_REQUIRED, 'requires_repair' => true, 'requires_replacement' => false, 'estimated_cost' => 250000, 'estimated_time' => 180, 'recommendation' => 'Pulido y encerado.'],
        'air_filter' => ['title' => 'Filtro de aire saturado', 'description' => 'Restricción del flujo de aire.', 'severity' => Severity::MEDIUM, 'status' => DiagnosticItemStatus::REPLACE_REQUIRED, 'requires_repair' => false, 'requires_replacement' => true, 'estimated_cost' => 65000, 'estimated_time' => 10, 'recommendation' => 'Reemplazar el filtro de aire.'],
        'cabin_filter' => ['title' => 'Filtro de habitáculo', 'description' => 'Filtro sucio, olor en el aire acondicionado.', 'severity' => Severity::LOW, 'status' => DiagnosticItemStatus::OBSERVATION, 'requires_repair' => false, 'requires_replacement' => true, 'estimated_cost' => 60000, 'estimated_time' => 10, 'recommendation' => 'Reemplazar el filtro.'],
        'sensor_fault' => ['title' => 'Sensor de temperatura', 'description' => 'Lectura intermitente del sensor.', 'severity' => Severity::MEDIUM, 'status' => DiagnosticItemStatus::REPAIR_REQUIRED, 'requires_repair' => true, 'requires_replacement' => false, 'estimated_cost' => 180000, 'estimated_time' => 45, 'recommendation' => 'Limpiar conectores y volver a medir.'],
        'coolant_low' => ['title' => 'Nivel de refrigerante', 'description' => 'Nivel por debajo del mínimo.', 'severity' => Severity::HIGH, 'status' => DiagnosticItemStatus::OBSERVATION, 'requires_repair' => false, 'requires_replacement' => true, 'estimated_cost' => 116000, 'estimated_time' => 15, 'recommendation' => 'Completar con refrigerante.'],
        'shock_absorber' => ['title' => 'Amortiguador delantero', 'description' => 'Se observa pérdida de aceite en el amortiguador.', 'severity' => Severity::CRITICAL, 'status' => DiagnosticItemStatus::REPLACE_REQUIRED, 'requires_repair' => false, 'requires_replacement' => true, 'estimated_cost' => 1500000, 'estimated_time' => 120, 'recommendation' => 'Reemplazar ambos amortiguadores delanteros.'],
        'bushings' => ['title' => 'Bujes de parrilla', 'description' => 'Bujes agrietados con juego.', 'severity' => Severity::HIGH, 'status' => DiagnosticItemStatus::REPLACE_REQUIRED, 'requires_repair' => false, 'requires_replacement' => true, 'estimated_cost' => 240000, 'estimated_time' => 90, 'recommendation' => 'Reemplazar el juego de bujes.'],
        'tires_worn' => ['title' => 'Neumáticos', 'description' => 'Banda de rodamiento por debajo de 1,6 mm.', 'severity' => Severity::HIGH, 'status' => DiagnosticItemStatus::REPLACE_REQUIRED, 'requires_repair' => false, 'requires_replacement' => true, 'estimated_cost' => 2200000, 'estimated_time' => 60, 'recommendation' => 'Reemplazar los neumáticos.'],
        'door_dent' => ['title' => 'Abolladura en puerta trasera', 'description' => 'Golpe sin daño estructural.', 'severity' => Severity::MEDIUM, 'status' => DiagnosticItemStatus::REPAIR_REQUIRED, 'requires_repair' => true, 'requires_replacement' => false, 'estimated_cost' => 950000, 'estimated_time' => 840, 'recommendation' => 'Desabollar y pintar.'],
        'alternator' => ['title' => 'Alternador', 'description' => 'Tensión de carga por debajo de 13 V.', 'severity' => Severity::CRITICAL, 'status' => DiagnosticItemStatus::REPAIR_REQUIRED, 'requires_repair' => true, 'requires_replacement' => false, 'estimated_cost' => 350000, 'estimated_time' => 180, 'recommendation' => 'Reparar el alternador.'],
        'clutch_worn' => ['title' => 'Kit de embrague', 'description' => 'Disco de embrague al límite.', 'severity' => Severity::HIGH, 'status' => DiagnosticItemStatus::REPLACE_REQUIRED, 'requires_repair' => false, 'requires_replacement' => true, 'estimated_cost' => 2150000, 'estimated_time' => 360, 'recommendation' => 'Reemplazar el kit de embrague.'],
    ];

    public function run(): void
    {
        foreach (ExampleScenarios::chronological() as $scenario) {
            $reception = ExampleScenarios::reception($scenario);

            if (!$reception) {
                continue;
            }

            foreach ($scenario['diagnostics'] as [, , , $complaint, , , $items]) {
                $diagnostic = Diagnostic::where('reception_id', $reception->id)->where('customer_complaint', $complaint)->first();

                if (!$diagnostic) {
                    continue;
                }

                ExampleScenarios::travel($diagnostic->created_at->toImmutable()->addMinutes(20), function () use ($diagnostic, $items) {
                    foreach ($items as $key) {
                        $template = self::TEMPLATES[$key];

                        // Idempotente: un ítem por título en cada diagnóstico.
                        DiagnosticItem::firstOrCreate(
                            ['diagnostic_id' => $diagnostic->id, 'title' => $template['title']],
                            [
                                ...$template,
                                'severity' => $template['severity']->value,
                                'status' => $template['status']->value,
                            ]
                        );
                    }
                });
            }
        }
    }
}
