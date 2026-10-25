<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDiagnosticItemTemplateRequest;
use App\Http\Requests\UpdateDiagnosticItemTemplateRequest;
use App\Http\Resources\DiagnosticItemTemplateResource;
use App\Models\DiagnosticItemTemplate;
use App\Services\DiagnosticItemTemplateService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class DiagnosticItemTemplateController extends Controller implements HasMiddleware
{
    use ApiResponse;

    public function __construct(
        protected DiagnosticItemTemplateService $templateService
    ) {
    }

    public static function middleware(): array
    {
        return [
            new Middleware('permission:diagnostic-item-templates.index', only: ['index']),
            new Middleware('permission:diagnostic-item-templates.store', only: ['store']),
            new Middleware('permission:diagnostic-item-templates.show', only: ['show']),
            new Middleware('permission:diagnostic-item-templates.update', only: ['update']),
            new Middleware('permission:diagnostic-item-templates.destroy', only: ['destroy']),
            new Middleware('permission:diagnostic-item-templates.change-status', only: ['activate', 'deactivate']),
        ];
    }

    public function index(Request $request)
    {
        $search = trim($request->input('search', ''));

        $query = DiagnosticItemTemplate::query()->with(['category']);

        $query->when($request->filled('search'), function ($query) use ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('title', 'like', "%{$search}%");
            });
        });

        $query->when($request->filled('category_id'), function ($q) use ($request) {
            $q->where('service_category_id', $request->category_id);
        });

        $query->when($request->filled('status'), function ($q) use ($request) {
            $q->where('status', $request->status);
        });

        $templates = $query
            ->orderBy('title')
            ->paginate($request->per_page ?? 10);

        return $this->successResponse(
            'Ítems del catálogo de diagnóstico obtenidos correctamente.',
            DiagnosticItemTemplateResource::collection($templates->items()),
            200,
            [
                'pagination' => [
                    'total'       => $templates->total(),
                    'perPage'     => $templates->perPage(),
                    'currentPage' => $templates->currentPage(),
                    'lastPage'    => $templates->lastPage(),
                ]
            ]
        );
    }

    public function show(DiagnosticItemTemplate $diagnosticItemTemplate)
    {
        return $this->successResponse(
            'Ítem del catálogo de diagnóstico obtenido correctamente.',
            new DiagnosticItemTemplateResource($diagnosticItemTemplate->load('category')),
            200
        );
    }

    public function store(StoreDiagnosticItemTemplateRequest $request)
    {
        $template = $this->templateService->create($request->validated());

        return $this->successResponse(
            'Ítem del catálogo de diagnóstico creado correctamente.',
            new DiagnosticItemTemplateResource($template),
            201
        );
    }

    public function update(UpdateDiagnosticItemTemplateRequest $request, DiagnosticItemTemplate $diagnosticItemTemplate)
    {
        $template = $this->templateService->update($diagnosticItemTemplate, $request->validated());

        return $this->successResponse(
            'Ítem del catálogo de diagnóstico actualizado correctamente.',
            new DiagnosticItemTemplateResource($template),
            200
        );
    }

    public function activate(DiagnosticItemTemplate $diagnosticItemTemplate)
    {
        $template = $this->templateService->activate($diagnosticItemTemplate);

        return $this->successResponse(
            'Ítem del catálogo de diagnóstico activado correctamente.',
            new DiagnosticItemTemplateResource($template),
            200
        );
    }

    public function deactivate(DiagnosticItemTemplate $diagnosticItemTemplate)
    {
        $template = $this->templateService->deactivate($diagnosticItemTemplate);

        return $this->successResponse(
            'Ítem del catálogo de diagnóstico desactivado correctamente.',
            new DiagnosticItemTemplateResource($template),
            200
        );
    }

    public function destroy(DiagnosticItemTemplate $diagnosticItemTemplate)
    {
        $this->templateService->delete($diagnosticItemTemplate);

        return $this->successResponse(
            'Ítem del catálogo de diagnóstico eliminado correctamente.',
            null,
            200
        );
    }
}
