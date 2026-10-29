<?php

namespace App\Services;

use App\Enums\ClientKind;
use App\Enums\DocumentSeries;
use App\Enums\InsurerStatus;
use App\Exceptions\InsurerException;
use App\Models\Client;
use App\Models\Insurer;
use App\Models\InsurerContact;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Aseguradoras (M2). Se crean sobre un cliente empresa existente o junto con su
 * cliente, en una transacción. Un cliente tiene como máximo una aseguradora
 * vigente. Avisos por email (decisión D): el cliente creado con la aseguradora
 * nace con los avisos desactivados; uno existente conserva su configuración.
 */
class InsurerService
{
    public function __construct(
        protected ClientService $clientService,
        protected DocumentSequenceService $documentSequences
    ) {
    }

    /**
     * @return array{insurer: Insurer, warnings: string[]}
     */
    public function create(array $data, User $user): array
    {
        return DB::transaction(function () use ($data, $user) {

            $warnings = [];

            if (isset($data['client'])) {
                $client = $this->clientService->create([
                    ...$data['client'],
                    'kind' => ClientKind::COMPANY->value,
                    'notifications_enabled' => false,
                ]);
            } else {
                $client = Client::whereKey($data['client_id'])->lockForUpdate()->firstOrFail();

                if ($client->kind !== ClientKind::COMPANY) {
                    throw new InsurerException(
                        'Una aseguradora solo puede vincularse a un cliente empresa.',
                        422,
                        'INSURER_CLIENT_NOT_COMPANY'
                    );
                }

                if ($client->notifications_enabled) {
                    $warnings[] = 'El cliente vinculado conserva sus avisos por email activos (presupuestos, vehículo '
                        . 'listo, facturas y mantenimiento). Si no deben enviarse, desactívelos en la ficha del cliente.';
                }
            }

            if ($client->insurer()->exists()) {
                throw new InsurerException(
                    'El cliente ya está vinculado a una aseguradora.',
                    409,
                    'INSURER_CLIENT_ALREADY_LINKED'
                );
            }

            $insurer = $this->documentSequences->create(DocumentSeries::INSURER, fn (string $code) => Insurer::create([
                'client_id' => $client->id,
                'code' => $code,
                'status' => InsurerStatus::ACTIVE,
                'payment_term_days' => $data['payment_term_days'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
            ]));

            return ['insurer' => $insurer->load(['client', 'contacts']), 'warnings' => $warnings];
        });
    }

    /**
     * Plazo de pago y notas. Los datos fiscales y de contacto se editan en el cliente.
     */
    public function update(Insurer $insurer, array $data): Insurer
    {
        $insurer->update($data);

        return $insurer->load(['client', 'contacts']);
    }

    public function activate(Insurer $insurer): Insurer
    {
        return $this->transitionStatus($insurer, InsurerStatus::ACTIVE);
    }

    public function deactivate(Insurer $insurer): Insurer
    {
        return $this->transitionStatus($insurer, InsurerStatus::INACTIVE);
    }

    /**
     * Baja lógica. Con siniestros no se elimina (INSURER_IN_USE), desde M3. El cliente queda como está.
     */
    public function delete(Insurer $insurer): void
    {
        $insurer->delete();
    }

    public function addContact(Insurer $insurer, array $data): InsurerContact
    {
        return DB::transaction(function () use ($insurer, $data) {
            Insurer::whereKey($insurer->id)->lockForUpdate()->first();

            $contact = $insurer->contacts()->create($data);

            if ($contact->is_primary) {
                $this->makeOnlyPrimary($contact);
            }

            return $contact;
        });
    }

    public function updateContact(InsurerContact $contact, array $data): InsurerContact
    {
        return DB::transaction(function () use ($contact, $data) {
            Insurer::whereKey($contact->insurer_id)->lockForUpdate()->first();

            $contact->update($data);

            if ($contact->is_primary) {
                $this->makeOnlyPrimary($contact);
            }

            return $contact;
        });
    }

    public function deleteContact(InsurerContact $contact): void
    {
        $contact->delete();
    }

    /**
     * Un solo contacto principal por aseguradora: marcar uno desmarca los demás (por el modelo, así se audita).
     */
    private function makeOnlyPrimary(InsurerContact $contact): void
    {
        InsurerContact::where('insurer_id', $contact->insurer_id)
            ->whereKeyNot($contact->id)
            ->where('is_primary', true)
            ->get()
            ->each(fn (InsurerContact $other) => $other->update(['is_primary' => false]));
    }

    private function transitionStatus(Insurer $insurer, InsurerStatus $status): Insurer
    {
        return DB::transaction(function () use ($insurer, $status) {

            $insurer = Insurer::whereKey($insurer->id)->lockForUpdate()->firstOrFail();

            if (!$insurer->status->canTransitionTo($status)) {
                throw new InsurerException(
                    'La aseguradora ya está ' . mb_strtolower($status->label()) . '.',
                    422,
                    'INSURER_INVALID_STATUS_TRANSITION'
                );
            }

            $insurer->update(['status' => $status]);

            return $insurer->load(['client', 'contacts']);
        });
    }
}
