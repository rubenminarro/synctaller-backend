<?php

namespace App\Services;

use App\Enums\DocumentSeries;
use App\Enums\ServiceStatus;
use App\Exceptions\ServiceException;
use App\Models\MaintenancePlan;
use App\Models\Service;
use Illuminate\Support\Facades\DB;

/**
 * Catálogo de servicios del taller (lista de precios).
 */
class ServiceCatalogService
{
    public function __construct(
        protected DocumentSequenceService $documentSequences
    ) {
    }

    public function create(array $data): Service
    {
        return DB::transaction(function () use ($data) {

            $service = $this->documentSequences->create(DocumentSeries::SERVICE, fn (string $code) => Service::create([
                ...$data,
                'code' => $code,
                'status' => ServiceStatus::ACTIVE,
            ]));

            return $service->load('category');
        });
    }

    public function update(Service $service, array $data): Service
    {
        return DB::transaction(function () use ($service, $data) {

            // El nombre se ve en los presupuestos que ya lo usan: usado, queda fijo. Precio, horas, descripción y
            // categoría sí se editan (solo afectan los presupuestos nuevos).
            if (
                array_key_exists('name', $data) &&
                $data['name'] !== $service->name &&
                $service->budgetItems()->exists()
            ) {
                throw new ServiceException(
                    'El servicio ya se usó en presupuestos: su nombre no puede cambiarse. Desactívelo y cree otro si hace falta.',
                    422,
                    'SERVICE_NAME_NOT_EDITABLE'
                );
            }

            $service->update($data);

            return $service->load('category');
        });
    }

    public function activate(Service $service): Service
    {
        return $this->transitionStatus($service, ServiceStatus::ACTIVE);
    }

    public function deactivate(Service $service): Service
    {
        return $this->transitionStatus($service, ServiceStatus::INACTIVE);
    }

    public function delete(Service $service): void
    {
        DB::transaction(function () use ($service) {

            if ($service->budgetItems()->exists()) {
                throw new ServiceException(
                    'No se puede eliminar el servicio porque está en uso en presupuestos. Desactívelo en su lugar.',
                    409,
                    'SERVICE_IN_USE'
                );
            }

            // Un plan de mantenimiento lo usa para registrar el mantenimiento al completar la OT.
            if (MaintenancePlan::where('service_id', $service->id)->exists()) {
                throw new ServiceException(
                    'No se puede eliminar el servicio porque lo usa un plan de mantenimiento. Desactívelo en su lugar.',
                    409,
                    'SERVICE_IN_USE'
                );
            }

            $service->delete();
        });
    }

    private function transitionStatus(Service $service, ServiceStatus $status): Service
    {
        return DB::transaction(function () use ($service, $status) {

            $service->refresh();

            if (!$service->status->canTransitionTo($status)) {
                throw new ServiceException(
                    "El servicio ya está {$status->label()}.",
                    422,
                    'SERVICE_INVALID_STATUS_TRANSITION'
                );
            }

            $service->update(['status' => $status]);

            return $service->load('category');
        });
    }
}
