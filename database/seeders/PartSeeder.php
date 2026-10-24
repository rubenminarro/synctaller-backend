<?php

namespace Database\Seeders;

use App\Enums\PartStatus;
use App\Enums\PartUnit;
use App\Models\Part;
use App\Models\PartCategory;
use App\Models\Supplier;
use App\Services\PartService;
use Illuminate\Database\Seeder;

/**
 * Catálogo de repuestos con su proveedor preferido. Uno queda inactivo y otro
 * descontinuado (sin stock: no admite entradas). El stock lo carga
 * InventorySeeder.
 */
class PartSeeder extends Seeder
{
    public function run(PartService $partService): void
    {
        $parts = [
            ['sku' => 'FIL-ACE-001', 'name' => 'Filtro de aceite', 'brand' => 'Mann', 'category' => 'Filtros', 'unit' => PartUnit::UNIT, 'cost_price' => 35000, 'sale_price' => 50000, 'minimum_stock' => 5, 'supplier' => '80000001-1'],
            ['sku' => 'FIL-AIR-001', 'name' => 'Filtro de aire', 'brand' => 'Mann', 'category' => 'Filtros', 'unit' => PartUnit::UNIT, 'cost_price' => 45000, 'sale_price' => 65000, 'minimum_stock' => 5, 'supplier' => '80000001-1'],
            ['sku' => 'FIL-HAB-001', 'name' => 'Filtro de habitáculo', 'brand' => 'Mann', 'category' => 'Filtros', 'unit' => PartUnit::UNIT, 'cost_price' => 40000, 'sale_price' => 60000, 'minimum_stock' => 3, 'supplier' => '80000001-1'],
            ['sku' => 'FIL-COM-001', 'name' => 'Filtro de combustible', 'brand' => 'Bosch', 'category' => 'Filtros', 'unit' => PartUnit::UNIT, 'cost_price' => 55000, 'sale_price' => 80000, 'minimum_stock' => 2, 'supplier' => '80000001-1', 'status' => PartStatus::INACTIVE],
            ['sku' => 'FRE-PAS-001', 'name' => 'Juego de pastillas de freno delanteras', 'brand' => 'Bosch', 'category' => 'Frenos', 'unit' => PartUnit::SET, 'cost_price' => 180000, 'sale_price' => 250000, 'minimum_stock' => 2, 'supplier' => '80000003-3'],
            ['sku' => 'FRE-DIS-001', 'name' => 'Disco de freno delantero', 'brand' => 'Fremax', 'category' => 'Frenos', 'unit' => PartUnit::UNIT, 'cost_price' => 210000, 'sale_price' => 290000, 'minimum_stock' => 2, 'supplier' => '80000003-3'],
            ['sku' => 'FRE-LIQ-001', 'name' => 'Líquido de frenos DOT 4', 'brand' => 'Bosch', 'category' => 'Frenos', 'unit' => PartUnit::LITER, 'cost_price' => 30000, 'sale_price' => 45000, 'minimum_stock' => 5, 'supplier' => '80000002-2'],
            ['sku' => 'LUB-5W30-001', 'name' => 'Aceite de motor 5W-30 sintético', 'brand' => 'Shell', 'category' => 'Lubricantes', 'unit' => PartUnit::LITER, 'cost_price' => 55000, 'sale_price' => 75000, 'minimum_stock' => 20, 'supplier' => '80000002-2'],
            ['sku' => 'LUB-20W50-001', 'name' => 'Aceite de motor 20W-50 mineral', 'brand' => 'Shell', 'category' => 'Lubricantes', 'unit' => PartUnit::LITER, 'cost_price' => 32000, 'sale_price' => 45000, 'minimum_stock' => 0, 'supplier' => '80000002-2', 'status' => PartStatus::DISCONTINUED],
            ['sku' => 'REF-REF-001', 'name' => 'Refrigerante concentrado', 'brand' => 'Prestone', 'category' => 'Refrigeración', 'unit' => PartUnit::LITER, 'cost_price' => 40000, 'sale_price' => 58000, 'minimum_stock' => 10, 'supplier' => '80000002-2'],
            ['sku' => 'SUS-AMO-001', 'name' => 'Amortiguador delantero', 'brand' => 'Monroe', 'category' => 'Suspensión', 'unit' => PartUnit::UNIT, 'cost_price' => 520000, 'sale_price' => 650000, 'minimum_stock' => 4, 'supplier' => '80000003-3'],
            ['sku' => 'SUS-BUJ-001', 'name' => 'Juego de bujes de parrilla', 'brand' => 'Sampel', 'category' => 'Suspensión', 'unit' => PartUnit::SET, 'cost_price' => 90000, 'sale_price' => 120000, 'minimum_stock' => 2, 'supplier' => '80000003-3'],
            ['sku' => 'MOT-BUJ-001', 'name' => 'Bujía de encendido', 'brand' => 'NGK', 'category' => 'Motor', 'unit' => PartUnit::UNIT, 'cost_price' => 25000, 'sale_price' => 38000, 'minimum_stock' => 8, 'supplier' => '80000001-1'],
            ['sku' => 'MOT-COR-001', 'name' => 'Kit de correa de distribución', 'brand' => 'Gates', 'category' => 'Motor', 'unit' => PartUnit::KIT, 'cost_price' => 480000, 'sale_price' => 620000, 'minimum_stock' => 1, 'supplier' => '80000001-1'],
            ['sku' => 'TRA-EMB-001', 'name' => 'Kit de embrague', 'brand' => 'Luk', 'category' => 'Transmisión', 'unit' => PartUnit::KIT, 'cost_price' => 950000, 'sale_price' => 1250000, 'minimum_stock' => 1, 'supplier' => '80000001-1'],
            ['sku' => 'ELE-BAT-001', 'name' => 'Batería 12 V 75 Ah', 'brand' => 'Moura', 'category' => 'Eléctrico', 'unit' => PartUnit::UNIT, 'cost_price' => 650000, 'sale_price' => 850000, 'minimum_stock' => 2, 'supplier' => '80000004-4'],
            ['sku' => 'ELE-LAM-001', 'name' => 'Lámpara H4', 'brand' => 'Philips', 'category' => 'Eléctrico', 'unit' => PartUnit::UNIT, 'cost_price' => 15000, 'sale_price' => 25000, 'minimum_stock' => 10, 'supplier' => '80000004-4'],
            ['sku' => 'NEU-205-001', 'name' => 'Neumático 205/55 R16', 'brand' => 'Pirelli', 'category' => 'Neumáticos', 'unit' => PartUnit::UNIT, 'cost_price' => 420000, 'sale_price' => 550000, 'minimum_stock' => 4, 'supplier' => '80000001-1'],
        ];

        foreach ($parts as $data) {
            if (Part::withTrashed()->where('sku', $data['sku'])->exists()) {
                continue;
            }

            $category = PartCategory::where('name', $data['category'])->firstOrFail();

            $part = $partService->create([
                'sku' => $data['sku'],
                'name' => $data['name'],
                'brand' => $data['brand'],
                'part_category_id' => $category->id,
                'unit' => $data['unit'],
                'cost_price' => $data['cost_price'],
                'sale_price' => $data['sale_price'],
                'minimum_stock' => $data['minimum_stock'],
            ]);

            $supplier = Supplier::where('tax_id', $data['supplier'])->first();

            if ($supplier) {
                $partService->attachSupplier($part, $supplier, [
                    'last_cost' => $data['cost_price'],
                    'is_preferred' => true,
                ]);
            }

            match ($data['status'] ?? PartStatus::ACTIVE) {
                PartStatus::INACTIVE => $partService->deactivate($part),
                PartStatus::DISCONTINUED => $partService->discontinue($part),
                default => null,
            };
        }
    }
}
