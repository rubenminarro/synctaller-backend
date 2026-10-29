<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\UserRoleController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\ClientVehicleController;
use App\Http\Controllers\Api\BrandController;
use App\Http\Controllers\Api\VehicleModelController;
use App\Http\Controllers\Api\VehicleController;
use App\Http\Controllers\Api\ReceptionController;
use App\Http\Controllers\Api\CheckListController;
use App\Http\Controllers\Api\ReceptionCheckListController; 
use App\Http\Controllers\Api\ReceptionPhotoController;
use App\Http\Controllers\Api\DiagnosticController;
use App\Http\Controllers\Api\DiagnosticItemController;
use App\Http\Controllers\Api\DiagnosticItemPhotoController;
use App\Http\Controllers\Api\DiagnosticItemTemplateController;
use App\Http\Controllers\Api\InsurerController;
use App\Http\Controllers\Api\InsurerContactController;
use App\Http\Controllers\Api\ClaimController;
use App\Http\Controllers\Api\BudgetController;
use App\Http\Controllers\Api\BudgetItemController;
use App\Http\Controllers\Api\MechanicController;
use App\Http\Controllers\Api\WorkOrderController;
use App\Http\Controllers\Api\WorkOrderItemController;
use App\Http\Controllers\Api\WorkOrderPartController;
use App\Http\Controllers\Api\WorkOrderItemMechanicController;
use App\Http\Controllers\Api\MechanicCommissionController;
use App\Http\Controllers\Api\ServiceCategoryController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\InvoiceStampController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\VehicleDeliveryController;
use App\Http\Controllers\Api\VehicleHistoryController;
use App\Http\Controllers\Api\MaintenancePlanController;
use App\Http\Controllers\Api\VehicleMaintenanceController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\PartCategoryController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\PartController;
use App\Http\Controllers\Api\PartSupplierController;
use App\Http\Controllers\Api\InventoryStockController;
use App\Http\Controllers\Api\InventoryMovementController;
use App\Http\Controllers\Api\InventoryOperationController;
use App\Http\Controllers\Api\PurchaseController;
use App\Http\Controllers\Api\SupplierPaymentController;
use App\Http\Controllers\Api\ClientNotificationController;
use App\Http\Controllers\Api\UserNotificationController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\UserPreferenceController;
use App\Http\Controllers\Api\SystemSettingController;

Route::post('/login', [AuthController::class, 'login']);

// Logo del sistema (público: el login lo muestra antes de autenticar).
Route::get('/branding', [SystemSettingController::class, 'branding'])->name('branding.show');

