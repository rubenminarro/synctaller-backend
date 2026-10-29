<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInternalExceptionRequest;
use App\Http\Resources\InternalExceptionResource;
use App\Models\InternalException;
use App\Services\InternalExceptionService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Excepciones internas del flujo de siniestros (M7). Solo se otorgan y se
 * consultan: no se editan ni se eliminan.
 */
class InternalExceptionController extends Controller implements HasMiddleware
{
    use ApiResponse;

    public function __construct(
        protected InternalExceptionService $exceptionService
    ) {
    }

    public static function middleware(): array
    {
        return [
            new Middleware('permission:internal-exceptions.index', only: ['index']),
            new Middleware('permission:internal-exceptions.grant', only: ['store']),
            new Middleware('permission:internal-exceptions.show', only: ['show']),
        ];
    }

    public function index(Request $request)
    {
        $exceptions = InternalException::query()
            ->with(['subject', 'consumedBy', 'grantor'])
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->type))
            ->when($request->filled('subject_type'), fn ($q) => $q->where('subject_type', $request->subject_type))
            ->when($request->filled('subject_id'), fn ($q) => $q->where('subject_id', (int) $request->subject_id))
            ->when($request->has('consumed'), fn ($q) => $request->boolean('consumed') ? $q->whereNotNull('consumed_at') : $q->whereNull('consumed_at'))
            ->latest('id')
            ->paginate($request->per_page ?? 10);

        return $this->successResponse(
            'Excepciones internas obtenidas correctamente.',
            InternalExceptionResource::collection($exceptions->items()),
            200,
            [
                'pagination' => [
                    'total'       => $exceptions->total(),
                    'perPage'     => $exceptions->perPage(),
                    'currentPage' => $exceptions->currentPage(),
                    'lastPage'    => $exceptions->lastPage(),
                ]
            ]
        );
    }

    public function store(StoreInternalExceptionRequest $request)
    {
        return $this->successResponse(
            'Excepción interna otorgada correctamente.',
            new InternalExceptionResource($this->exceptionService->grant($request->validated(), $request->user())),
            201
        );
    }

    public function show(InternalException $internalException)
    {
        return $this->successResponse(
            'Excepción interna obtenida correctamente.',
            new InternalExceptionResource($this->exceptionService->load($internalException)),
            200
        );
    }
}
