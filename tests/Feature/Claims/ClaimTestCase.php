<?php

namespace Tests\Feature\Claims;

use App\Models\Brand;
use App\Models\Client;
use App\Models\Reception;
use App\Models\Vehicle;
use App\Models\VehicleModel;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Invoices\InvoiceTestCase;

/**
 * Base de los tests de siniestros: aseguradora, recepción abierta y cuerpo del alta.
 */
abstract class ClaimTestCase extends InvoiceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    protected function insurer(): int
    {
        return $this->postJson('/api/insurers', ['client' => [
            'document_number' => '8009' . random_int(1000, 9999) . '-' . random_int(1, 9),
            'business_name' => 'Seguros del Sur S.A.',
            'email' => Str::lower(Str::random(8)) . '@example.com',
            'phone' => '+595211' . random_int(100000, 999999),
        ]])->assertCreated()->json('data.id');
    }

    protected function openReception(): Reception
    {
        $brand = Brand::create(['name' => 'Marca ' . Str::random(6)]);
        $client = Client::create([
            'document_number' => '4' . random_int(100000, 999999),
            'first_name' => 'Ana',
            'last_name' => 'Gomez',
            'phone' => '0981' . random_int(100000, 999999),
            'email' => 'ana@example.com',
        ]);
        $vehicle = Vehicle::create([
            'client_id' => $client->id,
            'brand_id' => $brand->id,
            'vehicle_model_id' => VehicleModel::create(['brand_id' => $brand->id, 'name' => 'Modelo ' . Str::random(6)])->id,
            'plate' => Str::upper(Str::random(7)),
        ]);

        return Reception::create(['client_id' => $client->id, 'vehicle_id' => $vehicle->id, 'reception_date' => now()->toDateString()]);
    }

    protected function claimBody(int $receptionId, int $insurerId, array $overrides = []): array
    {
        return [
            'reception_id' => $receptionId,
            'insurer_id' => $insurerId,
            'claim_type' => 'own',
            'loss_date' => now()->subDays(3)->toDateString(),
            'claim_number' => 'S-' . random_int(10000, 99999),
            ...$overrides,
        ];
    }
}
