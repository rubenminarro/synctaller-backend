<?php

namespace App\Services;

use Illuminate\Support\Str;

/**
 * Exporta los reportes de ReportService a CSV o PDF (FASE 24) sin recalcular:
 * el array del reporte se recorre y se convierte en secciones (un resumen de
 * valores sueltos y una tabla por cada lista de filas).
 */
class ReportExportService
{
    /**
     * Etiquetas en español de las claves de los reportes. Las que no estén
     * se muestran con la clave legible ("invoices_count" → "Invoices count").
     */
    private const LABELS = [
        'totals' => 'Totales',
        'invoices_count' => 'Facturas',
        'total' => 'Total',
        'exempt' => 'Exentas',
        'taxed_5' => 'Gravadas 5%',
        'taxed_10' => 'Gravadas 10%',
        'iva_5' => 'IVA 5%',
        'iva_10' => 'IVA 10%',
        'discount' => 'Descuentos',
        'average_ticket' => 'Ticket promedio',
        'by_period' => 'Por período',
        'period' => 'Período',
        'by_condition' => 'Por condición',
        'condition' => 'Condición',
        'collected' => 'Cobrado',
        'payments_count' => 'Pagos',
        'by_method' => 'Por medio de pago',
        'method' => 'Medio',
        'label' => 'Descripción',
        'cancelled' => 'Anuladas',
        'credit_notes' => 'Notas de crédito',
        'credit_notes_count' => 'Notas de crédito',
        'net_total' => 'Total neto de notas de crédito',
        'budgets_count' => 'Presupuestos',
        'by_status' => 'Por estado',
        'status' => 'Estado',
        'approval_rate' => 'Tasa de aprobación (%)',
        'work_orders_count' => 'Órdenes de trabajo',
        'sale_labor' => 'Venta mano de obra',
        'sale_parts' => 'Venta repuestos',
        'sale_total' => 'Venta total',
        'cost_parts' => 'Costo repuestos',
        'cost_labor' => 'Costo mano de obra',
        'commission_total' => 'Comisiones',
        'cost_total' => 'Costo total',
        'gross_margin' => 'Margen bruto',
        'margin_percentage' => 'Margen (%)',
        'incomplete_cost_count' => 'OT con costo incompleto',
        'lowest_margin' => 'Menor margen',
        'highest_margin' => 'Mayor margen',
        'work_order_id' => 'ID OT',
        'work_order_code' => 'OT',
        'is_cost_complete' => 'Costo completo',
        'mechanics' => 'Mecánicos',
        'mechanic_id' => 'ID mecánico',
        'employee_code' => 'Legajo',
        'full_name' => 'Nombre',
        'work_orders_as_responsible' => 'OT como responsable',
        'jobs_completed' => 'Trabajos',
        'labor_sale_attributed' => 'Venta atribuida',
        'hours_logged' => 'Horas',
        'labor_cost' => 'Costo de mano de obra',
        'stock_value' => 'Valor del stock',
        'below_minimum' => 'Bajo mínimo',
        'part_id' => 'ID repuesto',
        'part_code' => 'Código',
        'name' => 'Nombre',
        'minimum_stock' => 'Stock mínimo',
        'quantity_on_hand' => 'Existencia',
        'quantity_available' => 'Disponible',
        'top_consumed' => 'Más consumidos',
        'quantity' => 'Cantidad',
        'cost' => 'Costo',
        'movements_by_type' => 'Movimientos por tipo',
        'type' => 'Tipo',
        'movements_count' => 'Movimientos',
        'total_cost' => 'Costo total',
        'new_clients' => 'Clientes nuevos',
        'top_clients' => 'Clientes que más facturan',
        'client_id' => 'ID cliente',
        'receivables' => 'Por cobrar',
        'clients' => 'Clientes',
        'phone' => 'Teléfono',
        'balance' => 'Saldo',
        'oldest_due_date' => 'Vencimiento más antiguo',
        'top_vehicles' => 'Vehículos con más visitas',
        'vehicle_id' => 'ID vehículo',
        'plate' => 'Placa',
        'visits' => 'Visitas',
    ];

    public function __construct(
        protected DocumentPdfService $documentPdfService
    ) {
    }

