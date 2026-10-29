<?php

namespace App\Services;

use App\Enums\ClientKind;
use App\Exceptions\ClientException;
use App\Models\Client;
use Illuminate\Support\Facades\DB;

/**
 * Clientes: personas o empresas. La baja libera los campos únicos con un sufijo
 * y no se permite con el cliente en uso (datos maestros, 6.1).
 */
class ClientService
{
    public function create(array $data): Client
    {
        return Client::create($this->withoutOtherKindFields($data, $this->kindOf($data)));
    }

    public function update(Client $client, array $data): Client
    {
        return DB::transaction(function () use ($client, $data) {

            $kind = $this->kindOf($data, $client);

            if ($kind !== $client->kind) {
                // Las facturas y recepciones ya muestran el nombre del cliente: con ellas, el tipo queda fijo.
                if ($client->receptions()->withTrashed()->exists() || $client->invoices()->exists()) {
                    throw new ClientException(
                        'El cliente ya tiene recepciones o facturas: no se puede cambiar de persona a empresa ni al revés.',
                        422,
                        'CLIENT_KIND_NOT_EDITABLE'
                    );
                }

                $data = $this->withoutOtherKindFields($data, $kind);
            }

            $client->update($data);

            return $client;
        });
    }

    public function delete(Client $client): void
    {
        if ($client->vehicles()->exists()
            || $client->receptions()->withTrashed()->exists()
            || $client->invoices()->exists()) {
            throw new ClientException(
                'El cliente tiene vehículos, recepciones o facturas y no puede eliminarse.',
                409,
                'CLIENT_IN_USE'
            );
        }

        DB::transaction(function () use ($client) {
            $suffix = '//deleted_' . now()->timestamp;

            if ($client->document_number) {
                $client->document_number = substr($client->document_number . $suffix, 0, 30);
            }

            if ($client->email) {
                $client->email = $client->email . $suffix;
            }

            $client->save();

            $client->delete();
        });
    }

    private function kindOf(array $data, ?Client $client = null): ClientKind
    {
        return isset($data['kind']) ? ClientKind::from($data['kind']) : ($client?->kind ?? ClientKind::PERSON);
    }

    /**
     * Una persona no lleva razón social y una empresa no lleva nombre ni apellido.
     */
    private function withoutOtherKindFields(array $data, ClientKind $kind): array
    {
        $fields = $kind === ClientKind::COMPANY ? ['first_name', 'last_name'] : ['business_name'];

        return [...$data, ...array_fill_keys($fields, null), 'kind' => $kind];
    }
}
