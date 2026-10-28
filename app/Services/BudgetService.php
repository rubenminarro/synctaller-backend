<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\BudgetItem;
use App\Models\Diagnostic;
use App\Models\Part;
use App\Models\Service;
use App\Enums\BudgetItemType;
use App\Enums\BudgetStatus;
use App\Enums\DiagnosticStatus;
use App\Enums\DocumentSeries;
use App\Enums\ClientNotificationType;
use App\Exceptions\BudgetException;
use App\Exceptions\PartException;
use App\Exceptions\ServiceException;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

class BudgetService
{
    public function __construct(
        protected ReceptionStatusService $receptionStatusService,
        protected ClientNotificationService $clientNotificationService,
        protected InternalNotificationService $internalNotificationService,
        protected DocumentSequenceService $documentSequences
    ) {
    }

    /**
     * Total del ítem (cantidad × precio, sin IVA) con BCMath.
     */
    public function itemTotal(int|float|string $quantity, int|float|string $unitPrice): string
    {
        return Decimal::round(bcmul(Decimal::of($quantity, 2), Decimal::of($unitPrice, 2), 4), 2);
    }

    public function recalculateBudget(Budget $budget): Budget
    {
        $subtotal = $budget->items()->pluck('total')
            ->reduce(fn (string $sum, $total) => bcadd($sum, Decimal::of($total, 2), 2), '0.00');

        // Misma tasa con la que nacen las líneas de la factura (porcentaje entero).
        $taxRate = (string) (int) config('invoice.default_tax_rate', 10);

        $tax = Decimal::round(bcdiv(bcmul($subtotal, $taxRate, 4), '100', 4), 2);

        $total = bcadd($subtotal, $tax, 2);

        $budget->update([
            'subtotal' => $subtotal,
            'tax' => $tax,
            'total' => $total,
        ]);

        return $budget->refresh();
    }

    public function send(Budget $budget): Budget
    {
        if ($budget->status !== BudgetStatus::DRAFT) {
            throw new BudgetException(
                'Solo se pueden enviar presupuestos en estado borrador.',
                422,
                'BUDGET_NOT_SENDABLE'
            );
        }

        $this->receptionStatusService->ensureEditable($budget->reception_id);

        if (!$budget->items()->exists()) {
            throw new BudgetException(
                'No se puede enviar un presupuesto sin ítems.',
                422,
                'BUDGET_WITHOUT_ITEMS'
            );
        }

        // El borrador se arma libremente; al cliente se le envía con el diagnóstico cerrado.
        $openDiagnostics = Diagnostic::where('reception_id', $budget->reception_id)
            ->whereIn('status', [DiagnosticStatus::PENDING->value, DiagnosticStatus::IN_PROGRESS->value])
            ->orderBy('id')
            ->pluck('id');

        if ($openDiagnostics->isNotEmpty()) {
            throw new BudgetException(
                'La recepción tiene diagnósticos sin terminar (' . $openDiagnostics->map(fn ($id) => "#{$id}")->implode(', ')
                    . '): termínelos o cancélelos antes de enviar el presupuesto.',
                422,
                'BUDGET_DIAGNOSTICS_PENDING'
            );
        }

        return DB::transaction(function () use ($budget) {
            $budget->update([
                'status' => BudgetStatus::SENT,
            ]);

            // Aviso al cliente (cada envío, también tras reabrir).
            $this->clientNotificationService->notify(
                ClientNotificationType::BUDGET_SENT,
                $budget,
                $budget->reception?->client
            );

            return $budget->refresh();
        });
    }

    public function approve(Budget $budget): Budget
    {
        if ($budget->status !== BudgetStatus::SENT) {
            throw new BudgetException(
                'Solo se pueden aprobar presupuestos enviados.',
                422,
                'BUDGET_NOT_APPROVABLE'
            );
        }

        $this->receptionStatusService->ensureEditable($budget->reception_id);

        if (! $budget->items()->exists()) {
            throw new BudgetException(
                'No se puede aprobar un presupuesto sin items.',
                422,
                'BUDGET_WITHOUT_ITEMS'
            );
        }

        return DB::transaction(function () use ($budget) {
            $this->recalculateBudget($budget);

            $budget->update([
                'status' => BudgetStatus::APPROVED,
                'approved_at' => now(),
            ]);

            $this->internalNotificationService->budgetDecided($budget);

            return $budget->refresh();
        });
    }

    public function reject(Budget $budget): Budget
    {
        if ($budget->status !== BudgetStatus::SENT) {
            throw new BudgetException(
                'Solo se pueden rechazar presupuestos enviados.',
                422,
                'BUDGET_NOT_REJECTABLE'
            );
        }

        $this->receptionStatusService->ensureEditable($budget->reception_id);

        return DB::transaction(function () use ($budget) {
            $budget->update([
                'status' => BudgetStatus::REJECTED,
            ]);

            $this->internalNotificationService->budgetDecided($budget);

            return $budget->refresh();
        });
    }

    public function cancel(Budget $budget): Budget
    {
        if (! in_array($budget->status, [
            BudgetStatus::DRAFT,
            BudgetStatus::SENT,
        ], true)) {
            throw new BudgetException(
                'El presupuesto no puede ser cancelado en su estado actual.',
                422,
                'BUDGET_NOT_CANCELLABLE'
            );
        }

        $budget->update([
            'status' => BudgetStatus::CANCELLED,
        ]);

        return $budget->refresh();
    }

