<?php

namespace App\Services;

use App\Enums\AllocationPortionType;
use App\Enums\AllocationStatus;
use App\Enums\BudgetItemResponsibility;
use App\Enums\ClaimStatus;
use App\Enums\InternalExceptionType;
use App\Enums\WorkOrderStatus;
use App\Exceptions\AllocationException;
use App\Exceptions\ClaimException;
use App\Models\BudgetItem;
use App\Models\Claim;
use App\Models\InsurerAuthorization;
use App\Models\InternalException;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderAllocation;
use App\Models\WorkOrderAllocationLine;
use App\Support\Decimal;
use App\Support\Format;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reparto económico de la OT de un siniestro (M8). Divide lo vendido (el cierre,
 * por concepto: WorkOrderSaleBreakdown) en porciones sin IVA por responsable
 * (capa A) y asigna a la OT una parte del deducible tal como se informa (capa B,
 * no facturable hasta M10). Se genera un borrador a mano, se edita reemplazando
 * sus porciones y se confirma con R1–R7; la confirmada se sustituye con otra
 * versión. Orden de bloqueo: siniestro → OT → reparto → excepción.
 */
class AllocationService
{
    public function __construct(
        protected WorkOrderSaleBreakdown $saleBreakdown,
        protected OwnerShareService $ownerShare,
        protected InternalExceptionService $internalExceptions,
        protected AllocationBillingService $allocationBilling
    ) {
    }

    /**
     * Borrador nuevo: copia de la versión confirmada si la hay (corrección) o propuesta desde el cierre, la
     * autorización vigente y el deducible restante del siniestro.
     */
    public function generate(WorkOrder $workOrder, User $user): WorkOrderAllocation
    {
        return DB::transaction(function () use ($workOrder, $user) {
            $claim = $this->lockOpenClaim($workOrder);
            $workOrder = WorkOrder::whereKey($workOrder->id)->lockForUpdate()->firstOrFail();

            if ($workOrder->status !== WorkOrderStatus::COMPLETED || !$workOrder->closing) {
                throw new AllocationException(
                    'Solo se reparte una orden de trabajo completada.',
                    422,
                    'WORK_ORDER_NOT_ALLOCATABLE'
                );
            }

            if ($workOrder->allocations()->where('status', AllocationStatus::DRAFT)->exists()) {
                throw new AllocationException(
                    'La orden de trabajo ya tiene un reparto en borrador.',
                    409,
                    'ALLOCATION_DRAFT_EXISTS'
                );
            }

            $confirmed = $workOrder->confirmedAllocation()->with('lines')->first();

            $allocation = WorkOrderAllocation::create([
                'work_order_id' => $workOrder->id,
                'work_order_closing_id' => $workOrder->closing->id,
                'claim_id' => $claim->id,
                'version' => (int) $workOrder->allocations()->max('version') + 1,
                'status' => AllocationStatus::DRAFT,
                'sale_total' => $workOrder->closing->sale_total,
                'deductible_amount' => $confirmed ? $confirmed->deductible_amount : $this->suggestedDeductible($claim),
                'notes' => null,
                'created_by' => $user->id,
            ]);

            $lines = $confirmed
                ? $confirmed->lines->map(fn (WorkOrderAllocationLine $line) => $line->only([
                    'portion_type', 'source_type', 'source_id', 'budget_item_id', 'client_id',
                    'insurer_authorization_item_id', 'internal_exception_id', 'amount', 'description',
                ]))
                : $this->withOwnerDeductible($this->proposedLines($workOrder, $claim), $allocation);

            $lines->each(fn (array $line) => $allocation->lines()->create($line));

            return $this->load($allocation);
        });
    }

