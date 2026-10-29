<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInsurerRequest;
use App\Http\Requests\UpdateInsurerRequest;
use App\Http\Resources\InsurerResource;
use App\Http\Resources\ShowInsurerResource;
use App\Models\Insurer;
use App\Services\InsurerService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class InsurerController extends Controller implements HasMiddleware
{
    use ApiResponse;

    public function __construct(
        protected InsurerService $insurerService
    ) {
    }

    public static function middleware(): array
    {
        return [
            new Middleware('permission:insurers.index', only: ['index']),
            new Middleware('permission:insurers.store', only: ['store']),
            new Middleware('permission:insurers.show', only: ['show']),
            new Middleware('permission:insurers.update', only: ['update']),
            new Middleware('permission:insurers.destroy', only: ['destroy']),
            new Middleware('permission:insurers.change-status', only: ['activate', 'deactivate']),
        ];
    }

    public function index(Request $request)
    {
        $search = trim($request->input('search', ''));

        $insurers = Insurer::query()
            ->with('client')
            ->withCount('contacts')
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('code', 'like', "%{$search}%")
                        ->orWhereHas('client', fn ($c) => $c->where('business_name', 'like', "%{$search}%")
                            ->orWhere('document_number', 'like', "%{$search}%"));
                });
            })
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->orderBy('code')
            ->paginate($request->per_page ?? 10);

        return $this->successResponse(
            'Aseguradoras obtenidas correctamente.',
            InsurerResource::collection($insurers->items()),
            200,
            [
                'pagination' => [
                    'total'       => $insurers->total(),
                    'perPage'     => $insurers->perPage(),
                    'currentPage' => $insurers->currentPage(),
                    'lastPage'    => $insurers->lastPage(),
                ]
            ]
        );
    }

    public function store(StoreInsurerRequest $request)
    {
        ['insurer' => $insurer, 'warnings' => $warnings] = $this->insurerService->create($request->insurerData(), $request->user());

        return $this->successResponse(
            'Aseguradora creada correctamente.',
            new ShowInsurerResource($insurer),
            201,
            $warnings ? ['warnings' => $warnings] : null
        );
    }

    public function show(Insurer $insurer)
    {
        return $this->successResponse(
            'Aseguradora obtenida correctamente.',
            new ShowInsurerResource($insurer->load(['client', 'contacts'])),
            200
        );
    }

    public function update(UpdateInsurerRequest $request, Insurer $insurer)
    {
        $insurer = $this->insurerService->update($insurer, $request->validated());

        return $this->successResponse(
            'Aseguradora actualizada correctamente.',
            new ShowInsurerResource($insurer),
            200
        );
    }

    public function activate(Insurer $insurer)
    {
        return $this->successResponse(
            'Aseguradora activada correctamente.',
            new ShowInsurerResource($this->insurerService->activate($insurer)),
            200
        );
    }

    public function deactivate(Insurer $insurer)
    {
        return $this->successResponse(
            'Aseguradora desactivada correctamente.',
            new ShowInsurerResource($this->insurerService->deactivate($insurer)),
            200
        );
    }

    public function destroy(Insurer $insurer)
    {
        $this->insurerService->delete($insurer);

        return $this->successResponse(
            'Aseguradora eliminada correctamente.',
            null,
            200
        );
    }
}