    public function reopen(Budget $budget): Budget
    {
        if ($budget->status !== BudgetStatus::REJECTED) {
            throw new BudgetException(
                'Solo se pueden reabrir presupuestos rechazados.',
                422,
                'BUDGET_NOT_REOPENABLE'
            );
        }

        $this->receptionStatusService->ensureEditable($budget->reception_id);

        $budget->update([
            'status' => BudgetStatus::DRAFT,
            'approved_at' => null,
        ]);

        return $budget->refresh();
    }

    /**
     * Normaliza el vínculo con los catálogos de un ítem: solo los repuestos
     * llevan part_id y solo los servicios llevan service_id.
     */
    public function prepareCatalogItem(array $data, ?BudgetItem $item = null): array
    {
        $type = isset($data['type'])
            ? BudgetItemType::from($data['type'])
            : $item?->type;

        if ($type !== BudgetItemType::PART) {
            $data['part_id'] = null;
        }

        if ($type !== BudgetItemType::SERVICE) {
            $data['service_id'] = null;
        }

        // Los repuestos no llevan tiempo estimado.
        if ($type === BudgetItemType::PART) {
            $data['estimated_hours'] = null;
        }

        return match ($type) {
            BudgetItemType::PART => $this->preparePartItem($data, $item),
            BudgetItemType::SERVICE => $this->prepareServiceItem($data, $item),
            default => $data,
        };
    }

    /**
     * El servicio debe estar activo; sin precio informado se usa el
     * precio estándar del catálogo.
     */
    private function prepareServiceItem(array $data, ?BudgetItem $item): array
    {
        $serviceId = array_key_exists('service_id', $data) ? $data['service_id'] : $item?->service_id;

        if (!$serviceId) {
            return $data;
        }

        $service = Service::findOrFail($serviceId);

        if (!$service->isUsable()) {
            throw new ServiceException(
                'El servicio está inactivo y no puede presupuestarse.',
                422,
                'SERVICE_NOT_USABLE'
            );
        }

        $serviceChanged = $item === null || (int) $item->service_id !== (int) $serviceId;

        if (!isset($data['unit_price']) && $serviceChanged) {
            $data['unit_price'] = $service->standard_price;
        }

        // Tiempo estimado: el informado, o el del catálogo × cantidad cuando
        // cambia el servicio o la cantidad.
        $quantityChanged = isset($data['quantity'])
            && ($item === null || bccomp((string) $data['quantity'], (string) $item->quantity, 2) !== 0);

        if (
            !array_key_exists('estimated_hours', $data) &&
            ($serviceChanged || $quantityChanged) &&
            $service->estimated_hours !== null
        ) {
            $quantity = (string) ($data['quantity'] ?? $item?->quantity);

            $data['estimated_hours'] = Decimal::round(bcmul($service->estimated_hours, $quantity, 8), 2);
        }

        return $data;
    }

    private function preparePartItem(array $data, ?BudgetItem $item): array
    {
        $partId = array_key_exists('part_id', $data) ? $data['part_id'] : $item?->part_id;

        if (!$partId) {
            return $data;
        }

        $part = Part::findOrFail($partId);

        if (!$part->isUsable()) {
            throw new PartException(
                'El repuesto está inactivo y no puede presupuestarse.',
                422,
                'PART_NOT_USABLE'
            );
        }

        $quantity = (string) ($data['quantity'] ?? $item?->quantity);

        if (!$part->unit->allowsFractions() && Decimal::hasFraction(Decimal::of($quantity, 3))) {
            throw new BudgetException(
                "El repuesto se mide en {$part->unit->label()} y no admite cantidades fraccionadas.",
                422,
                'INVENTORY_FRACTIONAL_QUANTITY_NOT_ALLOWED'
            );
        }

        return $data;
    }

    /**
     * Solo el borrador de una recepción abierta. (Cancelar se permite aun con la recepción cerrada.)
     */
    public function ensureEditable(Budget $budget): void
    {
        if ($budget->status !== BudgetStatus::DRAFT) {
            throw new BudgetException(
                'El presupuesto no puede modificarse en su estado actual.',
                422,
                'BUDGET_NOT_EDITABLE'
            );
        }

        $this->receptionStatusService->ensureEditable($budget->reception_id);
    }

    public function create(array $data, int $userId): Budget
    {
        return DB::transaction(function () use ($data, $userId) {

            $this->receptionStatusService->ensureOpen((int) $data['reception_id']);

            $budget = $this->documentSequences->create(DocumentSeries::BUDGET, fn (string $code) => Budget::create([
                'reception_id' => $data['reception_id'],
                'created_by' => $userId,
                'code' => $code,
                'status' => BudgetStatus::DRAFT,
                'subtotal' => 0,
                'tax' => 0,
                'total' => 0,
                'notes' => $data['notes'] ?? null,
            ]));

            // Presupuestar pone la recepción "en proceso".
            $this->receptionStatusService->sync($budget->reception_id);

            return $budget;
        });
    }

    public function update(Budget $budget, array $data): Budget
    {
        $this->ensureEditable($budget);

        $budget->update($data);

        return $budget->refresh();
    }

    public function delete(Budget $budget): void
    {
        $this->ensureEditable($budget);

        DB::transaction(function () use ($budget) {
            $budget->delete();

            $this->receptionStatusService->sync($budget->reception_id);
        });
    }
}