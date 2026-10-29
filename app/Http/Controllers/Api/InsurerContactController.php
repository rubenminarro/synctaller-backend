<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\InsurerContactRequest;
use App\Http\Resources\InsurerContactResource;
use App\Models\Insurer;
use App\Models\InsurerContact;
use App\Services\InsurerService;
use App\Traits\ApiResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Contactos de una aseguradora (insurers/{insurer}/contacts, con scopeBindings).
 */
class InsurerContactController extends Controller implements HasMiddleware
{
    use ApiResponse;

    public function __construct(
        protected InsurerService $insurerService
    ) {
    }

    public static function middleware(): array
    {
        return [
            new Middleware('permission:insurers.show', only: ['index']),
            new Middleware('permission:insurers.update', only: ['store', 'update', 'destroy']),
        ];
    }

    public function index(Insurer $insurer)
    {
        return $this->successResponse(
            'Contactos de la aseguradora obtenidos correctamente.',
            InsurerContactResource::collection($insurer->contacts),
            200
        );
    }

    public function store(InsurerContactRequest $request, Insurer $insurer)
    {
        return $this->successResponse(
            'Contacto creado correctamente.',
            new InsurerContactResource($this->insurerService->addContact($insurer, $request->validated())),
            201
        );
    }

    public function update(InsurerContactRequest $request, Insurer $insurer, InsurerContact $contact)
    {
        return $this->successResponse(
            'Contacto actualizado correctamente.',
            new InsurerContactResource($this->insurerService->updateContact($contact, $request->validated())),
            200
        );
    }

    public function destroy(Insurer $insurer, InsurerContact $contact)
    {
        $this->insurerService->deleteContact($contact);

        return $this->successResponse(
            'Contacto eliminado correctamente.',
            null,
            200
        );
    }
}
