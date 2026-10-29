<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            AdminUserSeeder::class,
            MechanicSeeder::class,
            BrandSeeder::class,
            VehicleModelSeeder::class,
            ServiceCategorySeeder::class,
            ServiceSeeder::class,
            DiagnosticItemTemplateSeeder::class,
            MaintenancePlanSeeder::class,
            CheckListItemSeeder::class,
            CheckListItemServiceCategorySeeder::class,
            // Repuestos y stock antes del flujo del taller: los presupuestos y las OT los usan.
            PartCategorySeeder::class,
            SupplierSeeder::class,
            PartSeeder::class,
            InventorySeeder::class,
            // Compras antes del flujo del taller: el kardex se carga en orden cronológico.
            PurchaseSeeder::class,
            SupplierPaymentSeeder::class,
            ClientsSeeder::class,
            InsurerSeeder::class,
            VehiclesSeeder::class,
            // Flujo del taller por escenarios (ExampleScenarios).
            ReceptionSeeder::class,
            ClaimSeeder::class,
            DiagnosticSeeder::class,
            DiagnosticItemSeeder::class,
            DiagnosticItemPhotoSeeder::class,
            BudgetSeeder::class,
            BudgetItemSeeder::class,
            WorkOrderSeeder::class,
            ReceptionCancellationSeeder::class,
            // Facturación, cobro y entrega de los trabajos terminados.
            InvoiceStampSeeder::class,
            InvoiceSeeder::class,
            PaymentSeeder::class,
            VehicleDeliverySeeder::class,
            // Ajustes de los últimos días, después de todos los consumos.
            InventoryAdjustmentSeeder::class,
            // Mantenimientos, comisiones liquidadas y estados de los avisos (al final: usan todo lo anterior).
            VehicleMaintenanceSeeder::class,
            CommissionSeeder::class,
            NotificationSeeder::class,
        ]);
    }
}
