<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $admin = Role::firstOrCreate([
            'name' => 'administrador',
            'guard_name' => 'api'
        ]);

        $mecanico = Role::firstOrCreate([
            'name' => 'mechanic',
            'guard_name' => 'api',
        ]);

        $permissions = [
            
            /* users permissions */
            'users.index',
            'users.store',
            'users.show',
            'users.update',
            'users.destroy',

            /* permissions permissions */
            'permissions.index',
            'permissions.store',
            'permissions.show',
            'permissions.update',
            'permissions.destroy',

            /* roles permissions */
            'roles.index',
            'roles.store',
            'roles.show',
            'roles.update',
            'roles.destroy',
            'roles.permissions-grouped-by-module',

            /* clients permissions */
            'clients.index',
            'clients.store',
            'clients.show',
            'clients.update',
            'clients.destroy',

            /* brand permissions */
            'brands.index',
            'brands.store',
            'brands.show',
            'brands.update',
            'brands.destroy',

            /* vehicle model permissions */
            'vehicle_models.index',
            'vehicle_models.store',
            'vehicle_models.show',
            'vehicle_models.update',
            'vehicle_models.destroy',

            /* vehicle permissions */
            'vehicles.index',
            'vehicles.store',
            'vehicles.show',
            'vehicles.update',
            'vehicles.destroy',

            /* checklist permissions */
            'checklists.index',
            'checklists.store',
            'checklists.show',
            'checklists.update',
            'checklists.destroy',

            /* reception permissions */
            'receptions.index',
            'receptions.store',
            'receptions.show',
            'receptions.update',
            'receptions.destroy',
            'receptions.cancel',

            /* reception checklist permissions */
            'reception-check-lists.show',
            'reception-check-lists.update',

            /* reception photo permissions */
            'receptions-photos.index',
            'receptions-photos.store',
            'receptions-photos.update',
            'receptions-photos.destroy',

            /* diagnostic permissions */
            'diagnostics.index',
            'diagnostics.store',
            'diagnostics.show',
            'diagnostics.update',
            'diagnostics.destroy',

            /* diagnostic item permissions */
            'diagnostic-items.index',
            'diagnostic-items.store',
            'diagnostic-items.show',
            'diagnostic-items.update',
            'diagnostic-items.destroy',

            /* diagnostic item photo permissions */
            'diagnostic-item-photos.index',
            'diagnostic-item-photos.store',
            'diagnostic-item-photos.update',
            'diagnostic-item-photos.destroy',

            /* diagnostic item catalog permissions */
            'diagnostic-item-templates.index',
            'diagnostic-item-templates.store',
            'diagnostic-item-templates.show',
            'diagnostic-item-templates.update',
            'diagnostic-item-templates.destroy',
            'diagnostic-item-templates.change-status',

            /* insurer permissions */
            'insurers.index',
            'insurers.store',
            'insurers.show',
            'insurers.update',
            'insurers.destroy',
            'insurers.change-status',

            /* claim permissions */
            'claims.index',
            'claims.store',
            'claims.show',
            'claims.update',
            'claims.cancel',

            /* claim document permissions (destroy: solo administrador) */
            'claim-documents.index',
            'claim-documents.store',
            'claim-documents.download',
            'claim-documents.destroy',

            /* budget permissions */
            'budgets.index',
            'budgets.store',
            'budgets.show',
            'budgets.update',
            'budgets.destroy',
            'budgets.send',
            'budgets.approve',
            'budgets.reject',
            'budgets.cancel',
            'budgets.reopen',

            /* budget item permissions */
            'budget-items.index',
            'budget-items.store',
            'budget-items.show',
            'budget-items.update',
            'budget-items.destroy',

            /* mechanic permissions */
            'mechanics.index',
            'mechanics.store',
            'mechanics.show',
            'mechanics.update',
            'mechanics.destroy',

            /* work order permissions */
            'work-orders.index',
            'work-orders.store',
            'work-orders.show',
            'work-orders.update',
            'work-orders.start',
            'work-orders.pause',
            'work-orders.resume',
            'work-orders.complete',
            'work-orders.cancel',
            'work-orders.view-economics',

            /* work order item permissions */
            'work-order-items.start',
            'work-order-items.complete',
            'work-order-items.cancel',

            /* work order part permissions */
            'work-order-parts.index',
            'work-order-parts.store',
            'work-order-parts.update',
            'work-order-parts.reserve',
            'work-order-parts.consume',
            'work-order-parts.return',
            'work-order-parts.cancel',
            'work-order-parts.approve',

            /* part category permissions */
            'part-categories.index',
            'part-categories.store',
            'part-categories.show',
            'part-categories.update',
            'part-categories.destroy',

            /* supplier permissions */
            'suppliers.index',
            'suppliers.store',
            'suppliers.show',
            'suppliers.update',
            'suppliers.destroy',

            /* part permissions */
            'parts.index',
            'parts.store',
            'parts.show',
            'parts.update',
            'parts.destroy',
            'parts.change-status',
            'parts.manage-suppliers',

            /* service catalog permissions */
            'service-categories.index',
            'services.index',
            'services.store',
            'services.show',
            'services.update',
            'services.destroy',
            'services.change-status',

            /* work order item mechanics / commissions */
            'work-order-item-mechanics.index',
            'work-order-item-mechanics.sync',
            'work-order-item-mechanics.log-hours',
            'work-order-item-mechanics.log-hours-any',
            'mechanic-commissions.index',
            'mechanic-commissions.view-all',
            'mechanic-commissions.settle',

            /* invoicing permissions */
            'invoice-stamps.index',
            'invoice-stamps.store',
            'invoice-stamps.update',
            'invoices.index',
            'invoices.show',
            'invoices.store',
            'invoices.update',
            'invoices.issue',
            'invoices.cancel',
            'invoices.destroy',

            /* payment permissions */
            'payments.index',
            'payments.show',
            'payments.store',
            'payments.cancel',
            'payments.summary',

            /* vehicle delivery permissions */
            'vehicle-deliveries.index',
            'vehicle-deliveries.show',
            'vehicle-deliveries.store',
            'vehicle-deliveries.cancel',

            /* vehicle history permissions */
            'vehicle-history.view',

            /* preventive maintenance permissions */
            'maintenance-plans.index',
            'maintenance-plans.show',
            'maintenance-plans.store',
            'maintenance-plans.update',
            'maintenance-plans.destroy',
            'vehicle-maintenances.index',
            'vehicle-maintenances.record',
            'vehicle-maintenances.configure',
            'maintenance-alerts.index',

            /* dashboard and report permissions */
            'dashboard.view',
            'reports.sales',
            'reports.budgets',
            'reports.profitability',
            'reports.productivity',
            'reports.inventory',
            'reports.clients',

            /* inventory permissions */
            'inventory.index',
            'inventory.show',
            'inventory.entry',
            'inventory.exit',
            'inventory.adjust',

            /* purchase permissions */
            'purchases.index',
            'purchases.show',
            'purchases.store',
            'purchases.update',
            'purchases.destroy',
            'purchases.receive',
            'purchases.cancel',

            /* supplier payment permissions */
            'supplier-payments.index',
            'supplier-payments.show',
            'supplier-payments.store',
            'supplier-payments.cancel',
            'supplier-payments.summary',
            'accounts-payable.view',

            /* client notification permissions */
            'client-notifications.index',
            'client-notifications.show',
            'client-notifications.resend',

            /* audit permissions (solo administrador) */
            'audit-logs.index',
            'audit-logs.show',

            /* system settings permissions (solo administrador): logo del sistema */
            'system-settings.update',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'api']);
        }

        $admin->syncPermissions(
            Permission::where('guard_name', 'api')->get()
        );

        $mechanicPermissions = [
            'vehicles.index', 
            'vehicles.show',
            
            'receptions.index', 
            'receptions.show', 
            'receptions.update',
            
            'reception-check-lists.show', 
            'reception-check-lists.update',
            
            'receptions-photos.index', 
            'receptions-photos.store', 
            'receptions-photos.destroy',

            'diagnostics.index', 
            'diagnostics.store', 
            'diagnostics.show', 
            'diagnostics.update',
            
            'diagnostic-items.index', 
            'diagnostic-items.store', 
            'diagnostic-items.show', 
            'diagnostic-items.update', 
            'diagnostic-items.destroy',

            'diagnostic-item-photos.index', 
            'diagnostic-item-photos.store', 
            'diagnostic-item-photos.update', 
            'diagnostic-item-photos.destroy',

            'diagnostic-item-templates.index',
            'diagnostic-item-templates.show',

            'work-orders.index',
            'work-orders.show', 
            
            'work-orders.start', 
            'work-orders.pause', 
            'work-orders.resume', 
            'work-orders.complete',
            
            'work-order-items.start',
            'work-order-items.complete',

            'work-order-parts.index',
            'work-order-parts.consume',
            'work-order-parts.return',

            'service-categories.index',
            'services.index',
            'services.show',

            'work-order-item-mechanics.index',
            'work-order-item-mechanics.log-hours',
            'mechanic-commissions.index',

            'parts.index',
            'parts.show',

            'part-categories.index',

            'inventory.index',
            'inventory.show',

            'vehicle-history.view',

            'maintenance-plans.index',
            'vehicle-maintenances.index',

            'dashboard.view',

        ];

        $mecanico->syncPermissions(
            Permission::where('guard_name', 'api')->whereIn('name', $mechanicPermissions)->get()
        );

        foreach (self::workshopRoles() as $name => $role) {
            Role::firstOrCreate(
                ['name' => $name, 'guard_name' => 'api'],
                ['description' => $role['description']]
            )->syncPermissions(
                Permission::where('guard_name', 'api')->whereIn('name', $role['permissions'])->get()
            );
        }
    }

    /**
     * Roles del taller además de administrador y mecánico. Lo que queda solo
     * para el administrador: usuarios/roles/permisos, ver costos y margen
     * (work-orders.view-economics), liquidar comisiones, anular entregas y
     * gestionar timbrados.
     *
     * @return array<string, array{description: string, permissions: string[]}>
     */
    public static function workshopRoles(): array
    {
        return [
            'recepcionista' => [
                'description' => 'Recepción y atención al cliente',
                'permissions' => [
                    'clients.index', 'clients.store', 'clients.show', 'clients.update',
                    'brands.index', 'brands.show',
                    'vehicle_models.index', 'vehicle_models.show',
                    'vehicles.index', 'vehicles.store', 'vehicles.show', 'vehicles.update',
                    'checklists.index', 'checklists.show',
                    'receptions.index', 'receptions.store', 'receptions.show', 'receptions.update', 'receptions.destroy',
                    'receptions.cancel',
                    'reception-check-lists.show', 'reception-check-lists.update',
                    'receptions-photos.index', 'receptions-photos.store', 'receptions-photos.update', 'receptions-photos.destroy',
                    'diagnostics.index', 'diagnostics.show',
                    'diagnostic-items.index', 'diagnostic-items.show',
                    'diagnostic-item-photos.index',
                    'budgets.index', 'budgets.store', 'budgets.show', 'budgets.update', 'budgets.destroy',
                    'budgets.send', 'budgets.approve', 'budgets.reject', 'budgets.cancel', 'budgets.reopen',
                    'budget-items.index', 'budget-items.store', 'budget-items.show', 'budget-items.update', 'budget-items.destroy',
                    'service-categories.index', 'services.index', 'services.show',
                    'parts.index', 'parts.show', 'part-categories.index', 'inventory.index',
                    'work-orders.index', 'work-orders.show',
                    'work-order-parts.index', 'work-order-parts.approve',
                    'invoices.index', 'invoices.show',
                    'vehicle-deliveries.index', 'vehicle-deliveries.show', 'vehicle-deliveries.store',
                    'vehicle-history.view',
                    'maintenance-plans.index', 'maintenance-plans.show',
                    'vehicle-maintenances.index', 'vehicle-maintenances.record', 'maintenance-alerts.index',
                    'client-notifications.index', 'client-notifications.show', 'client-notifications.resend',
                    'insurers.index', 'insurers.store', 'insurers.show', 'insurers.update', 'insurers.destroy', 'insurers.change-status',
                    'claims.index', 'claims.store', 'claims.show', 'claims.update', 'claims.cancel',
                    'claim-documents.index', 'claim-documents.store', 'claim-documents.download',
                    'dashboard.view', 'reports.budgets',
                ],
            ],
            'jefe-taller' => [
                'description' => 'Organiza y controla el trabajo del taller',
                'permissions' => [
                    'clients.index', 'clients.show',
                    'vehicles.index', 'vehicles.show',
                    'receptions.index', 'receptions.show', 'receptions.update',
                    'reception-check-lists.show', 'reception-check-lists.update',
                    'receptions-photos.index',
                    'diagnostics.index', 'diagnostics.store', 'diagnostics.show', 'diagnostics.update', 'diagnostics.destroy',
                    'diagnostic-items.index', 'diagnostic-items.store', 'diagnostic-items.show', 'diagnostic-items.update', 'diagnostic-items.destroy',
                    'diagnostic-item-photos.index', 'diagnostic-item-photos.store', 'diagnostic-item-photos.update', 'diagnostic-item-photos.destroy',
                    'diagnostic-item-templates.index', 'diagnostic-item-templates.store', 'diagnostic-item-templates.show',
                    'diagnostic-item-templates.update', 'diagnostic-item-templates.destroy', 'diagnostic-item-templates.change-status',
                    'budgets.index', 'budgets.show', 'budget-items.index', 'budget-items.show',
                    'mechanics.index', 'mechanics.show',
                    'work-orders.index', 'work-orders.store', 'work-orders.show', 'work-orders.update',
                    'work-orders.start', 'work-orders.pause', 'work-orders.resume', 'work-orders.complete', 'work-orders.cancel',
                    'work-order-items.start', 'work-order-items.complete', 'work-order-items.cancel',
                    'work-order-parts.index', 'work-order-parts.store', 'work-order-parts.update', 'work-order-parts.reserve',
                    'work-order-parts.consume', 'work-order-parts.return', 'work-order-parts.cancel',
                    'work-order-parts.approve',
                    'work-order-item-mechanics.index', 'work-order-item-mechanics.sync',
                    'work-order-item-mechanics.log-hours', 'work-order-item-mechanics.log-hours-any',
                    'mechanic-commissions.index', 'mechanic-commissions.view-all',
                    'service-categories.index', 'services.index', 'services.show',
                    'parts.index', 'parts.show', 'part-categories.index',
                    'inventory.index', 'inventory.show',
                    'purchases.index', 'purchases.show',
                    'supplier-payments.index', 'supplier-payments.show', 'accounts-payable.view',
                    'vehicle-deliveries.index', 'vehicle-deliveries.show',
                    'vehicle-history.view',
                    'maintenance-plans.index', 'maintenance-plans.show',
                    'vehicle-maintenances.index', 'vehicle-maintenances.record', 'vehicle-maintenances.configure',
                    'maintenance-alerts.index',
                    'insurers.index', 'insurers.show',
                    'claims.index', 'claims.store', 'claims.show', 'claims.update', 'claims.cancel',
                    'claim-documents.index', 'claim-documents.store', 'claim-documents.download',
                    'dashboard.view', 'reports.productivity',
                ],
            ],
            'cajero' => [
                'description' => 'Facturación, cobros y caja',
                'permissions' => [
                    'clients.index', 'clients.show',
                    'budgets.index', 'budgets.show',
                    'work-orders.index', 'work-orders.show',
                    'invoice-stamps.index',
                    'invoices.index', 'invoices.show', 'invoices.store', 'invoices.update',
                    'invoices.issue', 'invoices.cancel', 'invoices.destroy',
                    'payments.index', 'payments.show', 'payments.store', 'payments.cancel', 'payments.summary',
                    'purchases.index', 'purchases.show',
                    'supplier-payments.index', 'supplier-payments.show', 'supplier-payments.store',
                    'supplier-payments.cancel', 'supplier-payments.summary', 'accounts-payable.view',
                    'vehicle-deliveries.index', 'vehicle-deliveries.show',
                    'vehicle-history.view',
                    'client-notifications.index', 'client-notifications.show', 'client-notifications.resend',
                    'insurers.index', 'insurers.show',
                    'claims.index', 'claims.show',
                    'claim-documents.index', 'claim-documents.download',
                    'dashboard.view', 'reports.sales', 'reports.clients',
                ],
            ],
            'deposito' => [
                'description' => 'Repuestos, proveedores e inventario',
                'permissions' => [
                    'part-categories.index', 'part-categories.store', 'part-categories.show',
                    'part-categories.update', 'part-categories.destroy',
                    'suppliers.index', 'suppliers.store', 'suppliers.show', 'suppliers.update', 'suppliers.destroy',
                    'parts.index', 'parts.store', 'parts.show', 'parts.update', 'parts.destroy',
                    'parts.change-status', 'parts.manage-suppliers',
                    'inventory.index', 'inventory.show', 'inventory.entry', 'inventory.exit', 'inventory.adjust',
                    'purchases.index', 'purchases.show', 'purchases.store', 'purchases.update', 'purchases.destroy',
                    'purchases.receive', 'purchases.cancel',
                    'supplier-payments.index', 'supplier-payments.show', 'accounts-payable.view',
                    'work-orders.index', 'work-orders.show',
                    'work-order-parts.index', 'work-order-parts.store', 'work-order-parts.update', 'work-order-parts.reserve',
                    'work-order-parts.consume', 'work-order-parts.return', 'work-order-parts.cancel',
                    'vehicle-history.view',
                    'dashboard.view', 'reports.inventory',
                ],
            ],
        ];
    }
}