<?php

namespace App\Services;

use App\Enums\DocumentSeries;
use App\Enums\InvoiceCondition;
use App\Enums\PartStatus;
use App\Enums\PurchaseStatus;
use App\Enums\TaxRate;
use App\Exceptions\InventoryException;
use App\Exceptions\PurchaseException;
use App\Exceptions\SupplierException;
use App\Models\Part;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Compras a proveedores: borrador editable → recibida (entra el stock al costo
 * sin IVA) → anulada (sale el stock). Costos cargados con IVA incluido.
 */
class PurchaseService
{
    private const RELATIONS = ['supplier', 'items.part', 'creator', 'receiver', 'canceller'];

    public function __construct(
        protected InventoryService $inventoryService,
        protected DocumentSequenceService $documentSequences
    ) {
    }

    public function create(array $data, User $user): Purchase
    {
        return DB::transaction(function () use ($data, $user) {

            $this->activeSupplier((int) $data['supplier_id']);

            $purchase = $this->documentSequences->create(DocumentSeries::PURCHASE, fn (string $code) => Purchase::create([
                ...$this->headerData($data),
                'supplier_id' => $data['supplier_id'],
                'code' => $code,
                'status' => PurchaseStatus::DRAFT,
                'condition' => $data['condition'] ?? InvoiceCondition::CASH->value,
                'created_by' => $user->id,
            ]));

            return $purchase->load(self::RELATIONS);
        });
    }

    public function update(Purchase $purchase, array $data): Purchase
    {
        return DB::transaction(function () use ($purchase, $data) {

            $purchase = $this->lockDraft($purchase);

            if (isset($data['supplier_id'])) {
                $this->activeSupplier((int) $data['supplier_id']);
                $purchase->supplier_id = $data['supplier_id'];
            }

            $purchase->fill($this->headerData($data));

            if (isset($data['condition'])) {
                $purchase->condition = $data['condition'];
            }

            $purchase->save();

            return $purchase->load(self::RELATIONS);
        });
    }

    public function delete(Purchase $purchase): void
    {
        DB::transaction(function () use ($purchase) {
            $this->lockDraft($purchase)->delete();
        });
    }

    public function addItem(Purchase $purchase, array $data): Purchase
    {
        return DB::transaction(function () use ($purchase, $data) {

            $purchase = $this->lockDraft($purchase);

            $part = $this->purchasablePart((int) $data['part_id']);

            $item = new PurchaseItem([
                'purchase_id' => $purchase->id,
                'part_id' => $part->id,
                'quantity' => $this->inventoryService->normalizeQuantity($part, $data['quantity']),
                'unit_cost' => Decimal::of($data['unit_cost'], 2),
                'tax_rate' => $this->taxRate($data['tax_rate'] ?? null),
            ]);
            $item->recalculate();
            $item->save();

            return $this->refreshTotals($purchase);
        });
    }

    public function updateItem(Purchase $purchase, PurchaseItem $item, array $data): Purchase
    {
        return DB::transaction(function () use ($purchase, $item, $data) {

            $purchase = $this->lockDraft($purchase);

            $part = isset($data['part_id'])
                ? $this->purchasablePart((int) $data['part_id'])
                : $item->part;

            $item->part_id = $part->id;
            $item->quantity = $this->inventoryService->normalizeQuantity($part, $data['quantity'] ?? $item->quantity);

            if (isset($data['unit_cost'])) {
                $item->unit_cost = Decimal::of($data['unit_cost'], 2);
            }

            if (array_key_exists('tax_rate', $data)) {
                $item->tax_rate = $this->taxRate($data['tax_rate']);
            }

            $item->recalculate();
            $item->save();

            return $this->refreshTotals($purchase);
        });
    }

    public function removeItem(Purchase $purchase, PurchaseItem $item): Purchase
    {
        return DB::transaction(function () use ($purchase, $item) {

            $purchase = $this->lockDraft($purchase);

            $item->delete();

            return $this->refreshTotals($purchase);
        });
    }

