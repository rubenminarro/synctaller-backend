<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reparto económico de la OT de un siniestro (M8). Cada versión divide lo vendido
 * (el cierre congelado) en porciones por concepto y responsable, sin IVA (capa
 * A), y asigna a la OT una parte del deducible del siniestro tal como se informa
 * (capa B, no facturable hasta M10). Un borrador por OT; la confirmada se
 * sustituye con otra versión.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_order_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained('work_orders')->restrictOnDelete();
            $table->foreignId('work_order_closing_id')->constrained('work_order_closings')->restrictOnDelete();
            $table->foreignId('claim_id')->constrained('claims')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 20)->default('draft');
            // Copia de control de la venta del cierre (R2).
            $table->decimal('sale_total', 14, 2);
            // Capa B: parte del deducible del siniestro asignada a esta OT, tal como se informa (no es un importe sin IVA).
            $table->decimal('deductible_amount', 14, 2)->nullable();
            $table->string('notes', 1000)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('superseded_by_id')->nullable()->constrained('work_order_allocations')->nullOnDelete();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();

            $table->unique(['work_order_id', 'version']);
            $table->index(['claim_id', 'status']);
        });

        Schema::create('work_order_allocation_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_allocation_id')->constrained('work_order_allocations')->cascadeOnDelete();
            $table->string('portion_type', 30);
            // El concepto vendido: el mismo origen que las líneas de la factura (WorkOrderItem o WorkOrderPart).
            $table->morphs('source');
            $table->foreignId('budget_item_id')->nullable()->constrained('budget_items')->nullOnDelete();
            // Responsable; nulo = el taller (solo en workshop_absorbed).
            $table->foreignId('client_id')->nullable()->constrained('clients')->restrictOnDelete();
            // Nombre propio: el automático (66 caracteres) supera el límite de 64 de MySQL.
            $table->foreignId('insurer_authorization_item_id')->nullable()
                ->constrained('insurer_authorization_items', indexName: 'allocation_lines_authorization_item_foreign')->restrictOnDelete();
            $table->foreignId('internal_exception_id')->nullable()->constrained('internal_exceptions')->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('description', 255);
            $table->timestamps();

            $table->unique(['work_order_allocation_id', 'source_type', 'source_id', 'portion_type'], 'allocation_lines_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_allocation_lines');
        Schema::dropIfExists('work_order_allocations');
    }
};