    /**
     * Edición del borrador: reemplaza todas sus porciones (si vienen) y cambia el deducible o las notas. Las reglas
     * R1–R7 se validan al confirmar; acá solo que cada porción sea de un concepto vendido de esta OT.
     *
     * @param array{lines?: array<int, array{source_type: string, source_id: int, portion_type: string, amount: string|int|float, internal_exception_id?: int|null}>, deductible_amount?: string|int|float, notes?: string|null} $data
     */
    public function update(WorkOrderAllocation $allocation, array $data): WorkOrderAllocation
    {
        return DB::transaction(function () use ($allocation, $data) {
            $allocation = $this->lockDraft($allocation);

            if (array_key_exists('lines', $data)) {
                $lines = $this->linesFromInput($allocation, $data['lines']);
                $allocation->lines()->get()->each(fn (WorkOrderAllocationLine $line) => $line->delete());
                $lines->each(fn (array $line) => $allocation->lines()->create($line));
            }

            $allocation->update(collect($data)->only(['deductible_amount', 'notes'])->all());

            return $this->load($allocation);
        });
    }

    /**
     * Confirma el borrador (R1–R7, con el siniestro bloqueado) y sustituye la versión confirmada anterior. Consume las
     * excepciones de absorción que usa. Con deducible mayor a 0 se confirma igual: la facturación espera M10 (A).
     */
    public function confirm(WorkOrderAllocation $allocation, User $user): WorkOrderAllocation
    {
        return DB::transaction(function () use ($allocation, $user) {
            $claim = Claim::whereKey($allocation->claim_id)->lockForUpdate()->firstOrFail();
            WorkOrder::whereKey($allocation->work_order_id)->lockForUpdate()->first();
            $allocation = $this->lockDraft($allocation);

            if ($claim->status !== ClaimStatus::OPEN) {
                throw new ClaimException('El siniestro no está abierto: no se confirma el reparto.', 422, 'CLAIM_NOT_EDITABLE');
            }

            $failure = collect($this->checks($allocation))->first(fn (array $check) => !$check['ok']);

            if ($failure) {
                throw new AllocationException($failure['message'], $failure['status'], $failure['error_code']);
            }

            $previous = WorkOrderAllocation::where('work_order_id', $allocation->work_order_id)
                ->where('status', AllocationStatus::CONFIRMED)
                ->lockForUpdate()
                ->first();

            // P6 con P10 (M9): la versión anterior no puede tener facturado neto; antes se eliminan sus borradores y se
            // acreditan (o anulan, sin pagos) sus facturas emitidas.
            $pending = $previous ? $this->allocationBilling->pendingInvoices($previous) : [];

            if ($pending !== []) {
                throw new AllocationException(
                    'La versión confirmada del reparto tiene facturas vigentes: elimine los borradores y acredite o anule las emitidas antes de confirmar la nueva.',
                    409,
                    'ALLOCATION_HAS_INVOICES',
                    ['invoices' => $pending]
                );
            }

            $allocation->update([
                'status' => AllocationStatus::CONFIRMED,
                'confirmed_by' => $user->id,
                'confirmed_at' => now(),
            ]);

            $previous?->update([
                'status' => AllocationStatus::SUPERSEDED,
                'superseded_by_id' => $allocation->id,
                'superseded_at' => now(),
            ]);

            $this->absorptionExceptions($allocation)->each(
                fn (InternalException $exception) => $this->internalExceptions->consume($exception, $allocation)
            );

            return $this->load($allocation);
        });
    }

    public function delete(WorkOrderAllocation $allocation): void
    {
        DB::transaction(function () use ($allocation) {
            $allocation = $this->lockDraft($allocation);
            $allocation->lines()->get()->each(fn (WorkOrderAllocationLine $line) => $line->delete());
            $allocation->delete();
        });
    }

    public function load(WorkOrderAllocation $allocation): WorkOrderAllocation
    {
        return $allocation->load(['lines.client', 'lines.internalException', 'claim.insurer.client', 'workOrder.reception.client', 'creator', 'confirmer']);
    }

