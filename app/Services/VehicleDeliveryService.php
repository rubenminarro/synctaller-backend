<?php

namespace App\Services;

use App\Enums\InvoiceCondition;
use App\Enums\InvoiceStatus;
use App\Enums\ReceptionStatus;
use App\Enums\VehicleDeliveryStatus;
use App\Enums\WorkOrderStatus;
use App\Exceptions\VehicleDeliveryException;
use App\Models\Reception;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleDelivery;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Entrega del vehículo (FASE 17). Requisitos por cada OT completada de la
 * recepción: factura emitida y, si es al contado, totalmente pagada (a
 * crédito se entrega con saldo). Ninguna OT puede quedar abierta.
 * Orden de bloqueo: recepción → entregas.
 */
class VehicleDeliveryService
{
    private const OPEN_WORK_ORDER_STATUSES = [
        WorkOrderStatus::PENDING,
        WorkOrderStatus::IN_PROGRESS,
        WorkOrderStatus::PAUSED,
    ];

    public function __construct(
        protected ClaimStatusService $claimStatusService
    ) {
    }

    public function deliver(Reception $reception, array $data, ?UploadedFile $signature, User $user): VehicleDelivery
    {
        $storedPath = null;

        try {
            return DB::transaction(function () use ($reception, $data, $signature, $user, &$storedPath) {

                $reception = Reception::whereKey($reception->id)->lockForUpdate()->firstOrFail();

                if ($reception->deliveries()->active()->exists()) {
                    throw new VehicleDeliveryException(
                        'El vehículo de esta recepción ya fue entregado.',
                        409,
                        'DELIVERY_ALREADY_REGISTERED'
                    );
                }

                if ($reception->status === ReceptionStatus::CANCELLED) {
                    throw new VehicleDeliveryException(
                        'La recepción está cancelada y no admite entrega.',
                        422,
                        'DELIVERY_RECEPTION_CANCELLED'
                    );
                }

                $this->ensureWorkOrdersResolved($reception);

                $finalMileage = (int) $data['final_mileage'];

                if ($reception->mileage !== null && $finalMileage < (int) $reception->mileage) {
                    throw new VehicleDeliveryException(
                        "El kilometraje de entrega ({$finalMileage}) no puede ser menor al de la recepción ({$reception->mileage}).",
                        422,
                        'DELIVERY_MILEAGE_BELOW_RECEPTION'
                    );
                }

                $sequence = (int) VehicleDelivery::lockForUpdate()->max('delivery_sequence') + 1;

                if ($signature) {
                    $storedPath = $signature->store('deliveries', 'public');
                }

                $delivery = VehicleDelivery::create([
                    'reception_id' => $reception->id,
                    'vehicle_id' => $reception->vehicle_id,
                    'client_id' => $reception->client_id,
                    'delivery_sequence' => $sequence,
                    'delivery_number' => 'ENT-' . str_pad((string) $sequence, 6, '0', STR_PAD_LEFT),
                    'status' => VehicleDeliveryStatus::DELIVERED,
                    'delivered_at' => now(),
                    'delivered_by' => $user->id,
                    'final_mileage' => $finalMileage,
                    'fuel_level' => $data['fuel_level'] ?? null,
                    'received_by_name' => $data['received_by_name'],
                    'received_by_document' => $data['received_by_document'],
                    'customer_accepted' => true,
                    'signature_path' => $storedPath,
                    'observations' => $data['observations'] ?? null,
                    'previous_reception_status' => $reception->status->value,
                ]);

                $reception->update(['status' => ReceptionStatus::DELIVERED]);

                // El siniestro vigente se cierra con la entrega.
                $this->claimStatusService->receptionDelivered($reception);

                // El kilometraje del vehículo solo avanza.
                Vehicle::whereKey($reception->vehicle_id)
                    ->where(fn ($q) => $q->whereNull('mileage')->orWhere('mileage', '<', $finalMileage))
                    ->update(['mileage' => $finalMileage]);

                return $this->load($delivery);
            });
        } catch (\Throwable $e) {
            if ($storedPath) {
                Storage::disk('public')->delete($storedPath);
            }

            throw $e;
        }
    }

