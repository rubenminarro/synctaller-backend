<?php

namespace App\Services;

use App\Enums\DiagnosticItemStatus;
use App\Enums\DiagnosticItemTemplateStatus;
use App\Exceptions\DiagnosticItemTemplateException;
use App\Models\DiagnosticItemTemplate;
use Illuminate\Support\Facades\DB;

/**
 * Catálogo de ítems de diagnóstico. El ítem del diagnóstico copia los valores del catálogo al crearse, así que
 * editar, desactivar o eliminar una entrada no cambia los diagnósticos ya cargados.
 */
class DiagnosticItemTemplateService
{
    /** Campos que el catálogo sugiere al ítem del diagnóstico. */
    public const COPIED_FIELDS = [
        'title',
        'description',
        'severity',
        'requires_repair',
        'requires_replacement',
        'estimated_time',
        'recommendation',
    ];

    public function create(array $data): DiagnosticItemTemplate
    {
        return DB::transaction(function () use ($data) {

            $template = DiagnosticItemTemplate::create([
                ...$data,
                'code' => $this->generateCode(),
                'status' => DiagnosticItemTemplateStatus::ACTIVE,
            ]);

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
     * Datos del ítem del diagnóstico al elegir una entrada del catálogo: los campos no enviados (o `null`) se
     * completan con los del catálogo; el estado del ítem arranca "pendiente" si no se informa.
     */
    public function prepareDiagnosticItem(array $data): array
    {
        if (empty($data['diagnostic_item_template_id'])) {
            return $data;
        }

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

        $data['status'] ??= DiagnosticItemStatus::PENDING->value;

        return $data;
    }

    /**
     * "Guardar en el catálogo" desde un ítem libre: crea la entrada con los campos del ítem.
     */
    public function createFromDiagnosticItem(array $data): DiagnosticItemTemplate
    {
        return $this->create(array_intersect_key($data, array_flip(self::COPIED_FIELDS)));
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

    private function generateCode(): string
    {
        $nextId = (DiagnosticItemTemplate::withTrashed()->max('id') ?? 0) + 1;

        return 'DIA-' . str_pad($nextId, 6, '0', STR_PAD_LEFT);
    }
}