    /**
     * R1–R7 y los controles del deducible, en el orden en que se aplican al confirmar. Cada uno: rule, ok, message,
     * error_code y status. El detalle del borrador los muestra como vista previa (resumen previo de P6).
     *
     * @return array<int, array{rule: string, ok: bool, message: string, error_code: string, status: int}>
     */
    public function checks(WorkOrderAllocation $allocation): array
    {
        $allocation->loadMissing(['lines', 'claim', 'workOrder.closing']);
        $workOrder = $allocation->workOrder;
        $claim = $allocation->claim;
        $lines = $allocation->lines;
        $concepts = $this->saleBreakdown->concepts($workOrder)->keyBy(fn (array $c) => $c['source_type'] . ':' . $c['source_id']);
        $budgetItems = BudgetItem::whereIn('id', $concepts->pluck('budget_item_id')->filter())->get()->keyBy('id');
        $authorization = $this->ownerShare->currentAuthorization($workOrder->budget, $claim->status === ClaimStatus::OPEN ? $claim : null);
        $sum = fn (Collection $items) => $items->reduce(fn (string $carry, $line) => bcadd($carry, (string) $line->amount, 2), '0.00');

        $check = fn (string $rule, bool $ok, string $message, string $code, int $status = 422) => compact('rule', 'ok', 'message') + ['error_code' => $code, 'status' => $status];
        $checks = [];

        // Deducible: desconocido no es 0 (§6.6); el del reparto debe informarse.
        $deductibleKnown = $claim->deductible_amount !== null && $allocation->deductible_amount !== null;
        $checks[] = $check('deductible', $deductibleKnown, $claim->deductible_amount === null
            ? 'El deducible del siniestro es desconocido: infórmelo antes de confirmar el reparto.'
            : 'Informe la parte del deducible que corresponde a esta orden de trabajo.', 'CLAIM_DEDUCTIBLE_UNKNOWN');

        $acceptedDeductible = $this->ownerShare->lastAcceptance($workOrder->budget)?->deductible_amount;
        $deductibleAccepted = $claim->deductible_amount === null || bccomp((string) $claim->deductible_amount, (string) ($acceptedDeductible ?? '0'), 2) <= 0;
        $checks[] = $check('deductible_accepted', $deductibleAccepted,
            'El deducible del siniestro subió desde la aceptación del dueño: registre una re-aceptación.', 'CLAIM_DEDUCTIBLE_NOT_ACCEPTED');

        // R1: cada concepto vendido suma su base, sin porciones de conceptos ajenos.
        $byConcept = $lines->groupBy(fn ($line) => $line->source_type . ':' . $line->source_id);
        $incomplete = $byConcept->keys()->diff($concepts->keys())->isNotEmpty()
            || $concepts->contains(fn (array $c, string $key) => bccomp($sum($byConcept[$key] ?? collect()), Decimal::of($c['base_total'], 2), 2) !== 0);
        $checks[] = $check('R1', !$incomplete,
            'Cada concepto vendido debe repartirse completo, en porciones mayores a cero.', 'ALLOCATION_INCOMPLETE');

        // R2: el total coincide con la venta del cierre (y con su copia de control).
        $total = $sum($lines);
        $saleTotal = Decimal::of($workOrder->closing->sale_total, 2);
        $matches = bccomp($total, $saleTotal, 2) === 0 && bccomp(Decimal::of($allocation->sale_total, 2), $saleTotal, 2) === 0;
        $checks[] = $check('R2', $matches,
            'El reparto (' . Format::money($total) . ') no coincide con la venta del cierre (' . Format::money($saleTotal) . ').', 'ALLOCATION_TOTAL_MISMATCH', 409);

        // R4: las líneas del dueño y los extras no van a la aseguradora (P5).
        $covered = $lines->where('portion_type', AllocationPortionType::INSURER_COVERED);
        $notCoverable = $covered->contains(function ($line) use ($concepts, $budgetItems) {
            $concept = $concepts[$line->source_type . ':' . $line->source_id] ?? null;

            return !$concept || $concept['is_extra'] || ($budgetItems[$concept['budget_item_id']] ?? null)?->responsibility !== BudgetItemResponsibility::INSURER;
        });
        $checks[] = $check('R4', !$notCoverable,
            'Los extras y las líneas a cargo del dueño no pueden asignarse a la aseguradora.', 'ALLOCATION_EXTRA_NOT_COVERABLE');

        // R3: por ítem del presupuesto, lo cubierto no supera lo autorizado en la versión vigente (C).
        $checks[] = $check('R3', $this->withinAuthorization($covered, $authorization, $sum),
            'Lo asignado a la aseguradora supera su autorización vigente (o la autorización cambió: genere el reparto de nuevo).', 'ALLOCATION_EXCEEDS_AUTHORIZATION');

        // R6: la absorción necesita una excepción de esta OT, sin usar, por un importe suficiente.
        $checks[] = $check('R6', $this->absorptionAuthorized($allocation, $sum),
            'Lo absorbido por el taller necesita una excepción de absorción de esta orden de trabajo, sin usar y por un importe suficiente.', 'ALLOCATION_ABSORPTION_NOT_AUTHORIZED');

        // R5: lo del dueño no supera lo que aceptó más los extras aprobados (B, sin tolerancia).
        // El deducible del dueño (M10) no cuenta acá: lo controlan el deducible aceptado, R7 y R11.
        $owner = $sum($lines->filter(fn ($line) => $line->portion_type->isOwner() && $line->portion_type !== AllocationPortionType::OWNER_DEDUCTIBLE));
        $limit = $this->ownerShare->acceptedLimit($workOrder);
        $checks[] = $check('R5', bccomp($owner, $limit, 2) <= 0,
            'La parte del dueño (' . Format::money($owner) . ') supera lo que aceptó (' . Format::money($limit) . '): registre una re-aceptación, una absorción del taller o un presupuesto complementario.', 'ALLOCATION_OWNER_AMOUNT_NOT_ACCEPTED');

        // R7: los deducibles confirmados del siniestro (sin la versión que esta sustituye) no superan el del siniestro.
        $checks[] = $check('R7', !$deductibleKnown || $this->deductibleWithinClaim($allocation, $claim),
            'La suma de los deducibles asignados a las órdenes de trabajo del siniestro supera su deducible.', 'ALLOCATION_DEDUCTIBLE_EXCEEDS_CLAIM');

        // Modalidad neta del deducible (M10): el deducible de la OT, sin IVA, pasa de lo cubierto al dueño. Sin la
        // modalidad, las porciones del deducible deben ser 0.
        $expected = $this->allocationBilling->expectedOwnerDeductible($allocation);
        $allocatedDeductible = $sum($lines->where('portion_type', AllocationPortionType::OWNER_DEDUCTIBLE));
        $checks[] = $check('deductible_tax', $expected !== null,
            'Indique en el siniestro si el deducible incluye el IVA: sin eso no se calcula su parte sin IVA.', 'CLAIM_DEDUCTIBLE_TAX_UNDEFINED');
        $checks[] = $check('deductible_covered', $expected === null || bccomp($expected, bcadd($sum($covered), $allocatedDeductible, 2), 2) <= 0,
            'El deducible de esta orden de trabajo (' . Format::money($expected ?? '0') . ' sin IVA) supera lo que cubre la aseguradora: el caso todavía no tiene una regla definida (V16).', 'ALLOCATION_DEDUCTIBLE_EXCEEDS_COVERED');
        $checks[] = $check('R11', $expected === null || bccomp($allocatedDeductible, $expected, 2) === 0,
            'Las porciones del deducible (' . Format::money($allocatedDeductible) . ') deben sumar el deducible de esta orden de trabajo sin IVA (' . Format::money($expected ?? '0') . ').', 'ALLOCATION_DEDUCTIBLE_MISMATCH');

        return $checks;
    }

