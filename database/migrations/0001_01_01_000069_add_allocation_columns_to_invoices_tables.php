<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Facturas por responsable (M9): la factura apunta a la versión del reparto que
 * factura y cada línea a su porción. Nulos en el flujo particular y en las
 * facturas anteriores.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('allocation_id')->nullable()->after('work_order_id')
                ->constrained('work_order_allocations')->restrictOnDelete();
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->foreignId('allocation_line_id')->nullable()->after('source_id')
                ->constrained('work_order_allocation_lines')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('allocation_line_id');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('allocation_id');
        });
    }
};
