<?php

namespace Database\Seeders;

use App\Enums\DiagnosticItemTemplateStatus;
use App\Enums\Severity;
use App\Models\DiagnosticItemTemplate;
use App\Models\ServiceCategory;
use App\Services\DiagnosticItemTemplateService;
use Illuminate\Database\Seeder;

/**
 * Catálogo de ítems de diagnóstico habituales, por categoría de servicio. Uno queda inactivo (estado de ejemplo).
 * Incluye cada título de DiagnosticItemSeeder: los ítems de los diagnósticos salen siempre del catálogo.
 */
class DiagnosticItemTemplateSeeder extends Seeder
{
    /**
     * [categoría, título, descripción, severidad, requiere reparación, requiere reemplazo, minutos, recomendación]
     */
    private const ITEMS = [
        ['mechanical', 'Aceite de motor vencido', 'Revisar kilometraje del último cambio, nivel y color del aceite.', Severity::MEDIUM, false, true, 30, 'Cambio de aceite y filtro.'],
        ['mechanical', 'Correa de distribución', 'Revisar grietas, desgaste y juego del tensor.', Severity::CRITICAL, false, true, 240, 'Cambiar el kit de distribución completo.'],
        ['mechanical', 'Bujías de encendido', 'Revisar desgaste y carbonización de los electrodos.', Severity::MEDIUM, false, true, 40, 'Reemplazar el juego de bujías.'],
        ['mechanical', 'Pastillas delanteras desgastadas', 'Medir el espesor de las pastillas delanteras.', Severity::HIGH, false, true, 60, 'Reemplazar pastillas delanteras.'],
        ['mechanical', 'Líquido de frenos', 'Revisar nivel y humedad del líquido de frenos.', Severity::MEDIUM, false, true, 20, 'Reemplazar el líquido de frenos.'],
        ['mechanical', 'Disco de freno delantero alabeado', 'Medir alabeo y espesor del disco.', Severity::HIGH, false, true, 60, 'Reemplazar el disco.'],
        ['mechanical', 'Filtro de aire saturado', 'Revisar el estado del filtro de aire del motor.', Severity::MEDIUM, false, true, 10, 'Reemplazar el filtro de aire.'],
        ['mechanical', 'Filtro de habitáculo', 'Revisar suciedad del filtro y olor en el aire acondicionado.', Severity::LOW, false, true, 10, 'Reemplazar el filtro.'],
        ['mechanical', 'Nivel de refrigerante', 'Revisar nivel y pérdidas del sistema de refrigeración.', Severity::HIGH, false, true, 15, 'Completar con refrigerante.'],
        ['mechanical', 'Amortiguador delantero', 'Revisar pérdidas de aceite y rebote.', Severity::CRITICAL, false, true, 120, 'Reemplazar ambos amortiguadores delanteros.'],
        ['mechanical', 'Bujes de parrilla', 'Revisar grietas y juego en los bujes.', Severity::HIGH, false, true, 90, 'Reemplazar el juego de bujes.'],
        ['mechanical', 'Kit de embrague', 'Revisar patinamiento y recorrido del pedal.', Severity::HIGH, false, true, 360, 'Reemplazar el kit de embrague.'],
        ['mechanical', 'Pérdida de aceite del motor', 'Ubicar el origen de la pérdida (juntas, retenes, cárter).', Severity::HIGH, true, false, 90, 'Reemplazar la junta o el retén afectado.'],
        ['mechanical', 'Escape y silenciador', 'Revisar fijaciones, pérdidas y ruidos del escape.', Severity::MEDIUM, true, false, 60, 'Reparar o reemplazar el tramo dañado.'],
        ['mechanical', 'Nivel de líquido de dirección', 'Revisar nivel y pérdidas de la dirección hidráulica.', Severity::LOW, false, false, 10, 'Completar el nivel y controlar pérdidas.'],
        ['electrical', 'Batería', 'Medir tensión en reposo y hacer prueba de carga.', Severity::MEDIUM, false, true, 30, 'Reemplazar la batería si no retiene carga.'],
        ['electrical', 'Lámparas delanteras', 'Revisar luces bajas, altas y de posición.', Severity::LOW, false, true, 15, 'Reemplazar la lámpara.'],
        ['electrical', 'Circuito de luces traseras', 'Revisar el circuito con el tester.', Severity::MEDIUM, true, false, 45, 'Reparar el cableado o el conector afectado.'],
        ['electrical', 'Sensor de temperatura', 'Revisar lectura y conectores del sensor.', Severity::MEDIUM, true, false, 45, 'Limpiar conectores y volver a medir.'],
        ['electrical', 'Alternador', 'Medir la tensión de carga con el motor en marcha.', Severity::CRITICAL, true, false, 180, 'Reparar el alternador.'],
        ['electrical', 'Motor de arranque', 'Revisar consumo y funcionamiento del burro de arranque.', Severity::HIGH, true, false, 120, 'Reparar o reemplazar el motor de arranque.'],
        ['electrical', 'Sistema de limpiaparabrisas', 'Revisar motor, escobillas y sapito.', Severity::LOW, false, false, 15, 'Reemplazar las escobillas si barren mal.'],
        ['electrical', 'Aire acondicionado sin enfriar', 'Medir presiones y revisar el compresor.', Severity::MEDIUM, true, false, 60, 'Cargar gas y revisar pérdidas.'],
        ['tires', 'Alineación del tren delantero', 'Medir convergencia y caída.', Severity::MEDIUM, true, false, 60, 'Alinear y balancear.'],
        ['tires', 'Neumáticos', 'Medir la banda de rodamiento y revisar deformaciones.', Severity::HIGH, false, true, 60, 'Reemplazar los neumáticos.'],
        ['tires', 'Presión de neumáticos', 'Medir la presión de las cuatro ruedas.', Severity::LOW, false, false, 10, 'Ajustar a la presión del fabricante.'],
        ['tires', 'Rueda de auxilio', 'Revisar presión y estado de la rueda de auxilio.', Severity::LOW, false, false, 10, 'Inflar o reemplazar la rueda de auxilio.'],
        ['bodywork', 'Abolladura en carrocería', 'Revisar golpes y daño estructural.', Severity::MEDIUM, true, false, 480, 'Desabollar y pintar.'],
        ['bodywork', 'Pintura opaca', 'Revisar oxidación del barniz.', Severity::LOW, true, false, 180, 'Pulido y encerado.'],
        ['bodywork', 'Rayones en paragolpes', 'Revisar profundidad de los rayones.', Severity::LOW, true, false, 120, 'Pulir o repintar el paragolpes.'],
        ['general', 'Inspección general de seguridad', 'Frenos, luces, neumáticos, cinturones y fluidos.', Severity::LOW, false, false, 30, 'Informar al cliente lo observado.'],
    ];

    /** Inactivo: ya no se ofrece en diagnósticos nuevos. */
    private const INACTIVE = ['mechanical', 'Carburador', 'Revisar mezcla y ralentí del carburador.', Severity::MEDIUM, true, false, 120, 'Limpiar y regular el carburador.'];

    public function run(DiagnosticItemTemplateService $templateService): void
    {
        $categories = ServiceCategory::pluck('id', 'code');

        foreach ([...self::ITEMS, self::INACTIVE] as [$category, $title, $description, $severity, $repair, $replacement, $minutes, $recommendation]) {
            // Idempotente: una entrada por título.
            if (DiagnosticItemTemplate::where('title', $title)->exists()) {
                continue;
            }

            $template = $templateService->create([
                'service_category_id' => $categories[$category] ?? null,
                'title' => $title,
                'description' => $description,
                'severity' => $severity->value,
                'requires_repair' => $repair,
                'requires_replacement' => $replacement,
                'estimated_time' => $minutes,
                'recommendation' => $recommendation,
            ]);

            if ($title === self::INACTIVE[1]) {
                $templateService->deactivate($template);
            }
        }
    }
}