    /**
     * Resumen por responsable (P6) y advertencias del reparto.
     */
    public function summary(WorkOrderAllocation $allocation): array
    {
        $allocation->loadMissing(['lines.client', 'claim', 'workOrder.budget']);
        $authorization = $this->ownerShare->currentAuthorization($allocation->workOrder->budget, $allocation->claim->status === ClaimStatus::OPEN ? $allocation->claim : null);
        $sum = fn (Collection $items) => $items->reduce(fn (string $carry, $line) => bcadd($carry, (string) $line->amount, 2), '0.00');

        $responsibles = $allocation->lines
            ->groupBy(fn ($line) => $line->client_id ?? 'workshop')
            ->map(fn (Collection $lines) => [
                'client_id' => $lines->first()->client_id,
                'name' => $lines->first()->client?->display_name ?? 'Taller',
                'role' => match (true) {
                    $lines->first()->client_id === null => 'workshop',
                    $lines->contains('portion_type', AllocationPortionType::INSURER_COVERED) => 'insurer',
                    default => 'owner',
                },
                'amount' => $sum($lines),
            ])
            ->values();

        $warnings = [];
        $coveredItems = $allocation->lines->whereNotNull('insurer_authorization_item_id')->pluck('insurer_authorization_item_id')->unique();

        if (!$authorization) {
            $warnings[] = 'El presupuesto no tiene una autorización vigente de la aseguradora: lo de sus líneas queda a cargo del dueño hasta registrarla.';
        } elseif ($coveredItems->diff($authorization->items->pluck('id'))->isNotEmpty()) {
            $warnings[] = 'La autorización de la aseguradora cambió después de armar este reparto: genere una nueva versión.';
        }

        return [
            'responsibles' => $responsibles->all(),
            'total' => $sum($allocation->lines),
            'deductible_amount' => $allocation->deductible_amount,
            // Pendiente: deducible mayor a 0 que no se factura (sin la modalidad neta o sin su IVA, M10).
            'deductible_fiscal_pending' => $allocation->deductible_amount !== null && bccomp((string) $allocation->deductible_amount, '0', 2) > 0
                && !$this->allocationBilling->deductibleInvoiced($allocation->claim),
            'warnings' => $warnings,
        ];
    }

