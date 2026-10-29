<?php

namespace App\Services;

use App\Enums\BudgetItemResponsibility;
use App\Enums\BudgetStatus;
use App\Enums\ClaimStatus;
use App\Enums\InsurerAuthorizationDecision;
use App\Enums\InsurerAuthorizationResult;
use App\Enums\InsurerAuthorizationStatus;
use App\Exceptions\InsurerAuthorizationException;
use App\Models\Budget;
use App\Models\BudgetItem;
use App\Models\Claim;
use App\Models\ClaimDocument;
use App\Models\InsurerAuthorization;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Autorizaciones de la aseguradora (M6). Una línea por cada ítem a cargo de la
 * aseguradora, con tope en el importe y la cantidad presupuestados (P7). Los
 * importes se guardan sin IVA; el registro declara si los cargó con IVA. Una
 * versión nueva sustituye a la vigente; si sube algún importe exige un documento
 * del siniestro como evidencia (decisión C). Orden de bloqueo: presupuesto →
 * autorización. Sin dependencias de otros Services (lo usan BudgetService y
 * ReceptionStatusService para invalidar).
 */
class InsurerAuthorizationService
{
    public function register(Budget $budget, array $data, User $user): InsurerAuthorization
    {
        return DB::transaction(function () use ($budget, $data, $user) {

            $budget = Budget::whereKey($budget->id)->lockForUpdate()->firstOrFail();

            if (!in_array($budget->status, [BudgetStatus::SENT, BudgetStatus::APPROVED], true)) {
                throw new InsurerAuthorizationException(
                    'Solo se registra la autorización de un presupuesto enviado o aprobado.',
                    422,
                    'INSURER_AUTHORIZATION_BUDGET_NOT_AUTHORIZABLE'
                );
            }

            $claim = Claim::where('reception_id', $budget->reception_id)->where('status', ClaimStatus::OPEN)->first();

            if (!$claim) {
                throw new InsurerAuthorizationException(
                    'La recepción del presupuesto no tiene un siniestro abierto.',
                    422,
                    'BUDGET_WITHOUT_CLAIM'
                );
            }

            $insurerItems = $budget->items()->where('responsibility', BudgetItemResponsibility::INSURER)->get()->keyBy('id');

            if ($insurerItems->isEmpty()) {
                throw new InsurerAuthorizationException(
                    'El presupuesto no tiene líneas a cargo de la aseguradora.',
                    422,
                    'INSURER_AUTHORIZATION_WITHOUT_INSURER_ITEMS'
                );
            }

            $lines = $this->lines($data, $insurerItems);
            $previous = $budget->currentInsurerAuthorization()->lockForUpdate()->first();
            $documents = $this->documents($data['document_ids'] ?? [], $claim);

            if ($previous && $documents->isEmpty() && $this->raisesAnyLine($previous, $lines)) {
                throw new InsurerAuthorizationException(
                    'La nueva versión autoriza más que la anterior en alguna línea: vincule el documento de la aseguradora que lo respalda.',
                    422,
                    'INSURER_AUTHORIZATION_EVIDENCE_REQUIRED'
                );
            }

            $authorization = InsurerAuthorization::create([
                'budget_id' => $budget->id,
                'claim_id' => $claim->id,
                'version' => (int) $budget->insurerAuthorizations()->max('version') + 1,
                'status' => InsurerAuthorizationStatus::CURRENT,
                'result' => $this->result($lines),
                'authorization_number' => $data['authorization_number'] ?? null,
                'decided_at' => $data['decided_at'],
                'channel' => $data['channel'],
                'adjuster_name' => $data['adjuster_name'] ?? null,
                'authorized_total' => $lines->reduce(fn (string $sum, array $line) => bcadd($sum, $line['authorized_total'], 2), '0.00'),
                'notes' => $data['notes'] ?? null,
                'registered_by' => $user->id,
            ]);

            $lines->each(fn (array $line) => $authorization->items()->create($line));

            $previous?->update([
                'status' => InsurerAuthorizationStatus::SUPERSEDED,
                'superseded_by_id' => $authorization->id,
                'superseded_at' => now(),
            ]);

            $documents->each(fn (ClaimDocument $document) => $document->update(['insurer_authorization_id' => $authorization->id]));

            return $this->load($authorization);
        });
    }

    /**
     * Reabrir o cancelar el presupuesto (o cancelar la recepción) invalida la vigente: hay que volver a registrarla.
     */
    public function invalidateCurrent(Budget $budget, string $reason): void
    {
        InsurerAuthorization::where('budget_id', $budget->id)
            ->where('status', InsurerAuthorizationStatus::CURRENT)
            ->lockForUpdate()
            ->get()
            ->each(fn (InsurerAuthorization $authorization) => $authorization->update([
                'status' => InsurerAuthorizationStatus::INVALIDATED,
                'invalidated_at' => now(),
                'invalidation_reason' => $reason,
            ]));
    }

    public function load(InsurerAuthorization $authorization): InsurerAuthorization
    {
        return $authorization->load(['items', 'documents', 'registrar']);
    }