    /**
     * Recibe la compra: entra el stock de cada línea al costo sin IVA y
     * actualiza (o crea) el vínculo repuesto-proveedor con ese costo.
     * Orden de bloqueo: compra → part_stocks.
     */
    public function receive(Purchase $purchase, User $user): Purchase
    {
        return DB::transaction(function () use ($purchase, $user) {

            $purchase = Purchase::whereKey($purchase->id)->lockForUpdate()->firstOrFail();

            if ($purchase->status !== PurchaseStatus::DRAFT) {
                throw new PurchaseException(
                    "La compra está {$purchase->status->label()} y no puede recibirse.",
                    422,
                    'PURCHASE_NOT_RECEIVABLE'
                );
            }

            $items = $purchase->items()->with('part')->get();

            if ($items->isEmpty()) {
                throw new PurchaseException(
                    'La compra no tiene líneas para recibir.',
                    422,
                    'PURCHASE_WITHOUT_ITEMS'
                );
            }

            $this->ensureReceivableDocument($purchase);

            $supplier = $this->activeSupplier($purchase->supplier_id);

            foreach ($items as $item) {
                $movement = $this->inventoryService->registerPurchase(
                    $item->part,
                    $item->quantity,
                    $item->net_unit_cost,
                    $supplier,
                    $purchase->supplier_document_number,
                    $user,
                    $item
                );

                $item->update(['inventory_movement_id' => $movement->id]);

                $this->updateSupplierCost($item->part, $supplier, $item->net_unit_cost);
            }

            $purchase->update([
                'status' => PurchaseStatus::RECEIVED,
                'received_at' => now(),
                'received_by' => $user->id,
            ]);

            return $purchase->load(self::RELATIONS);
        });
    }

    /**
     * Anula una compra recibida: sale el stock de cada línea. Si parte ya se
     * consumió o está reservada, se rechaza (INSUFFICIENT_STOCK).
     * El last_cost del proveedor no se revierte.
     */
    public function cancel(Purchase $purchase, string $reason, User $user): Purchase
    {
        return DB::transaction(function () use ($purchase, $reason, $user) {

            $purchase = Purchase::whereKey($purchase->id)->lockForUpdate()->firstOrFail();

            if ($purchase->status !== PurchaseStatus::RECEIVED) {
                throw new PurchaseException(
                    $purchase->status === PurchaseStatus::DRAFT
                        ? 'La compra en borrador no se anula: puede eliminarse.'
                        : 'La compra ya está anulada.',
                    422,
                    'PURCHASE_NOT_CANCELLABLE'
                );
            }

            if ($purchase->payments()->registered()->exists()) {
                throw new PurchaseException(
                    'La compra tiene pagos registrados: anúlelos antes de anular la compra.',
                    422,
                    'PURCHASE_HAS_PAYMENTS'
                );
            }

            foreach ($purchase->items()->with('part')->get() as $item) {
                try {
                    $this->inventoryService->reversePurchase(
                        $item->part,
                        $item->quantity,
                        $purchase->supplier,
                        $purchase->supplier_document_number,
                        $user,
                        $item,
                        $reason
                    );
                } catch (InventoryException $e) {
                    // Indica qué repuesto impide anular.
                    throw new InventoryException(
                        "No se puede anular la compra: {$item->part->code} - {$e->getMessage()}",
                        $e->getStatus(),
                        $e->getErrorCode(),
                        $e->getErrors()
                    );
                }
            }

            $purchase->update([
                'status' => PurchaseStatus::CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $user->id,
                'cancellation_reason' => $reason,
            ]);

            return $purchase->load(self::RELATIONS);
        });
    }

