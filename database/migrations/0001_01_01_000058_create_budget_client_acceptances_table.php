<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historial de las respuestas del cliente a un presupuesto (aprobación o
 * rechazo): quién lo registró, cuándo, por qué medio y por qué importe. Sin
 * filas para los presupuestos anteriores (no hay de dónde sacar esos datos).
 * Las columnas de aseguradora llegan con M7.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_client_acceptances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_id')->constrained('budgets')->restrictOnDelete();
            $table->string('kind', 20);
            $table->foreignId('decided_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at');
            $table->string('channel', 20)->nullable();
            $table->string('contact', 150)->nullable();
            $table->string('notes', 500)->nullable();
            // Importe sin IVA a cargo del cliente al responder (en un particular, el subtotal).
            $table->decimal('owner_amount', 14, 2);
            $table->timestamps();

            $table->index(['budget_id', 'decided_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_client_acceptances');
    }
};
