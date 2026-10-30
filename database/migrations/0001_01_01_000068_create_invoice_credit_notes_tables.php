<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notas de crédito emitidas fuera del sistema y registradas sobre una factura
 * emitida (P10). Por línea de la factura: el importe con IVA en guaraníes, como
 * la factura, y su base sin IVA (la que libera la porción del reparto en M9).
 * Inmutables: un registro mal cargado se anula con motivo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_credit_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            // Número y timbrado de la nota externa, tal como se emitió.
            $table->string('number', 30);
            $table->string('stamp_number', 30)->nullable();
            $table->date('issue_date');
            $table->string('reason', 1000);
            $table->string('status', 20)->default('registered');
            $table->decimal('total', 14, 2);
            $table->decimal('total_exempt', 14, 2)->default(0);
            $table->decimal('total_5', 14, 2)->default(0);
            $table->decimal('total_10', 14, 2)->default(0);
            $table->decimal('iva_5', 14, 2)->default(0);
            $table->decimal('iva_10', 14, 2)->default(0);
            $table->foreignId('registered_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('cancellation_reason', 1000)->nullable();
            $table->timestamps();

            $table->index(['invoice_id', 'status']);
            $table->index('number');
        });

        Schema::create('invoice_credit_note_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_credit_note_id')->constrained('invoice_credit_notes')->cascadeOnDelete();
            $table->foreignId('invoice_item_id')->constrained('invoice_items')->restrictOnDelete();
            $table->string('description', 255);
            $table->unsignedTinyInteger('tax_rate');
            // Con IVA, en guaraníes (como la línea de la factura).
            $table->decimal('amount', 14, 2);
            // Sin IVA: la parte de la base de la línea que se acredita.
            $table->decimal('base_amount', 14, 2);
            $table->timestamps();

            $table->unique(['invoice_credit_note_id', 'invoice_item_id'], 'credit_note_items_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_credit_note_items');
        Schema::dropIfExists('invoice_credit_notes');
    }
};