Route::middleware('auth:sanctum')->scopeBindings()->group(function () {

    Route::post('/logout', [AuthController::class, 'logout']);

    // Usuario autenticado con los nombres de sus permisos (sin permiso específico).
    Route::get('/me', [AuthController::class, 'me']);

    Route::apiResource('users', UserRoleController::class);

    Route::apiResource('permissions', PermissionController::class);

    // Antes del apiResource: si no, roles/{role} captura la URL.
    Route::get('/roles/permissions-grouped-by-module', [RoleController::class, 'permissionsGroupedByModule'])->name('roles.permissions-grouped-by-module');

    Route::apiResource('roles', RoleController::class);

    Route::apiResource('mechanics', MechanicController::class);

    Route::apiResource('clients', ClientController::class);

    Route::apiResource('clients.vehicles', ClientVehicleController::class)->only(['index', 'store', 'show']);

    Route::apiResource('brands', BrandController::class);

    Route::apiResource('vehicle-models', VehicleModelController::class);

    Route::apiResource('vehicles', VehicleController::class);

    Route::apiResource('checklists', CheckListController::class);

    Route::apiResource('receptions', ReceptionController::class);

    Route::post('receptions/{reception}/cancel', [ReceptionController::class, 'cancel'])->name('receptions.cancel');

    // Siniestros (M3).
    Route::apiResource('claims', ClaimController::class)->except(['update', 'destroy']);

    Route::patch('claims/{claim}', [ClaimController::class, 'update'])->name('claims.update');

    Route::post('claims/{claim}/cancel', [ClaimController::class, 'cancel'])->name('claims.cancel');

    Route::get('receptions/{reception}/claim', [ClaimController::class, 'forReception'])->name('receptions.claim');

    Route::get('/reception-check-lists/{receptionCheckList}', [ReceptionCheckListController::class, 'show'])->name('reception-check-lists.show');
        
    Route::patch('/reception-check-lists/{receptionCheckList}', [ReceptionCheckListController::class, 'update'])->name('reception-check-lists.update');

    Route::apiResource('receptions.photos', ReceptionPhotoController::class)->except(['show']);

    Route::apiResource('diagnostics', DiagnosticController::class);

    Route::apiResource('diagnostic-items', DiagnosticItemController::class);

    Route::apiResource('diagnostic-items.photos', DiagnosticItemPhotoController::class)->except(['show']);

    Route::apiResource('diagnostic-item-templates', DiagnosticItemTemplateController::class)->except(['update']);

    Route::patch('diagnostic-item-templates/{diagnostic_item_template}', [DiagnosticItemTemplateController::class, 'update'])->name('diagnostic-item-templates.update');

    Route::post('diagnostic-item-templates/{diagnostic_item_template}/activate', [DiagnosticItemTemplateController::class, 'activate'])->name('diagnostic-item-templates.activate');

    Route::post('diagnostic-item-templates/{diagnostic_item_template}/deactivate', [DiagnosticItemTemplateController::class, 'deactivate'])->name('diagnostic-item-templates.deactivate');

    // Aseguradoras (M2) y sus contactos.
    Route::apiResource('insurers', InsurerController::class)->except(['update']);

    Route::patch('insurers/{insurer}', [InsurerController::class, 'update'])->name('insurers.update');

    Route::post('insurers/{insurer}/activate', [InsurerController::class, 'activate'])->name('insurers.activate');

    Route::post('insurers/{insurer}/deactivate', [InsurerController::class, 'deactivate'])->name('insurers.deactivate');

    Route::apiResource('insurers.contacts', InsurerContactController::class)->except(['show', 'update']);

    Route::patch('insurers/{insurer}/contacts/{contact}', [InsurerContactController::class, 'update'])->name('insurers.contacts.update');

    Route::apiResource('budgets', BudgetController::class);

    Route::post('budgets/{budget}/send', [BudgetController::class, 'send'])->name('budgets.send');

    Route::post('budgets/{budget}/approve', [BudgetController::class, 'approve'])->name('budgets.approve');

    Route::post('budgets/{budget}/reject', [BudgetController::class, 'reject'])->name('budgets.reject');

    Route::post('budgets/{budget}/cancel', [BudgetController::class, 'cancel'])->name('budgets.cancel');

    Route::post('budgets/{budget}/reopen', [BudgetController::class, 'reopen'])->name('budgets.reopen');

    Route::get('budgets/{budget}/pdf', [BudgetController::class, 'pdf'])->name('budgets.pdf');

    Route::apiResource('budgets.items', BudgetItemController::class);

    Route::apiResource('work-orders', WorkOrderController::class)->except(['update', 'destroy']);

    Route::patch('work-orders/{work_order}', [WorkOrderController::class, 'update'])->name('work-orders.update');

    Route::post('work-orders/{workOrder}/start', [WorkOrderController::class, 'start'])->name('work-orders.start');

    Route::post('work-orders/{workOrder}/pause', [WorkOrderController::class, 'pause'])->name('work-orders.pause');

    Route::post('work-orders/{workOrder}/resume', [WorkOrderController::class, 'resume'])->name('work-orders.resume');

    Route::post('work-orders/{workOrder}/complete', [WorkOrderController::class, 'complete'])->name('work-orders.complete');

    Route::post('work-orders/{workOrder}/cancel', [WorkOrderController::class, 'cancel'])->name('work-orders.cancel');

    Route::post('work-orders/{workOrder}/items/{item}/start', [WorkOrderItemController::class, 'start'])->name('work-orders.items.start');

    Route::post('work-orders/{workOrder}/items/{item}/complete', [WorkOrderItemController::class, 'complete'])->name('work-orders.items.complete');

    Route::post('work-orders/{workOrder}/items/{item}/cancel', [WorkOrderItemController::class, 'cancel'])->name('work-orders.items.cancel');

    Route::get('work-orders/{workOrder}/items/{item}/mechanics', [WorkOrderItemMechanicController::class, 'index'])->name('work-orders.items.mechanics.index');

    Route::put('work-orders/{workOrder}/items/{item}/mechanics', [WorkOrderItemMechanicController::class, 'sync'])->name('work-orders.items.mechanics.sync');

    Route::patch('work-orders/{workOrder}/items/{item}/mechanics/{mechanic}/hours', [WorkOrderItemMechanicController::class, 'logHours'])->name('work-orders.items.mechanics.hours');

    Route::get('mechanics/{mechanic}/commissions', [MechanicCommissionController::class, 'index'])->name('mechanics.commissions.index');

    Route::post('mechanics/{mechanic}/commissions/settle', [MechanicCommissionController::class, 'settle'])->name('mechanics.commissions.settle');

    Route::get('invoice-stamps', [InvoiceStampController::class, 'index'])->name('invoice-stamps.index');

    Route::post('invoice-stamps', [InvoiceStampController::class, 'store'])->name('invoice-stamps.store');

    Route::patch('invoice-stamps/{invoiceStamp}', [InvoiceStampController::class, 'update'])->name('invoice-stamps.update');

    Route::post('work-orders/{workOrder}/invoice', [InvoiceController::class, 'storeFromWorkOrder'])->name('work-orders.invoice.store');

    Route::get('invoices', [InvoiceController::class, 'index'])->name('invoices.index');

    Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');

    Route::get('invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])->name('invoices.pdf');

    Route::patch('invoices/{invoice}', [InvoiceController::class, 'update'])->name('invoices.update');

    Route::delete('invoices/{invoice}', [InvoiceController::class, 'destroy'])->name('invoices.destroy');

    Route::patch('invoices/{invoice}/items/{item}', [InvoiceController::class, 'updateItem'])->name('invoices.items.update');

    Route::post('invoices/{invoice}/issue', [InvoiceController::class, 'issue'])->name('invoices.issue');

    Route::post('invoices/{invoice}/cancel', [InvoiceController::class, 'cancel'])->name('invoices.cancel');

    Route::post('invoices/{invoice}/payments', [PaymentController::class, 'store'])->name('invoices.payments.store');

    // Antes de payments/{payment}: si no, la ruta con parámetro captura "summary".
    Route::get('payments/summary', [PaymentController::class, 'summary'])->name('payments.summary');

    Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');

    Route::get('payments/{payment}', [PaymentController::class, 'show'])->name('payments.show');

    Route::get('payments/{payment}/receipt', [PaymentController::class, 'receipt'])->name('payments.receipt');

    Route::post('payments/{payment}/cancel', [PaymentController::class, 'cancel'])->name('payments.cancel');

    Route::post('receptions/{reception}/delivery', [VehicleDeliveryController::class, 'store'])->name('receptions.delivery.store');

    Route::get('vehicle-deliveries', [VehicleDeliveryController::class, 'index'])->name('vehicle-deliveries.index');

    Route::get('vehicle-deliveries/{delivery}', [VehicleDeliveryController::class, 'show'])->name('vehicle-deliveries.show');

    Route::get('vehicle-deliveries/{delivery}/pdf', [VehicleDeliveryController::class, 'pdf'])->name('vehicle-deliveries.pdf');

    Route::post('vehicle-deliveries/{delivery}/cancel', [VehicleDeliveryController::class, 'cancel'])->name('vehicle-deliveries.cancel');

    Route::get('vehicles/{vehicle}/history', [VehicleHistoryController::class, 'show'])->name('vehicles.history.show');

    Route::get('vehicles/{vehicle}/history/parts', [VehicleHistoryController::class, 'parts'])->name('vehicles.history.parts');

    Route::get('vehicles/{vehicle}/history/mileage', [VehicleHistoryController::class, 'mileage'])->name('vehicles.history.mileage');

    Route::apiResource('maintenance-plans', MaintenancePlanController::class)->except(['update']);

    Route::patch('maintenance-plans/{maintenancePlan}', [MaintenancePlanController::class, 'update'])->name('maintenance-plans.update');

    Route::get('vehicles/{vehicle}/maintenances', [VehicleMaintenanceController::class, 'index'])->name('vehicles.maintenances.index');

    Route::post('vehicles/{vehicle}/maintenances', [VehicleMaintenanceController::class, 'record'])->name('vehicles.maintenances.record');

    // El plan no es una relación del vehículo: sin scoped binding.
    Route::patch('vehicles/{vehicle}/maintenances/{maintenancePlan}', [VehicleMaintenanceController::class, 'configure'])
        ->withoutScopedBindings()
        ->name('vehicles.maintenances.configure');

    Route::get('maintenance/alerts', [VehicleMaintenanceController::class, 'alerts'])->name('maintenance.alerts');

    Route::get('dashboard', [ReportController::class, 'dashboard'])->name('dashboard');

    Route::get('reports/sales', [ReportController::class, 'sales'])->name('reports.sales');

    Route::get('reports/budgets', [ReportController::class, 'budgets'])->name('reports.budgets');

    Route::get('reports/profitability', [ReportController::class, 'profitability'])->name('reports.profitability');

    Route::get('reports/productivity', [ReportController::class, 'productivity'])->name('reports.productivity');

    Route::get('reports/inventory', [ReportController::class, 'inventory'])->name('reports.inventory');

    Route::get('reports/clients', [ReportController::class, 'clients'])->name('reports.clients');

    Route::get('service-categories', [ServiceCategoryController::class, 'index'])->name('service-categories.index');

    Route::apiResource('services', ServiceController::class)->except(['update']);

    Route::patch('services/{service}', [ServiceController::class, 'update'])->name('services.update');

    Route::post('services/{service}/activate', [ServiceController::class, 'activate'])->name('services.activate');

    Route::post('services/{service}/deactivate', [ServiceController::class, 'deactivate'])->name('services.deactivate');

    Route::get('work-orders/{workOrder}/parts', [WorkOrderPartController::class, 'index'])->name('work-orders.parts.index');

    Route::post('work-orders/{workOrder}/parts', [WorkOrderPartController::class, 'store'])->name('work-orders.parts.store');

    Route::patch('work-orders/{workOrder}/parts/{part}', [WorkOrderPartController::class, 'update'])->name('work-orders.parts.update');

    Route::post('work-orders/{workOrder}/parts/{part}/approve', [WorkOrderPartController::class, 'approve'])->name('work-orders.parts.approve');

    Route::post('work-orders/{workOrder}/parts/{part}/reject', [WorkOrderPartController::class, 'reject'])->name('work-orders.parts.reject');

    Route::post('work-orders/{workOrder}/parts/{part}/reserve', [WorkOrderPartController::class, 'reserve'])->name('work-orders.parts.reserve');

    Route::post('work-orders/{workOrder}/parts/{part}/consume', [WorkOrderPartController::class, 'consume'])->name('work-orders.parts.consume');

    Route::post('work-orders/{workOrder}/parts/{part}/return', [WorkOrderPartController::class, 'returnToStock'])->name('work-orders.parts.return');

    Route::post('work-orders/{workOrder}/parts/{part}/cancel', [WorkOrderPartController::class, 'cancel'])->name('work-orders.parts.cancel');

    Route::apiResource('part-categories', PartCategoryController::class)->except(['update']);

    Route::patch('part-categories/{part_category}', [PartCategoryController::class, 'update'])->name('part-categories.update');

    Route::apiResource('suppliers', SupplierController::class)->except(['update']);

    Route::patch('suppliers/{supplier}', [SupplierController::class, 'update'])->name('suppliers.update');

    Route::apiResource('parts', PartController::class)->except(['update']);

    Route::patch('parts/{part}', [PartController::class, 'update'])->name('parts.update');

    Route::post('parts/{part}/activate', [PartController::class, 'activate'])->name('parts.activate');

    Route::post('parts/{part}/deactivate', [PartController::class, 'deactivate'])->name('parts.deactivate');

    Route::post('parts/{part}/discontinue', [PartController::class, 'discontinue'])->name('parts.discontinue');

    Route::post('parts/{part}/suppliers', [PartSupplierController::class, 'store'])->name('parts.suppliers.store');

    Route::patch('parts/{part}/suppliers/{supplier}', [PartSupplierController::class, 'update'])->name('parts.suppliers.update');

    Route::delete('parts/{part}/suppliers/{supplier}', [PartSupplierController::class, 'destroy'])->name('parts.suppliers.destroy');

    Route::get('parts/{part}/movements', [InventoryMovementController::class, 'partMovements'])->name('parts.movements.index');

    Route::get('inventory/stocks', [InventoryStockController::class, 'index'])->name('inventory.stocks.index');

    Route::get('inventory/movements', [InventoryMovementController::class, 'index'])->name('inventory.movements.index');

    Route::get('inventory/movements/{movement}', [InventoryMovementController::class, 'show'])->name('inventory.movements.show');

    Route::post('inventory/entries', [InventoryOperationController::class, 'storeEntry'])->name('inventory.entries.store');

    Route::post('inventory/exits', [InventoryOperationController::class, 'storeExit'])->name('inventory.exits.store');

    Route::post('inventory/adjustments', [InventoryOperationController::class, 'storeAdjustment'])->name('inventory.adjustments.store');

    Route::get('purchases', [PurchaseController::class, 'index'])->name('purchases.index');

    Route::post('purchases', [PurchaseController::class, 'store'])->name('purchases.store');

    Route::get('purchases/{purchase}', [PurchaseController::class, 'show'])->name('purchases.show');

    Route::patch('purchases/{purchase}', [PurchaseController::class, 'update'])->name('purchases.update');

    Route::delete('purchases/{purchase}', [PurchaseController::class, 'destroy'])->name('purchases.destroy');

    Route::post('purchases/{purchase}/items', [PurchaseController::class, 'storeItem'])->name('purchases.items.store');

    Route::patch('purchases/{purchase}/items/{item}', [PurchaseController::class, 'updateItem'])->name('purchases.items.update');

    Route::delete('purchases/{purchase}/items/{item}', [PurchaseController::class, 'destroyItem'])->name('purchases.items.destroy');

    Route::post('purchases/{purchase}/receive', [PurchaseController::class, 'receive'])->name('purchases.receive');

    Route::post('purchases/{purchase}/cancel', [PurchaseController::class, 'cancel'])->name('purchases.cancel');

    Route::get('purchases/{purchase}/payments', [SupplierPaymentController::class, 'purchasePayments'])->name('purchases.payments.index');

    Route::post('purchases/{purchase}/payments', [SupplierPaymentController::class, 'store'])->name('purchases.payments.store');

    // Antes de supplier-payments/{supplierPayment}: si no, la ruta con parámetro captura "summary".
    Route::get('supplier-payments/summary', [SupplierPaymentController::class, 'summary'])->name('supplier-payments.summary');

    Route::get('supplier-payments', [SupplierPaymentController::class, 'index'])->name('supplier-payments.index');

    Route::get('supplier-payments/{supplierPayment}', [SupplierPaymentController::class, 'show'])->name('supplier-payments.show');

    Route::post('supplier-payments/{supplierPayment}/cancel', [SupplierPaymentController::class, 'cancel'])->name('supplier-payments.cancel');

    Route::get('accounts-payable', [SupplierPaymentController::class, 'accountsPayable'])->name('accounts-payable');

    Route::get('client-notifications', [ClientNotificationController::class, 'index'])->name('client-notifications.index');

    Route::get('client-notifications/{clientNotification}', [ClientNotificationController::class, 'show'])->name('client-notifications.show');

    Route::post('client-notifications/{clientNotification}/resend', [ClientNotificationController::class, 'resend'])->name('client-notifications.resend');

    Route::get('clients/{client}/notifications', [ClientNotificationController::class, 'clientNotifications'])->name('clients.notifications.index');

    Route::get('audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');

    Route::get('audit-logs/{auditLog}', [AuditLogController::class, 'show'])->name('audit-logs.show');

    // Bandeja interna del usuario autenticado (sin permisos: solo sus avisos).
    Route::get('me/notifications', [UserNotificationController::class, 'index'])->name('me.notifications.index');

    Route::post('me/notifications/read-all', [UserNotificationController::class, 'markAllAsRead'])->name('me.notifications.read-all');

    Route::patch('me/notifications/{notification}/read', [UserNotificationController::class, 'markAsRead'])->name('me.notifications.read');

    // Preferencias visuales del usuario autenticado (sin permisos: solo las suyas).
    Route::get('me/preferences', [UserPreferenceController::class, 'show'])->name('me.preferences.show');

    Route::patch('me/preferences', [UserPreferenceController::class, 'update'])->name('me.preferences.update');

    Route::delete('me/preferences', [UserPreferenceController::class, 'destroy'])->name('me.preferences.destroy');

    Route::post('system-settings/logo', [SystemSettingController::class, 'uploadLogo'])->name('system-settings.logo.update');

    Route::delete('system-settings/logo', [SystemSettingController::class, 'deleteLogo'])->name('system-settings.logo.destroy');

    Route::post('system-settings/favicon', [SystemSettingController::class, 'uploadFavicon'])->name('system-settings.favicon.update');

    Route::delete('system-settings/favicon', [SystemSettingController::class, 'deleteFavicon'])->name('system-settings.favicon.destroy');

});
