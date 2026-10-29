<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DestroyClaimDocumentRequest;
use App\Http\Requests\UploadClaimDocumentRequest;
use App\Http\Resources\ClaimDocumentResource;
use App\Models\Claim;
use App\Models\ClaimDocument;
use App\Services\ClaimDocumentService;
use App\Traits\ApiResponse;
use App\Traits\FileResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Documentos de un siniestro (claims/{claim}/documents, con scopeBindings: un
 * documento de otro siniestro o dado de baja responde 404).
 */
class ClaimDocumentController extends Controller implements HasMiddleware
{
    use ApiResponse, FileResponse;

    public function __construct(
        protected ClaimDocumentService $documentService
    ) {
    }

    public static function middleware(): array
    {
        return [
            new Middleware('permission:claim-documents.index', only: ['index']),
            new Middleware('permission:claim-documents.store', only: ['store']),
            new Middleware('permission:claim-documents.download', only: ['download']),
            new Middleware('permission:claim-documents.destroy', only: ['destroy']),
        ];
    }

    public function index(Claim $claim)
    {
        return $this->successResponse(
            'Documentos del siniestro obtenidos correctamente.',
            ClaimDocumentResource::collection($claim->documents()->with('uploader')->get()),
            200
        );
    }

    public function store(UploadClaimDocumentRequest $request, Claim $claim)
    {
        $document = $this->documentService->store($claim, $request->file('file'), $request->validated(), $request->user());

        return $this->successResponse(
            'Documento subido correctamente.',
            new ClaimDocumentResource($document),
            201
        );
    }

    public function download(Request $request, Claim $claim, ClaimDocument $document)
    {
        return $this->storedFileResponse($this->documentService->download($document, $request->user()));
    }

    public function destroy(DestroyClaimDocumentRequest $request, Claim $claim, ClaimDocument $document)
    {
        $this->documentService->delete($document, $request->validated('reason'), $request->user());

        return $this->successResponse(
            'Documento dado de baja correctamente.',
            null,
            200
        );
    }
}
