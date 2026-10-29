<?php

namespace App\Services;

use App\Enums\BudgetItemResponsibility;
use App\Enums\ClaimStatus;
use App\Enums\ClientAcceptanceKind;
use App\Enums\ClientDecision;
use App\Enums\WorkOrderItemStatus;
use App\Enums\WorkOrderPartStatus;
use App\Models\Budget;
use App\Models\BudgetClientAcceptance;
use App\Models\BudgetItem;
use App\Models\Claim;
use App\Models\InsurerAuthorization;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Models\WorkOrderPart;
use App\Support\Decimal;
use Illuminate\Support\Collection;

/**
 * Parte a cargo del dueño en un siniestro (M7, decisión B), sin IVA como el
 * presupuesto: sus líneas más lo que la aseguradora no autoriza de las suyas.
 * Sin siniestro abierto, todo es del dueño; sin autorización todavía, las líneas
 * de la aseguradora quedan pendientes y no se le suman. En un presupuesto sin
 * líneas de la aseguradora es el subtotal, como en el flujo particular. Solo
 * lectura y sin dependencias de otros Services.
 */
class OwnerShareService
{
    /** Advertencia de P5 al agregar un extra en una OT con siniestro: informa, no cambia ninguna regla. */
    public const EXTRA_WARNING = 'Este repuesto quedará a cargo del dueño. Si debe cubrirlo la aseguradora, cargalo en un presupuesto complementario.';

    /**
     * Advertencias del alta de un repuesto en la OT (P5): un extra con siniestro abierto queda a cargo del dueño.
     *
     * @return string[]
     */
    public function extraPartWarnings(WorkOrderPart $line): array
    {
        return $line->isExtra() && $this->openClaim($line->workOrder->reception_id) ? [self::EXTRA_WARNING] : [];
    }

    public function hasInsurerItems(Budget $budget): bool
    {
        return $budget->items()->where('responsibility', BudgetItemResponsibility::INSURER)->exists();
    }

    public function openClaim(int $receptionId): ?Claim
    {
        return Claim::where('reception_id', $receptionId)->where('status', ClaimStatus::OPEN)->first();
    }

    /**
     * Autorización vigente del presupuesto para el siniestro abierto (la de un siniestro cancelado no cuenta).
     */
    public function currentAuthorization(Budget $budget, ?Claim $claim): ?InsurerAuthorization
    {
        if (!$claim) {
            return null;
        }

        return $budget->currentInsurerAuthorization()->where('claim_id', $claim->id)->with('items')->first();
    }

