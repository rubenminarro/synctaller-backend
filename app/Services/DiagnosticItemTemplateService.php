<?php

namespace App\Services;

use App\Enums\DiagnosticItemStatus;
use App\Enums\DiagnosticItemTemplateStatus;
use App\Enums\DocumentSeries;
use App\Exceptions\DiagnosticItemTemplateException;
use App\Models\DiagnosticItemTemplate;
use Illuminate\Support\Facades\DB;

/**
 * Catálogo de ítems de diagnóstico. El ítem del diagnóstico copia los valores del catálogo al crearse, así que
 * editar, desactivar o eliminar una entrada no cambia los diagnósticos ya cargados.
 */
class DiagnosticItemTemplateService
{
    /** Campos que el catálogo sugiere al ítem del diagnóstico (el título se copia siempre). */
    public const COPIED_FIELDS = [
        'description',
        'severity',
        'requires_repair',
        'requires_replacement',
        'estimated_time',
        'recommendation',
    ];

    public function __construct(
        protected DocumentSequenceService $documentSequences
    ) {
    }

    public function create(array $data): DiagnosticItemTemplate
    {
        return DB::transaction(function () use ($data) {

            $template = $this->documentSequences->create(
                DocumentSeries::DIAGNOSTIC_ITEM_TEMPLATE,
                fn (string $code) => DiagnosticItemTemplate::create([
                    ...$data,
                    'code' => $code,
                    'status' => DiagnosticItemTemplateStatus::ACTIVE,
                ])
            );

            return $template->refresh()->load('category');
        });
    }

    public function update(DiagnosticItemTemplate $template, array $data): DiagnosticItemTemplate
    {
        return DB::transaction(function () use ($template, $data) {

            $template->update($data);

            return $template->load('category');
        });
    }

    public function activate(DiagnosticItemTemplate $template): DiagnosticItemTemplate
    {
        return $this->transitionStatus($template, DiagnosticItemTemplateStatus::ACTIVE);
    }

    public function deactivate(DiagnosticItemTemplate $template): DiagnosticItemTemplate
    {
        return $this->transitionStatus($template, DiagnosticItemTemplateStatus::INACTIVE);
    }

    /**
     * Baja lógica, aunque esté en uso: los ítems de diagnóstico guardan su copia y conservan el vínculo.
     */
    public function delete(DiagnosticItemTemplate $template): void
    {
        $template->delete();
    }

    /**
     * Datos del ítem del diagnóstico, que siempre sale de una entrada del catálogo: el título es el suyo y los demás
     * campos no enviados (o `null`) se completan con los del catálogo; el estado arranca "pendiente" si no se informa.
     */
    public function prepareDiagnosticItem(array $data): array
    {
        $template = DiagnosticItemTemplate::findOrFail($data['diagnostic_item_template_id']);

        if (!$template->isUsable()) {
            throw new DiagnosticItemTemplateException(
                'El ítem del catálogo está inactivo: no puede usarse en diagnósticos nuevos.',
                422,
                'DIAGNOSTIC_ITEM_TEMPLATE_INACTIVE'
            );
        }

        foreach (self::COPIED_FIELDS as $field) {
            if (!isset($data[$field])) {
                $data[$field] = $template->{$field};
            }
        }

        $data['title'] = $template->title;
        $data['status'] ??= DiagnosticItemStatus::PENDING->value;

        return $data;
    }

    private function transitionStatus(DiagnosticItemTemplate $template, DiagnosticItemTemplateStatus $status): DiagnosticItemTemplate
    {
        return DB::transaction(function () use ($template, $status) {

            $template->refresh();

            if (!$template->status->canTransitionTo($status)) {
                throw new DiagnosticItemTemplateException(
                    "El ítem del catálogo ya está {$this->lower($status)}.",
                    422,
                    'DIAGNOSTIC_ITEM_TEMPLATE_INVALID_STATUS_TRANSITION'
                );
            }

            $template->update(['status' => $status]);

            return $template->load('category');
        });
    }

    private function lower(DiagnosticItemTemplateStatus $status): string
    {
        return mb_strtolower($status->label());
    }
}
