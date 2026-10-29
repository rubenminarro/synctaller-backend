<?php

namespace App\Http\Requests;

use App\Enums\ClientKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator as ValidatorInstance;

/**
 * Alta de aseguradora: con client_id de un cliente empresa existente o con los
 * datos de su cliente (client), uno de los dos. Los datos del cliente se validan
 * con las mismas reglas que el alta de un cliente empresa (StoreClientRequest).
 */
class StoreInsurerRequest extends FormRequest
{
    private array $clientData = [];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_id' => [
                'required_without:client',
                'prohibits:client',
                'integer',
                Rule::exists('clients', 'id')->whereNull('deleted_at'),
            ],
            'client' => [
                'required_without:client_id',
                'array',
            ],
            'payment_term_days' => [
                'nullable',
                'integer',
                'min:0',
                'max:365',
            ],
            'notes' => [
                'nullable',
                'string',
                'max:1000',
                'regex:/^[\pL\pN\s.,;:()\-#@!?%]*$/u',
            ],
        ];
    }

    public function after(): array
    {
        return [
            function (ValidatorInstance $validator) {
                if (!is_array($this->input('client'))) {
                    return;
                }

                $clientRequest = StoreClientRequest::create('/', 'POST', [
                    ...$this->input('client'),
                    'kind' => ClientKind::COMPANY->value,
                ]);

                $clientValidator = Validator::make($clientRequest->all(), $clientRequest->rules(), $clientRequest->messages());

                if ($clientValidator->fails()) {
                    foreach ($clientValidator->errors()->messages() as $field => $messages) {
                        foreach ($messages as $message) {
                            $validator->errors()->add("client.{$field}", $message);
                        }
                    }

                    return;
                }

                $this->clientData = $clientValidator->validated();
            },
        ];
    }

    /**
     * Datos validados, con los del cliente ya filtrados por sus reglas.
     */
    public function insurerData(): array
    {
        $data = $this->validated();

        if (isset($data['client'])) {
            $data['client'] = $this->clientData;
        }

        return $data;
    }

    public function messages(): array
    {
        return [
            'client_id' => [
                'required_without' => 'Indique el cliente empresa (client_id) o los datos del cliente nuevo (client).',
                'prohibits' => 'Envíe client_id o client, no los dos.',
                'integer' => 'El cliente debe ser un identificador válido.',
                'exists' => 'El cliente seleccionado no existe.',
            ],
            'client' => [
                'required_without' => 'Indique el cliente empresa (client_id) o los datos del cliente nuevo (client).',
                'array' => 'Los datos del cliente deben ser un objeto.',
            ],
            'payment_term_days' => [
                'integer' => 'El plazo de pago debe ser un número entero de días.',
                'min' => 'El plazo de pago no puede ser negativo.',
                'max' => 'El plazo de pago no puede superar los 365 días.',
            ],
            'notes' => [
                'string' => 'Las notas deben ser un texto válido.',
                'max' => 'Las notas no deben superar los 1000 caracteres.',
                'regex' => 'Las notas contienen caracteres no permitidos.',
            ],
        ];
    }
}
