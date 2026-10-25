<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDiagnosticItemRequest;
use App\Http\Requests\UpdateDiagnosticItemRequest;
use App\Http\Resources\DiagnosticItemResource;
use App\Http\Resources\ShowDiagnosticItemResource;
use App\Models\Diagnostic;
use App\Models\DiagnosticItem;
use App\Services\DiagnosticItemTemplateService;
use App\Services\DiagnosticService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class DiagnosticItemController extends Controller implements HasMiddleware
{
    use ApiResponse;

    public static function middleware(): array
    {
        return [
            new Middleware('permission:diagnostic-items.index', only: ['index']),
            new Middleware('permission:diagnostic-items.store', only: ['store']),
            new Middleware('permission:diagnostic-items.show', only: ['show']),
            new Middleware('permission:diagnostic-items.update', only: ['update']),
            new Middleware('permission:diagnostic-items.destroy', only: ['destroy']),
        ];
    }

    public function index(Request $request)
    {
        $search = trim($request->input('search', ''));

        $query = DiagnosticItem::query()
            ->with([
                'photos',
                'template',
            ]);
        
        $query->when($request->filled('search'), function ($query) use ($search) {
            $query->where(function ($q) use ($search) {

                if (is_numeric($search)) {
                    $q->orWhere('id', $search)
                    ->orWhere('diagnostic_id', $search);
                }

                 $q->orWhere('title', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%")
                ->orWhere('severity', 'like', "%{$search}%")
                ->orWhere('status', 'like', "%{$search}%")
                ->orWhere('recommendation', 'like', "%{$search}%");
            });
        });

        $query->when($request->filled('diagnostic_id'), function ($q) use ($request) {
            $q->where('diagnostic_id', $request->diagnostic_id);
        });

        $query->when($request->filled('severity'), function ($q) use ($request) {
            $q->where('severity', $request->severity);
        });

        $query->when($request->filled('status'), function ($q) use ($request) {
            $q->where('status', $request->status);
        });

        $query->when($request->filled('requires_repair'), function ($q) use ($request) {
            $q->where(
                'requires_repair',
                $request->boolean('requires_repair')
            );
        });

        $query->when($request->filled('requires_replacement'), function ($q) use ($request) {
            $q->where(
                'requires_replacement',
                $request->boolean('requires_replacement')
            );
        });

        $diagnosticsItems = $query
            ->latest()
            ->paginate($request->per_page ?? 10);

        return $this->successResponse(
            'Items de diagnósticos obtenidos correctamente.',
            DiagnosticItemResource::collection($diagnosticsItems->items()),
            200,
            [
                'pagination' => [
                    'total'       => $diagnosticsItems->total(),
                    'perPage'     => $diagnosticsItems->perPage(),
                    'currentPage' => $diagnosticsItems->currentPage(),
                    'lastPage'    => $diagnosticsItems->lastPage(),
                ]
            ]
        );
    }

    public function store(
        StoreDiagnosticItemRequest $request,
        DiagnosticService $diagnosticService,
        DiagnosticItemTemplateService $templateService
    ) {
        $data = $request->validated();
        $saveAsTemplate = (bool) ($data['save_as_template'] ?? false);
        unset($data['save_as_template']);

        if ($saveAsTemplate && !$request->user()->can('diagnostic-item-templates.store')) {
            return $this->errorResponse(
                'No tiene permiso para agregar ítems al catálogo de diagnóstico.',
                403,
                'FORBIDDEN'
            );
        }

        $diagnosticService->ensureItemsEditable(Diagnostic::findOrFail($data['diagnostic_id']));

        $diagnosticItem = DB::transaction(function () use ($data, $saveAsTemplate, $templateService) {
            $data = $templateService->prepareDiagnosticItem($data);

            if ($saveAsTemplate) {
                $data['diagnostic_item_template_id'] = $templateService->createFromDiagnosticItem($data)->id;
            }

            return DiagnosticItem::create($data);
        });

        $diagnosticItem->load([
            'photos',
            'template',
        ]);

        return $this->successResponse(
            'Item de diagnóstico creado correctamente.',
            new DiagnosticItemResource($diagnosticItem),
            201
        );
    }

    public function show(DiagnosticItem $diagnosticItem)
    {
        $diagnosticItem->load([
            'diagnostic',
            'photos',
            'template',
        ]);

        return $this->successResponse(
            'Item de diagnóstico obtenido correctamente.',
            new ShowDiagnosticItemResource($diagnosticItem)
        );
    }

    public function update(UpdateDiagnosticItemRequest $request, DiagnosticItem $diagnosticItem, DiagnosticService $diagnosticService)
    {
        $diagnosticService->ensureItemsEditable($diagnosticItem->diagnostic);

        $diagnosticItem->update(
            $request->validated()
        );

        $diagnosticItem->load([
            'diagnostic',
            'template',
        ]);

        return $this->successResponse(
            'Item de diagnóstico actualizado correctamente.',
            new DiagnosticItemResource($diagnosticItem)
        );
    }

    public function destroy(DiagnosticItem $diagnosticItem, DiagnosticService $diagnosticService)
    {
        $diagnosticService->ensureItemsEditable($diagnosticItem->diagnostic);

        $diagnosticItem->load('photos');

        foreach ($diagnosticItem->photos as $photo) {

            if ($photo->path && Storage::disk('public')->exists($photo->path)) {
                Storage::disk('public')->delete($photo->path);
            }

            $photo->delete();
        }

        $diagnosticItem->delete();

        return $this->successResponse(
            'Item de diagnóstico eliminado correctamente.',
            null
        );
    }
    
}
