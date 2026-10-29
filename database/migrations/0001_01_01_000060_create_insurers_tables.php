<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aseguradoras (M2): entidad propia vinculada 1:1 a un cliente empresa, que es
 * quien recibe las facturas y la deuda. Razón social, RUC, email y teléfono se
 * leen del cliente, no se duplican. La modalidad del deducible llega con M10.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('insurers', function (Blueprint $table) {
            $table->id();
            // Una aseguradora vigente por cliente (InsurerService): una dada de baja no retiene a su cliente.
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->string('code', 20)->unique();
            $table->string('status', 20)->default('active');
            $table->unsignedSmallInteger('payment_term_days')->nullable();
            $table->string('notes', 1000)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('insurer_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('insurer_id')->constrained('insurers')->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('role', 30);
            $table->string('phone', 20)->nullable();
            $table->string('email', 100)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->string('notes', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insurer_contacts');
        Schema::dropIfExists('insurers');
    }
};
