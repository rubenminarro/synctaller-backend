<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Services\InsurerService;
use Illuminate\Database\Seeder;

/**
 * Aseguradoras de ejemplo (M2), creadas por InsurerService con el recepcionista
 * junto con su cliente empresa (que nace con los avisos desactivados): una activa
 * con dos contactos y una inactiva. Idempotente: omite la aseguradora cuyo
 * cliente ya existe.
 */
class InsurerSeeder extends Seeder
{
    /** RUC del cliente de la aseguradora activa. */
    public const ACTIVE = '80098765-4';

    /** RUC del cliente de la aseguradora inactiva. */
    public const INACTIVE = '80076543-2';

    public function run(InsurerService $insurerService): void
    {
        $receptionist = ExampleScenarios::user('recepcion1@mail.com.py');

        $insurers = [
            [self::ACTIVE, 'Seguros del Sur S.A.', 'siniestros@segurosdelsur.example.com', '595212001000', 30, [
                ['name' => 'Laura Benítez', 'role' => 'claims', 'phone' => '595981700100', 'email' => 'lbenitez@segurosdelsur.example.com', 'is_primary' => true],
                ['name' => 'Pedro Ortiz', 'role' => 'billing', 'phone' => '595981700200', 'email' => 'facturacion@segurosdelsur.example.com'],
            ], true],
            [self::INACTIVE, 'Aseguradora Guaraní S.A.', 'contacto@aseguradoraguarani.example.com', '595212003000', 45, [
                ['name' => 'Mirta Acosta', 'role' => 'claims', 'phone' => '595981700300', 'is_primary' => true],
            ], false],
        ];

        foreach ($insurers as [$document, $businessName, $email, $phone, $termDays, $contacts, $active]) {
            if (Client::where('document_number', $document)->exists()) {
                continue;
            }

            ['insurer' => $insurer] = $insurerService->create([
                'client' => [
                    'document_number' => $document,
                    'business_name' => $businessName,
                    'email' => $email,
                    'phone' => $phone,
                    'address' => 'Asunción',
                ],
                'payment_term_days' => $termDays,
            ], $receptionist);

            foreach ($contacts as $contact) {
                $insurerService->addContact($insurer, $contact);
            }

            if (!$active) {
                $insurerService->deactivate($insurer);
            }
        }
    }
}