    /**
     * Conceptos vendidos de la OT (WorkOrderSaleBreakdown) con lo que cada uno debe sumar en el reparto (R1), si puede
     * ir a la aseguradora (R4) y lo autorizado para su ítem del presupuesto en la versión vigente (R3, por ítem: varios
     * conceptos pueden compartirlo). Lo usa el editor del borrador.
     *
     * @return array<int, array<string, mixed>>
     */
    public function concepts(WorkOrderAllocation $allocation): array
    {
        $allocation->loadMissing(['claim', 'workOrder.budget']);
        $workOrder = $allocation->workOrder;
        $claim = $allocation->claim;
        $concepts = $this->saleBreakdown->concepts($workOrder);
        $budgetItems = BudgetItem::whereIn('id', $concepts->pluck('budget_item_id')->filter())->get()->keyBy('id');
        $authorized = $this->ownerShare->currentAuthorization($workOrder->budget, $claim->status === ClaimStatus::OPEN ? $claim : null)?->items->keyBy('budget_item_id');

        return $concepts->map(function (array $concept) use ($budgetItems, $authorized) {
            $budgetItem = $budgetItems[$concept['budget_item_id']] ?? null;
            $responsibility = $budgetItem?->responsibility ?? BudgetItemResponsibility::OWNER;

            return [
                'source_type' => $concept['source_type'],
                'source_id' => $concept['source_id'],
                'budget_item_id' => $concept['budget_item_id'],
                'description' => $concept['description'],
                'is_extra' => $concept['is_extra'],
                'responsibility' => [
                    'value' => $responsibility->value,
                    'label' => $responsibility->label(),
                ],
                'coverable' => !$concept['is_extra'] && $responsibility === BudgetItemResponsibility::INSURER,
                'quantity' => Decimal::of($concept['quantity'], 2),
                'base_unit_price' => Decimal::of($concept['base_unit_price'], 2),
                'base_total' => Decimal::of($concept['base_total'], 2),
                'authorized_total' => $concept['is_extra'] ? null : $authorized?->get($concept['budget_item_id'])?->authorized_total,
            ];
        })->values()->all();
    }

