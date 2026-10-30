<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ClaimsReportRequest;
use App\Http\Requests\ReceivablesReportRequest;
use App\Http\Requests\ReportRequest;
use App\Services\ClaimSummaryService;
use App\Services\ReportExportService;
use App\Services\ReportService;
use App\Traits\ApiResponse;
use App\Traits\FileResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Dashboard y reportes (solo lectura). Un permiso por reporte; el dashboard
 * muestra solo los bloques de los reportes que el usuario puede ver. Con
 * ?format=csv|pdf el reporte se descarga (mismo permiso).
 */
class ReportController extends Controller implements HasMiddleware
{
    use ApiResponse, FileResponse;

    /**
     * Bloque del dashboard => permiso que lo habilita.
     */
    private const DASHBOARD_BLOCKS = [
        'sales' => 'reports.sales',
        'budgets' => 'reports.budgets',
        'profitability' => 'reports.profitability',
        'productivity' => 'reports.productivity',
        'inventory' => 'reports.inventory',
        'maintenance' => 'maintenance-alerts.index',
    ];

    public function __construct(
        protected ReportService $reportService,
        protected ReportExportService $reportExportService,
        protected ClaimSummaryService $claimSummaryService
    ) {
    }

    public static function middleware(): array
    {
        return [
            new Middleware('permission:dashboard.view', only: ['dashboard']),
            new Middleware('permission:reports.sales', only: ['sales']),
            new Middleware('permission:reports.budgets', only: ['budgets']),
            new Middleware('permission:reports.profitability', only: ['profitability']),
            new Middleware('permission:reports.productivity', only: ['productivity']),
            new Middleware('permission:reports.inventory', only: ['inventory']),
            new Middleware('permission:reports.clients', only: ['clients']),
            new Middleware('permission:reports.receivables', only: ['receivables']),
            new Middleware('permission:reports.claims', only: ['claims']),
        ];
    }

    public function dashboard(Request $request)
    {
        $allowed = collect(self::DASHBOARD_BLOCKS)
            ->filter(fn (string $permission) => $request->user()->can($permission))
            ->keys()
            ->all();

        return $this->successResponse(
            'Dashboard obtenido correctamente.',
            $this->reportService->dashboard($allowed),
            200
        );
    }

    public function sales(ReportRequest $request)
    {
        return $this->report($request, 'ventas', 'Reporte de ventas', $this->reportService->sales(
            $request->from(),
            $request->to(),
            $request->group_by ?? 'day'
        ));
    }

    public function budgets(ReportRequest $request)
    {
        return $this->report($request, 'presupuestos', 'Reporte de presupuestos', $this->reportService->budgets($request->from(), $request->to()));
    }

    public function profitability(ReportRequest $request)
    {
        return $this->report($request, 'rentabilidad', 'Reporte de rentabilidad', $this->reportService->profitability($request->from(), $request->to()));
    }

    public function productivity(ReportRequest $request)
    {
        return $this->report($request, 'productividad', 'Reporte de productividad', $this->reportService->productivity($request->from(), $request->to()));
    }

    public function inventory(ReportRequest $request)
    {
        return $this->report($request, 'inventario', 'Reporte de inventario', $this->reportService->inventory($request->from(), $request->to()));
    }

    public function clients(ReportRequest $request)
    {
        return $this->report($request, 'clientes', 'Reporte de clientes', $this->reportService->clients($request->from(), $request->to()));
    }

    /**
     * Cuentas por cobrar a hoy, por tipo de destinatario (M11).
     */
    public function receivables(ReceivablesReportRequest $request)
    {
        return $this->report($request, 'cuentas-por-cobrar', 'Cuentas por cobrar', $this->reportService->receivables(
            $request->recipientType(),
            $request->filled('client_id') ? (int) $request->client_id : null
        ));
    }

    /**
     * Siniestros del período, de lo presupuestado a lo cobrado (M11).
     */
    public function claims(ClaimsReportRequest $request)
    {
        return $this->report($request, 'siniestros', 'Reporte de siniestros', $this->claimSummaryService->report(
            $request->from(),
            $request->to(),
            $request->filled('insurer_id') ? (int) $request->insurer_id : null,
            $request->status()
        ));
    }

    private function report(ReportRequest $request, string $name, string $title, array $data)
    {
        return match ($request->input('format', 'json')) {
            'csv' => $this->fileResponse($this->reportExportService->csv($name, $title, $data), 'text/csv; charset=UTF-8'),
            'pdf' => $this->fileResponse($this->reportExportService->pdf($name, $title, $data), 'application/pdf'),
            default => $this->successResponse("{$title} obtenido correctamente.", $data, 200),
        };
    }
}
