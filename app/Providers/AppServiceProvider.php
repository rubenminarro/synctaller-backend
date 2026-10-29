<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;
use Illuminate\Database\Eloquent\Relations\Relation;
use App\Models\Budget;
use App\Models\Invoice;
use App\Models\PurchaseItem;
use App\Models\Reception;
use App\Models\VehicleMaintenance;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Models\WorkOrderPart;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Alias para referencias de inventario, orígenes de líneas de factura,
        // documentos que originan avisos a clientes y sujetos de las excepciones
        // internas. Sin enforce: otras relaciones polimórficas (Spatie,
        // notificaciones de usuarios) siguen guardando el nombre de clase.
        Relation::morphMap([
            'work_order_part' => WorkOrderPart::class,
            'work_order_item' => WorkOrderItem::class,
            'work_order' => WorkOrder::class,
            'purchase_item' => PurchaseItem::class,
            'budget' => Budget::class,
            'reception' => Reception::class,
            'invoice' => Invoice::class,
            'vehicle_maintenance' => VehicleMaintenance::class,
        ]);

        // Bypass implícito para el rol 'administrador'
        Gate::before(function ($user, $ability) {
            return $user->hasRole('administrador') ? true : null;
        });
    }
}