    /**
     * Modalidad neta del deducible (M10): la base del deducible de la OT se descuenta de lo cubierto por la aseguradora,
     * concepto por concepto en orden, y pasa al dueño como porción owner_deductible del mismo concepto (R1 y R2 no
     * cambian). Si lo cubierto no alcanza, queda lo posible y la confirmación lo rechaza (V16).
     *
     * @param Collection<int, array<string, mixed>> $lines
     * @return Collection<int, array<string, mixed>>
     */
    private function withOwnerDeductible(Collection $lines, WorkOrderAllocation $allocation): Collection
    {
        $remaining = $this->allocationBilling->expectedOwnerDeductible($allocation);

        if ($remaining === null || bccomp($remaining, '0', 2) <= 0) {
            return $lines;
        }

        $ownerClientId = $allocation->workOrder->reception->client_id;
        $kept = collect();
        $deductible = collect();

        foreach ($lines as $line) {
            if ($line['portion_type'] !== AllocationPortionType::INSURER_COVERED || bccomp($remaining, '0', 2) <= 0) {
                $kept->push($line);
                continue;
            }

            $amount = Decimal::of($line['amount'], 2);
            $taken = bccomp($amount, $remaining, 2) <= 0 ? $amount : $remaining;
            $remaining = bcsub($remaining, $taken, 2);

            if (bccomp($rest = bcsub($amount, $taken, 2), '0', 2) > 0) {
                $kept->push([...$line, 'amount' => $rest]);
            }

            $deductible->push([
                ...$line,
                'portion_type' => AllocationPortionType::OWNER_DEDUCTIBLE,
                'client_id' => $ownerClientId,
                'insurer_authorization_item_id' => null,
                'amount' => $taken,
            ]);
        }

        return $kept->merge($deductible)->values();
    }