    /**
     * Última respuesta que fija el importe aceptado: aprobación o re-aceptación.
     */
    public function lastAcceptance(Budget $budget): ?BudgetClientAcceptance
    {
        return $budget->clientAcceptances()
            ->whereIn('kind', ClientAcceptanceKind::acceptances())
            ->reorder()
            ->orderByDesc('decided_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Parte del dueño según el presupuesto.
     */
    public function budgetOwnerAmount(Budget $budget): string
    {
        if (!$this->hasInsurerItems($budget)) {
            return Decimal::of($budget->subtotal, 2);
        }

        $claim = $this->openClaim($budget->reception_id);
        $authorized = $this->authorizedByItem($budget, $claim);

        return $budget->items()->get()->reduce(
            fn (string $sum, BudgetItem $item) => bcadd($sum, $this->ownerShare($item, Decimal::of($item->total, 2), $claim !== null, $authorized), 2),
            '0.00'
        );
    }

    /**
     * Parte potencial del dueño en la OT con la ejecución actual, sin los extras (que tienen su propia aprobación):
     * lo resuelto (consumido, devuelto o cancelado) por lo que se usó y lo pendiente por lo previsto.
     */
    public function workOrderOwnerAmount(WorkOrder $workOrder): string
    {
        $workOrder->loadMissing(['budget', 'items.budgetItem', 'parts']);
        $claim = $this->openClaim($workOrder->reception_id);
        $authorized = $this->hasInsurerItems($workOrder->budget) ? $this->authorizedByItem($workOrder->budget, $claim) : null;

        return $workOrder->items->reduce(function (string $sum, WorkOrderItem $item) use ($workOrder, $claim, $authorized) {
            if (!$item->budgetItem) {
                return $sum;
            }

            $potential = $this->potentialSale($item, $workOrder->parts->where('work_order_item_id', $item->id));

            return bcadd($sum, $this->ownerShare($item->budgetItem, $potential, $claim !== null, $authorized), 2);
        }, '0.00');
    }

    /**
     * Advertencia de B en el detalle de una OT con líneas de la aseguradora: lo que hoy pagaría el dueño supera lo
     * que aceptó (más los extras que aprobó), o el deducible del siniestro subió desde su aceptación. Informativa:
     * no bloquea nada (el control es al confirmar el reparto, M8). Null si no hay nada que advertir.
     */
    public function workOrderWarning(WorkOrder $workOrder): ?array
    {
        $workOrder->loadMissing(['budget', 'parts']);

        if (!$workOrder->budget || !$this->hasInsurerItems($workOrder->budget)) {
            return null;
        }

        $acceptance = $this->lastAcceptance($workOrder->budget);
        [$extrasApproved, $extrasPotential] = $this->extras($workOrder->parts);

        $accepted = bcadd(Decimal::of($acceptance?->owner_amount, 2), $extrasApproved, 2);
        $potential = bcadd($this->workOrderOwnerAmount($workOrder), $extrasPotential, 2);
        $exceeded = bccomp($potential, $accepted, 2) > 0;

        $currentDeductible = $this->openClaim($workOrder->reception_id)?->deductible_amount;
        $acceptedDeductible = $acceptance?->deductible_amount;
        $deductibleIncreased = $currentDeductible !== null && bccomp((string) $currentDeductible, (string) ($acceptedDeductible ?? '0'), 2) > 0;

        if (!$exceeded && !$deductibleIncreased) {
            return null;
        }

        $messages = array_filter([
            $exceeded ? 'Lo que hoy pagaría el dueño supera lo que aceptó: antes de facturar hace falta una re-aceptación, una absorción del taller o un presupuesto complementario.' : null,
            $deductibleIncreased ? 'El deducible del siniestro subió desde la aceptación del dueño: hace falta una re-aceptación.' : null,
        ]);

        return [
            'accepted_amount' => $accepted,
            'potential_amount' => $potential,
            'difference' => $exceeded ? bcsub($potential, $accepted, 2) : '0.00',
            'amount_exceeded' => $exceeded,
            'accepted_deductible' => $acceptedDeductible,
            'current_deductible' => $currentDeductible,
            'deductible_increased' => $deductibleIncreased,
            'message' => implode(' ', $messages),
        ];
    }

    /**
     * Importe autorizado por ítem del presupuesto; null si todavía no hay autorización para el siniestro abierto.
     *
     * @return Collection<int, string>|null
     */
    private function authorizedByItem(Budget $budget, ?Claim $claim): ?Collection
    {
        return $this->currentAuthorization($budget, $claim)?->items->pluck('authorized_total', 'budget_item_id');
    }

    /**
     * @param Collection<int, string>|null $authorized
     */
    private function ownerShare(BudgetItem $item, string $amount, bool $hasOpenClaim, ?Collection $authorized): string
    {
        if ($item->responsibility !== BudgetItemResponsibility::INSURER || !$hasOpenClaim) {
            return $amount;
        }

        // Sin autorización todavía, la línea de la aseguradora queda pendiente: no se suma al dueño.
        if ($authorized === null) {
            return '0.00';
        }

        $uncovered = bcsub($amount, Decimal::of($authorized[$item->id] ?? '0', 2), 2);

        return bccomp($uncovered, '0', 2) > 0 ? $uncovered : '0.00';
    }

    /**
     * Venta potencial de un ítem de la OT (como el cierre, pero lo pendiente por lo previsto).
     *
     * @param Collection<int, WorkOrderPart> $lines
     */
    private function potentialSale(WorkOrderItem $item, Collection $lines): string
    {
        if ($item->status === WorkOrderItemStatus::CANCELLED) {
            return '0.00';
        }

        // Mano de obra, servicio o repuesto fuera de catálogo: se vende lo presupuestado.
        if ($item->isCommissionable() || $item->budgetItem->part_id === null || $lines->isEmpty()) {
            return Decimal::of($item->budgetItem->total, 2);
        }

        return $lines->reduce(fn (string $sum, WorkOrderPart $line) => bcadd($sum, $this->potentialLine($line), 2), '0.00');
    }

    private function potentialLine(WorkOrderPart $line): string
    {
        return match (true) {
            $line->status === WorkOrderPartStatus::CANCELLED => '0.00',
            $line->status->isOpen() => Decimal::round(bcmul((string) $line->quantity, (string) ($line->unit_price ?? '0'), 8), 2),
            default => $line->totalPrice(),
        };
    }

    /**
     * Extras aprobados por el cliente: [importe aprobado (cantidad × precio), importe potencial].
     *
     * @param Collection<int, WorkOrderPart> $parts
     * @return array{0: string, 1: string}
     */
    private function extras(Collection $parts): array
    {
        return $parts
            ->filter(fn (WorkOrderPart $line) => $line->isExtra()
                && $line->client_decision === ClientDecision::APPROVED
                && $line->status !== WorkOrderPartStatus::CANCELLED)
            ->reduce(fn (array $sum, WorkOrderPart $line) => [
                bcadd($sum[0], Decimal::round(bcmul((string) $line->quantity, (string) ($line->unit_price ?? '0'), 8), 2), 2),
                bcadd($sum[1], $this->potentialLine($line), 2),
            ], ['0.00', '0.00']);
    }
}