    /**
     * Anula la entrega: la recepción vuelve a su estado anterior. El número
     * ENT no se reutiliza y el kilometraje del vehículo se conserva.
     */
    public function cancel(VehicleDelivery $delivery, string $reason, User $user): VehicleDelivery
    {
        return DB::transaction(function () use ($delivery, $reason, $user) {

            $reception = Reception::whereKey($delivery->reception_id)->lockForUpdate()->firstOrFail();
            $delivery = VehicleDelivery::whereKey($delivery->id)->lockForUpdate()->firstOrFail();

            if ($delivery->status !== VehicleDeliveryStatus::DELIVERED) {
                throw new VehicleDeliveryException(
                    'La entrega ya está anulada.',
                    422,
                    'DELIVERY_NOT_CANCELLABLE'
                );
            }

            $restoredStatus = ReceptionStatus::from($delivery->previous_reception_status);

            // Reabrir esta recepción dejaría dos abiertas si el vehículo ya reingresó.
            if (
                $restoredStatus->isOpen() &&
                Reception::where('vehicle_id', $reception->vehicle_id)
                    ->whereKeyNot($reception->id)
                    ->whereIn('status', ReceptionStatus::openStatuses())
                    ->exists()
            ) {
                throw new VehicleDeliveryException(
                    'No se puede anular la entrega: el vehículo ya tiene otra recepción abierta.',
                    409,
                    'DELIVERY_VEHICLE_HAS_OPEN_RECEPTION'
                );
            }

            $delivery->update([
                'status' => VehicleDeliveryStatus::CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $user->id,
                'cancellation_reason' => $reason,
            ]);

            $reception->update(['status' => $restoredStatus]);

            // El siniestro cerrado por la entrega vuelve a abrirse.
            $this->claimStatusService->deliveryCancelled($reception);

            return $this->load($delivery);
        });
    }

    /**
     * Ninguna OT abierta, al menos una completada y cada completada con
     * factura emitida (al contado, sin saldo).
     */
    private function ensureWorkOrdersResolved(Reception $reception): void
    {
        $workOrders = $reception->workOrders()->with('currentInvoice')->get();

        $open = $workOrders->filter(fn ($wo) => in_array($wo->status, self::OPEN_WORK_ORDER_STATUSES, true));

        if ($open->isNotEmpty()) {
            throw new VehicleDeliveryException(
                'La recepción tiene órdenes de trabajo abiertas: ' . $open->pluck('code')->implode(', ') . '.',
                422,
                'DELIVERY_WORK_ORDERS_OPEN'
            );
        }

        $completed = $workOrders->where('status', WorkOrderStatus::COMPLETED);

        if ($completed->isEmpty()) {
            throw new VehicleDeliveryException(
                'La recepción no tiene órdenes de trabajo completadas.',
                422,
                'DELIVERY_WITHOUT_COMPLETED_WORK_ORDER'
            );
        }

        foreach ($completed as $workOrder) {
            $invoice = $workOrder->currentInvoice;

            if ($invoice === null || $invoice->status !== InvoiceStatus::ISSUED) {
                throw new VehicleDeliveryException(
                    "La orden de trabajo {$workOrder->code} no tiene una factura emitida.",
                    422,
                    'DELIVERY_WORK_ORDER_NOT_INVOICED'
                );
            }

            if ($invoice->condition === InvoiceCondition::CASH && bccomp($invoice->balance(), '0', 2) > 0) {
                throw new VehicleDeliveryException(
                    "La factura {$invoice->number} es al contado y tiene saldo pendiente ({$invoice->balance()}).",
                    422,
                    'DELIVERY_INVOICE_NOT_PAID'
                );
            }
        }
    }

    private function load(VehicleDelivery $delivery): VehicleDelivery
    {
        return $delivery->refresh()->load(['reception', 'vehicle', 'client']);
    }
}