    /**
     * Propuesta desde el cierre: la aseguradora recibe hasta lo autorizado por ítem del presupuesto, sumando sus
     * conceptos en orden, y el resto va al dueño como no cubierto; los extras y las líneas del dueño, al dueño.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function proposedLines(WorkOrder $workOrder, Claim $claim): Collection
    {
        $authorization = $this->ownerShare->currentAuthorization($workOrder->budget, $claim);
        $authorized = $authorization?->items->keyBy('budget_item_id');
        $remaining = $authorized?->map(fn ($item) => Decimal::of($item->authorized_total, 2)) ?? collect();
        $concepts = $this->saleBreakdown->concepts($workOrder);
        $budgetItems = BudgetItem::whereIn('id', $concepts->pluck('budget_item_id')->filter())->get()->keyBy('id');
        $insurerClientId = $claim->insurer()->withTrashed()->first()->client_id;
        $ownerClientId = $workOrder->reception->client_id;
        $lines = collect();

        foreach ($concepts as $concept) {
            $base = Decimal::of($concept['base_total'], 2);
            $budgetItem = $budgetItems[$concept['budget_item_id']] ?? null;
            $line = fn (AllocationPortionType $type, string $amount, ?int $clientId, ?int $authorizationItemId = null) => [
                'portion_type' => $type,
                'source_type' => $concept['source_type'],
                'source_id' => $concept['source_id'],
                'budget_item_id' => $concept['budget_item_id'],
                'client_id' => $clientId,
                'insurer_authorization_item_id' => $authorizationItemId,
                'internal_exception_id' => null,
                'amount' => $amount,
                'description' => $concept['description'],
            ];

            if ($concept['is_extra'] || $budgetItem?->responsibility !== BudgetItemResponsibility::INSURER) {
                $lines->push($line(AllocationPortionType::OWNER_RESPONSIBILITY, $base, $ownerClientId));
                continue;
            }

            $available = $remaining[$budgetItem->id] ?? '0.00';
            $covered = bccomp($available, $base, 2) < 0 ? $available : $base;
            $uncovered = bcsub($base, $covered, 2);

            if (bccomp($covered, '0', 2) > 0) {
                $remaining[$budgetItem->id] = bcsub($available, $covered, 2);
                $lines->push($line(AllocationPortionType::INSURER_COVERED, $covered, $insurerClientId, $authorized[$budgetItem->id]->id));
            }

            if (bccomp($uncovered, '0', 2) > 0) {
                $lines->push($line(AllocationPortionType::OWNER_UNCOVERED, $uncovered, $ownerClientId));
            }
        }

        return $lines;
    }

    /**
     * Porciones de la edición: cada una de un concepto vendido de la OT, una por tipo. El responsable, el ítem del
     * presupuesto y la línea de autorización se derivan del concepto y del tipo.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function linesFromInput(WorkOrderAllocation $allocation, array $input): Collection
    {
        $workOrder = $allocation->workOrder;
        $concepts = $this->saleBreakdown->concepts($workOrder)->keyBy(fn (array $c) => $c['source_type'] . ':' . $c['source_id']);
        $claim = $allocation->claim;
        $authorized = $this->ownerShare->currentAuthorization($workOrder->budget, $claim->status === ClaimStatus::OPEN ? $claim : null)?->items->keyBy('budget_item_id');
        $insurerClientId = $claim->insurer()->withTrashed()->first()->client_id;
        $ownerClientId = $workOrder->reception->client_id;

        $keys = collect($input)->map(fn (array $line) => $line['source_type'] . ':' . $line['source_id'] . ':' . $line['portion_type']);

        if ($keys->duplicates()->isNotEmpty()) {
            throw new AllocationException('Un concepto no puede tener dos porciones del mismo tipo.', 422, 'ALLOCATION_LINE_MISMATCH');
        }

        return collect($input)->map(function (array $line) use ($concepts, $authorized, $insurerClientId, $ownerClientId) {
            $concept = $concepts[$line['source_type'] . ':' . $line['source_id']] ?? null;

            if (!$concept) {
                throw new AllocationException('Alguna porción no es de un concepto vendido en esta orden de trabajo.', 422, 'ALLOCATION_LINE_MISMATCH');
            }

            $type = AllocationPortionType::from($line['portion_type']);

            return [
                'portion_type' => $type,
                'source_type' => $concept['source_type'],
                'source_id' => $concept['source_id'],
                'budget_item_id' => $concept['budget_item_id'],
                'client_id' => match ($type) {
                    AllocationPortionType::INSURER_COVERED => $insurerClientId,
                    AllocationPortionType::WORKSHOP_ABSORBED => null,
                    default => $ownerClientId,
                },
                'insurer_authorization_item_id' => $type === AllocationPortionType::INSURER_COVERED
                    ? $authorized?->get($concept['budget_item_id'])?->id
                    : null,
                'internal_exception_id' => $type === AllocationPortionType::WORKSHOP_ABSORBED ? ($line['internal_exception_id'] ?? null) : null,
                'amount' => Decimal::of($line['amount'], 2),
                'description' => $concept['description'],
            ];
        })->values();
    }

    private function withinAuthorization(Collection $covered, ?InsurerAuthorization $authorization, callable $sum): bool
    {
        if ($covered->isEmpty()) {
            return true;
        }

        $authorizedItems = $authorization?->items->keyBy('id');

        if (!$authorizedItems || $covered->contains(fn ($line) => !$authorizedItems->has($line->insurer_authorization_item_id))) {
            return false;
        }

        return $covered->groupBy('insurer_authorization_item_id')->every(
            fn (Collection $lines, $itemId) => bccomp($sum($lines), Decimal::of($authorizedItems[$itemId]->authorized_total, 2), 2) <= 0
        );
    }

    private function absorptionAuthorized(WorkOrderAllocation $allocation, callable $sum): bool
    {
        $absorbed = $allocation->lines->where('portion_type', AllocationPortionType::WORKSHOP_ABSORBED);

        if ($absorbed->isEmpty()) {
            return true;
        }

        if ($absorbed->contains(fn ($line) => $line->internal_exception_id === null)) {
            return false;
        }

        $exceptions = $this->absorptionExceptions($allocation)->keyBy('id');

        return $absorbed->groupBy('internal_exception_id')->every(function (Collection $lines, $id) use ($exceptions, $sum, $allocation) {
            $exception = $exceptions[$id] ?? null;

            return $exception
                && $exception->consumed_at === null
                && (int) $exception->subject_id === $allocation->work_order_id
                && bccomp($sum($lines), Decimal::of($exception->amount, 2), 2) <= 0;
        });
    }

    /**
     * @return Collection<int, InternalException>
     */
    private function absorptionExceptions(WorkOrderAllocation $allocation): Collection
    {
        $ids = $allocation->lines->pluck('internal_exception_id')->filter()->unique();

        return InternalException::whereKey($ids)
            ->where('type', InternalExceptionType::WORKSHOP_ABSORPTION)
            ->where('subject_type', (new WorkOrder())->getMorphClass())
            ->lockForUpdate()
            ->get();
    }