    /**
     * Secciones del reporte: [{title, columns, rows}]. La primera es el
     * resumen (Concepto / Valor) si el reporte tiene valores sueltos.
     *
     * @return array<int, array{title: string, columns: string[], rows: array<int, array<int, string>>}>
     */
    public function sections(array $report): array
    {
        $summary = [];
        $tables = [];

        // Colecciones y modelos anidados → arrays planos, igual que en la respuesta JSON.
        $report = json_decode(json_encode($report), true);

        $this->walk(array_diff_key($report, ['period' => true]), [], $summary, $tables);

        if ($summary !== []) {
            array_unshift($tables, ['title' => 'Resumen', 'columns' => ['Concepto', 'Valor'], 'rows' => $summary]);
        }

        return $tables;
    }

    /**
     * CSV para Excel: UTF-8 con BOM y separador ";". Cada sección va con su
     * título y una línea en blanco entre secciones.
     *
     * @return array{filename: string, content: string}
     */
    public function csv(string $name, string $title, array $report): array
    {
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, [$title], ';');
        fputcsv($handle, ['Período', $this->periodLabel($report)], ';');

        foreach ($this->sections($report) as $section) {
            fputcsv($handle, [], ';');
            fputcsv($handle, [$section['title']], ';');
            fputcsv($handle, $section['columns'], ';');

            foreach ($section['rows'] as $row) {
                fputcsv($handle, $row, ';');
            }
        }

        rewind($handle);
        $content = "\xEF\xBB\xBF" . stream_get_contents($handle);
        fclose($handle);

        return ['filename' => $this->filename($name, $report, 'csv'), 'content' => $content];
    }

    /**
     * @return array{filename: string, content: string}
     */
    public function pdf(string $name, string $title, array $report): array
    {
        return $this->documentPdfService->render('pdf.report', $this->filename($name, $report, 'pdf'), [
            'title' => $title,
            'period' => $this->periodLabel($report),
            'sections' => $this->sections($report),
        ], 'landscape');
    }

    /**
     * Valores sueltos → resumen (con el prefijo de su grupo); listas de
     * filas → tabla; grupos anidados → recursión.
     */
    private function walk(array $data, array $path, array &$summary, array &$tables): void
    {
        foreach ($data as $key => $value) {
            $current = [...$path, $this->label((string) $key)];

            if (is_array($value) && array_is_list($value)) {
                if ($value !== [] && is_array($value[0])) {
                    $tables[] = $this->table(implode(' — ', $current), $value);
                } elseif ($value === []) {
                    $tables[] = ['title' => implode(' — ', $current), 'columns' => [], 'rows' => []];
                }

                continue;
            }

            if (is_array($value)) {
                // "totals" agrupa los valores principales: sin prefijo.
                $this->walk($value, $key === 'totals' ? $path : $current, $summary, $tables);

                continue;
            }

            $summary[] = [implode(' — ', $current), $this->value($value)];
        }
    }

    private function table(string $title, array $rows): array
    {
        $keys = array_keys(array_filter($rows[0], fn ($value) => !is_array($value)));

        return [
            'title' => $title,
            'columns' => array_map(fn ($key) => $this->label((string) $key), $keys),
            'rows' => array_map(
                fn (array $row) => array_map(fn ($key) => $this->value($row[$key] ?? null), $keys),
                $rows
            ),
        ];
    }

    private function label(string $key): string
    {
        return self::LABELS[$key] ?? Str::ucfirst(str_replace('_', ' ', $key));
    }

    /**
     * Números con coma decimal (configuración regional de Excel en español).
     */
    private function value(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'Sí' : 'No',
            is_numeric($value) => str_replace('.', ',', (string) $value),
            default => (string) $value,
        };
    }

    private function periodLabel(array $report): string
    {
        $period = $report['period'] ?? null;

        return $period ? "{$period['date_from']} a {$period['date_to']}" : '';
    }

    private function filename(string $name, array $report, string $extension): string
    {
        $period = $report['period'] ?? null;
        $suffix = $period ? "_{$period['date_from']}_{$period['date_to']}" : '';

        return "reporte-{$name}{$suffix}.{$extension}";
    }
}
