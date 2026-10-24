<?php

namespace Database\Seeders;

use App\Models\InvoiceStamp;
use Illuminate\Database\Seeder;

/**
 * Timbrado de ejemplo (001-001), vigente desde hace seis meses y por un año: cubre las facturas de los escenarios
 * de ejemplo, emitidas en los últimos meses.
 */
class InvoiceStampSeeder extends Seeder
{
    public function run(): void
    {
        InvoiceStamp::firstOrCreate(
            ['stamp_number' => '12345678', 'establishment' => '001', 'expedition_point' => '001'],
            [
                'valid_from' => now()->subMonths(6)->startOfDay(),
                'valid_until' => now()->addMonths(6)->startOfDay(),
                'next_number' => 1,
                'is_active' => true,
            ]
        );
    }
}
