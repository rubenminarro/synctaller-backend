<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Siniestros (M3): uno vigente por recepción (controlado en ClaimService; uno
 * cancelado queda en el historial). El estado acompaña a la recepción: se
 * cierra al entregar, se reabre al anular la entrega y se cancela con ella.
 * Deducible: nulo = desconocido, 0 = sin deducible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('claims', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->foreignId('reception_id')->constrained('receptions')->restrictOnDelete();
            $table->foreignId('insurer_id')->constrained('insurers')->restrictOnDelete();
            $table->string('claim_number', 50)->nullable();
            $table->string('policy_number', 50)->nullable();
            $table->string('claim_type', 20);
            $table->date('loss_date');
            $table->text('loss_description')->nullable();

            $table->foreignId('insured_client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->string('insured_name', 150);
            $table->string('insured_document', 30)->nullable();
            $table->string('insured_phone', 20)->nullable();
            $table->string('insured_email', 100)->nullable();

            $table->string('adjuster_name', 150)->nullable();
            $table->string('adjuster_phone', 20)->nullable();
            $table->string('adjuster_email', 100)->nullable();
            $table->dateTime('inspection_scheduled_at')->nullable();
            $table->dateTime('inspected_at')->nullable();

            $table->decimal('deductible_amount', 14, 2)->nullable();
            $table->text('coverage_notes')->nullable();

            $table->string('status', 20)->default('open');
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancellation_reason', 1000)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['reception_id', 'status']);
            $table->index(['insurer_id', 'claim_number']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('claims');
    }
};