    /**
     * Datos de la factura del proveedor exigidos para recibir.
     */
    private function ensureReceivableDocument(Purchase $purchase): void
    {
        if (!$purchase->supplier_document_number || !$purchase->supplier_document_date) {
            throw new PurchaseException(
                'Para recibir la compra se requieren el número y la fecha de la factura del proveedor.',
                422,
                'PURCHASE_DOCUMENT_REQUIRED'
            );
        }

        if ($purchase->condition === InvoiceCondition::CREDIT) {
            if (!$purchase->due_date || $purchase->due_date->lt($purchase->supplier_document_date)) {
                throw new PurchaseException(
                    'Una compra a crédito requiere un vencimiento igual o posterior a la fecha de la factura.',
                    422,
                    'PURCHASE_DUE_DATE_INVALID'
                );
            }
        }

        $duplicated = Purchase::where('supplier_id', $purchase->supplier_id)
            ->where('supplier_document_number', $purchase->supplier_document_number)
            ->where('status', PurchaseStatus::RECEIVED->value)
            ->whereKeyNot($purchase->id)
            ->value('code');

        if ($duplicated) {
            throw new PurchaseException(
                "La factura {$purchase->supplier_document_number} de este proveedor ya fue cargada en la compra {$duplicated}.",
                409,
                'PURCHASE_DUPLICATE_DOCUMENT'
            );
        }
    }

    private function updateSupplierCost(Part $part, Supplier $supplier, string $netUnitCost): void
    {
        $lastCost = Decimal::round($netUnitCost, 2);

        if ($part->suppliers()->whereKey($supplier->id)->exists()) {
            $part->suppliers()->updateExistingPivot($supplier->id, ['last_cost' => $lastCost]);

            return;
        }

        $part->suppliers()->attach($supplier->id, ['last_cost' => $lastCost]);
    }

    /**
     * Totales por tasa y liquidación del IVA sobre costos con IVA incluido
     * (misma regla que la factura de venta), más el total sin IVA.
     */
    private function refreshTotals(Purchase $purchase): Purchase
    {
        $decimals = (int) config('invoice.decimals', 0);
        $totals = ['0' => '0', '5' => '0', '10' => '0'];
        $net = '0';

        foreach ($purchase->items()->get() as $item) {
            $rate = (string) $item->tax_rate->value;
            $totals[$rate] = bcadd($totals[$rate], $item->total, 2);
            $net = bcadd($net, $item->netTotal(), 2);
        }

        $iva = fn (TaxRate $rate) => Decimal::round(
            bcdiv($totals[(string) $rate->value], $rate->liquidationDivisor(), 8),
            $decimals
        );

        $purchase->update([
            'total_exempt' => $totals['0'],
            'total_5' => $totals['5'],
            'total_10' => $totals['10'],
            'iva_5' => $iva(TaxRate::FIVE),
            'iva_10' => $iva(TaxRate::TEN),
            'total' => bcadd(bcadd($totals['0'], $totals['5'], 2), $totals['10'], 2),
            'net_total' => $net,
        ]);

        return $purchase->load(self::RELATIONS);
    }

    private function headerData(array $data): array
    {
        return array_intersect_key($data, array_flip([
            'supplier_document_number',
            'supplier_document_date',
            'supplier_stamp_number',
            'due_date',
            'notes',
        ]));
    }

    private function lockDraft(Purchase $purchase): Purchase
    {
        $purchase = Purchase::whereKey($purchase->id)->lockForUpdate()->firstOrFail();

        if ($purchase->status !== PurchaseStatus::DRAFT) {
            throw new PurchaseException(
                'Solo puede modificarse una compra en borrador.',
                422,
                'PURCHASE_NOT_EDITABLE'
            );
        }

        return $purchase;
    }

    private function activeSupplier(int $supplierId): Supplier
    {
        $supplier = Supplier::findOrFail($supplierId);

        if (!$supplier->is_active) {
            throw new SupplierException(
                'El proveedor seleccionado está inactivo.',
                422,
                'SUPPLIER_INACTIVE'
            );
        }

        return $supplier;
    }

    private function purchasablePart(int $partId): Part
    {
        $part = Part::findOrFail($partId);

        if ($part->status === PartStatus::DISCONTINUED) {
            throw new InventoryException(
                'El repuesto está descontinuado y no admite entradas de stock.',
                422,
                'PART_DISCONTINUED_NO_ENTRY'
            );
        }

        return $part;
    }

    private function taxRate(int|string|null $rate): TaxRate
    {
        return TaxRate::from((int) ($rate ?? config('invoice.default_tax_rate', 10)));
    }
}