    private function deductibleWithinClaim(WorkOrderAllocation $allocation, Claim $claim): bool
    {
        $others = WorkOrderAllocation::where('claim_id', $claim->id)
            ->where('status', AllocationStatus::CONFIRMED)
            ->where('work_order_id', '!=', $allocation->work_order_id)
            ->sum('deductible_amount');

        return bccomp(bcadd((string) $others, (string) $allocation->deductible_amount, 2), (string) $claim->deductible_amount, 2) <= 0;
    }

    /**
     * Deducible restante del siniestro (sugerencia: los borradores no lo reservan). Nulo si es desconocido.
     */
    private function suggestedDeductible(Claim $claim): ?string
    {
        if ($claim->deductible_amount === null) {
            return null;
        }

        $confirmed = (string) WorkOrderAllocation::where('claim_id', $claim->id)->where('status', AllocationStatus::CONFIRMED)->sum('deductible_amount');
        $remaining = bcsub((string) $claim->deductible_amount, $confirmed, 2);

        return bccomp($remaining, '0', 2) > 0 ? $remaining : '0.00';
    }

    /**
     * El siniestro no cancelado de la recepción, bloqueado; solo una OT que requiere reparto llega acá.
     */
    private function lockOpenClaim(WorkOrder $workOrder): Claim
    {
        if (!$this->ownerShare->requiresAllocation($workOrder)) {
            throw new AllocationException(
                'La orden de trabajo no requiere reparto: no tiene líneas a cargo de la aseguradora en un siniestro vigente.',
                422,
                'ALLOCATION_NOT_REQUIRED'
            );
        }

        $claim = Claim::where('reception_id', $workOrder->reception_id)
            ->where('status', '!=', ClaimStatus::CANCELLED)
            ->lockForUpdate()
            ->firstOrFail();

        if ($claim->status !== ClaimStatus::OPEN) {
            throw new ClaimException('El siniestro no está abierto: no se reparte.', 422, 'CLAIM_NOT_EDITABLE');
        }

        return $claim;
    }

    private function lockDraft(WorkOrderAllocation $allocation): WorkOrderAllocation
    {
        $allocation = WorkOrderAllocation::whereKey($allocation->id)->lockForUpdate()->firstOrFail();

        if (!$allocation->isDraft()) {
            throw new AllocationException(
                'Solo se modifica un reparto en borrador.',
                422,
                'ALLOCATION_NOT_EDITABLE'
            );
        }

        return $allocation;
    }
}
