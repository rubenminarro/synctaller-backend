<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Autorizaciones de la aseguradora (M6): una por presupuesto y versión, línea por
 * línea, con los importes sin IVA (como el presupuesto). Inmutables: una nueva
 * versión sustituye a la vigente, y reabrir o cancelar el presupuesto la
 * invalida. Los documentos del siniestro pueden quedar vinculados como evidencia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('insurer_authorizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_id')->constrained('budgets')->restrictOnDelete();
            $table->foreignId('claim_id')->constrained('claims')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 20)->default('current');
            $table->string('result', 20);
            $table->string('authorization_number', 50)->nullable();
            $table->date('decided_at');
            $table->string('channel', 20);
            $table->string('adjuster_name', 150)->nullable();
            $table->decimal('authorized_total', 14, 2);
            $table->string('notes', 1000)->nullable();
            $table->foreignId('registered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('superseded_by_id')->nullable()->constrained('insurer_authorizations')->nullOnDelete();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamp('invalidated_at')->nullable();
            $table->string('invalidation_reason', 1000)->nullable();
            $table->timestamps();

            $table->unique(['budget_id', 'version']);
            $table->index(['budget_id', 'status']);
        });

        Schema::create('insurer_authorization_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('insurer_authorization_id')->constrained('insurer_authorizations')->cascadeOnDelete();
            // El ítem se borra físicamente si el presupuesto reabierto lo quita: la línea guarda su copia.
            $table->foreignId('budget_item_id')->nullable()->constrained('budget_items')->nullOnDelete();
            $table->string('description', 255);
            $table->decimal('budgeted_quantity', 10, 2);
            $table->decimal('budgeted_total', 14, 2);
            $table->string('decision', 20);
            $table->decimal('authorized_quantity', 10, 2);
            $table->decimal('authorized_unit_price', 14, 2);
            $table->decimal('authorized_total', 14, 2);
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->unique(['insurer_authorization_id', 'budget_item_id'], 'insurer_auth_items_unique');
        });

        Schema::table('claim_documents', function (Blueprint $table) {
            $table->foreignId('insurer_authorization_id')->nullable()->after('claim_id')
                ->constrained('insurer_authorizations')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('claim_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('insurer_authorization_id');
        });
        Schema::dropIfExists('insurer_authorization_items');
        Schema::dropIfExists('insurer_authorizations');
    }
};
