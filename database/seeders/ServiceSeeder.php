<?php

namespace Database\Seeders;

use App\Models\Service;
use App\Models\ServiceCategory;
use App\Services\ServiceCatalogService;
use Illuminate\Database\Seeder;

/**
 * Catálogo de servicios por categoría. "Lavado de motor" queda inactivo para
 * probar que no se ofrece en los presupuestos.
 */
class ServiceSeeder extends Seeder
{
    public function run(ServiceCatalogService $serviceCatalogService): void
    {
        $services = [
            ['category' => 'general', 'name' => 'Diagnóstico general', 'standard_price' => 150000, 'estimated_hours' => 1],
            ['category' => 'general', 'name' => 'Escaneo computarizado', 'standard_price' => 180000, 'estimated_hours' => 1],
            ['category' => 'general', 'name' => 'Lavado de motor', 'standard_price' => 70000, 'estimated_hours' => 1, 'inactive' => true],
            ['category' => 'mechanical', 'name' => 'Cambio de aceite y filtro', 'standard_price' => 80000, 'estimated_hours' => 0.5],
            ['category' => 'mechanical', 'name' => 'Alineación y balanceo', 'standard_price' => 120000, 'estimated_hours' => 1],
            ['category' => 'mechanical', 'name' => 'Instalación de amortiguadores', 'standard_price' => 200000, 'estimated_hours' => 2],
            ['category' => 'mechanical', 'name' => 'Cambio de pastillas de freno', 'standard_price' => 150000, 'estimated_hours' => 1.5],
            ['category' => 'mechanical', 'name' => 'Cambio de correa de distribución', 'standard_price' => 650000, 'estimated_hours' => 4],
            ['category' => 'mechanical', 'name' => 'Afinamiento de motor', 'standard_price' => 300000, 'estimated_hours' => 2.5],
            ['category' => 'mechanical', 'name' => 'Cambio de embrague', 'standard_price' => 900000, 'estimated_hours' => 6],
            ['category' => 'bodywork', 'name' => 'Desabollado y preparación de puerta', 'standard_price' => 450000, 'estimated_hours' => 6],
            ['category' => 'bodywork', 'name' => 'Pintura general de puerta trasera', 'standard_price' => 500000, 'estimated_hours' => 8],
            ['category' => 'bodywork', 'name' => 'Pulido y encerado', 'standard_price' => 250000, 'estimated_hours' => 3],
            ['category' => 'electrical', 'name' => 'Diagnóstico eléctrico', 'standard_price' => 100000, 'estimated_hours' => 1.5],
            ['category' => 'electrical', 'name' => 'Cambio de batería', 'standard_price' => 50000, 'estimated_hours' => 0.5],
            ['category' => 'electrical', 'name' => 'Reparación de alternador', 'standard_price' => 350000, 'estimated_hours' => 3],
            ['category' => 'tires', 'name' => 'Rotación de neumáticos', 'standard_price' => 60000, 'estimated_hours' => 0.5],
            ['category' => 'tires', 'name' => 'Montaje y balanceo de neumáticos', 'standard_price' => 100000, 'estimated_hours' => 1],
        ];

        foreach ($services as $data) {
            if (Service::withTrashed()->where('name', $data['name'])->exists()) {
                continue;
            }

            $category = ServiceCategory::where('code', $data['category'])->first();

            if (!$category) {
                continue;
            }

            $service = $serviceCatalogService->create([
                'service_category_id' => $category->id,
                'name' => $data['name'],
                'standard_price' => $data['standard_price'],
                'estimated_hours' => $data['estimated_hours'],
            ]);

            if ($data['inactive'] ?? false) {
                $serviceCatalogService->deactivate($service);
            }
        }
    }
}