    /**
     * Una línea por cada ítem a cargo de la aseguradora, ni más ni menos, con los importes sin IVA.
     *
     * @param Collection<int, BudgetItem> $insurerItems
     * @return Collection<int, array<string, mixed>>
     */
    private function lines(array $data, Collection $insurerItems): Collection
    {
        $given = collect($data['items'])->keyBy('budget_item_id');

        if ($given->count() !== count($data['items']) || $given->keys()->diff($insurerItems->keys())->isNotEmpty()) {
            throw new InsurerAuthorizationException(
                'Hay líneas que no son ítems a cargo de la aseguradora en este presupuesto, o que se repiten.',
                422,
                'INSURER_AUTHORIZATION_ITEM_MISMATCH'
            );
        }

        if ($insurerItems->keys()->diff($given->keys())->isNotEmpty()) {
            throw new InsurerAuthorizationException(
                'Falta la decisión de la aseguradora sobre algún ítem a su cargo: informe todas las líneas.',
                422,
                'INSURER_AUTHORIZATION_ITEMS_INCOMPLETE'
            );
        }

        return $insurerItems->map(function (BudgetItem $item) use ($given, $data) {
            $line = $given[$item->id];
            $decision = InsurerAuthorizationDecision::from($line['decision']);

            [$quantity, $total] = match ($decision) {
                InsurerAuthorizationDecision::AUTHORIZED => [Decimal::of($item->quantity, 2), Decimal::of($item->total, 2)],
                InsurerAuthorizationDecision::NOT_COVERED => ['0.00', '0.00'],
                InsurerAuthorizationDecision::PARTIAL => [
                    Decimal::of($line['authorized_quantity'] ?? $item->quantity, 2),
                    $this->withoutTax((string) $line['authorized_total'], (bool) $data['amounts_include_tax']),
                ],
            };

            // Tope (P7): nunca más de lo presupuestado, en importe ni en cantidad. El exceso va a un complementario.
            if (bccomp($total, Decimal::of($item->total, 2), 2) > 0 || bccomp($quantity, Decimal::of($item->quantity, 2), 2) > 0) {
                throw new InsurerAuthorizationException(
                    "Lo autorizado para «{$item->description}» supera lo presupuestado. Lo que exceda va en un presupuesto complementario.",
                    422,
                    'INSURER_AUTHORIZATION_EXCEEDS_BUDGET'
                );
            }

            return [
                'budget_item_id' => $item->id,
                'description' => $item->description,
                'budgeted_quantity' => Decimal::of($item->quantity, 2),
                'budgeted_total' => Decimal::of($item->total, 2),
                'decision' => $decision,
                'authorized_quantity' => $quantity,
                'authorized_unit_price' => bccomp($quantity, '0', 2) > 0 ? Decimal::round(bcdiv($total, $quantity, 6), 2) : '0.00',
                'authorized_total' => $total,
                'notes' => $line['notes'] ?? null,
            ];
        })->values();
    }

    /**
     * Importe sin IVA, como el presupuesto (P7). La tasa es la del presupuesto (invoice.default_tax_rate).
     */
    private function withoutTax(string $amount, bool $includesTax): string
    {
        if (!$includesTax) {
            return Decimal::of($amount, 2);
        }

        $rate = (string) (int) config('invoice.default_tax_rate', 10);

        return Decimal::round(bcdiv(bcmul($amount, '100', 6), bcadd('100', $rate, 6), 6), 2);
    }

    /**
     * @param Collection<int, array<string, mixed>> $lines
     */
    private function result(Collection $lines): InsurerAuthorizationResult
    {
        $decisions = $lines->pluck('decision')->unique();

        return match (true) {
            $decisions->every(fn ($d) => $d === InsurerAuthorizationDecision::AUTHORIZED) => InsurerAuthorizationResult::FULL,
            $decisions->every(fn ($d) => $d === InsurerAuthorizationDecision::NOT_COVERED) => InsurerAuthorizationResult::REJECTED,
            default => InsurerAuthorizationResult::PARTIAL,
        };
    }

    /**
     * Si alguna línea autoriza más que en la versión vigente (una línea nueva también cuenta).
     *
     * @param Collection<int, array<string, mixed>> $lines
     */
    private function raisesAnyLine(InsurerAuthorization $previous, Collection $lines): bool
    {
        $before = $previous->items()->pluck('authorized_total', 'budget_item_id');

        return $lines->contains(fn (array $line) => bccomp($line['authorized_total'], (string) ($before[$line['budget_item_id']] ?? '0'), 2) > 0);
    }

    /**
     * Documentos de evidencia: del mismo siniestro, vigentes y sin vincular a otra autorización.
     *
     * @return Collection<int, ClaimDocument>
     */
    private function documents(array $ids, Claim $claim): Collection
    {
        if ($ids === []) {
            return collect();
        }

        $documents = ClaimDocument::whereKey($ids)
            ->where('claim_id', $claim->id)
            ->whereNull('insurer_authorization_id')
            ->lockForUpdate()
            ->get();

        if ($documents->count() !== count(array_unique($ids))) {
            throw new InsurerAuthorizationException(
                'Algún documento no es de este siniestro, fue dado de baja o ya respalda otra autorización.',
                422,
                'INSURER_AUTHORIZATION_DOCUMENT_MISMATCH'
            );
        }

        return $documents;
    }
}
