<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Evidencia de la aceptación del dueño en un siniestro (M7, decisión B): la
 * autorización vigente y el deducible del siniestro al responder. Nulos en el
 * flujo particular y en las filas anteriores.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('budget_client_acceptances', function (Blueprint $table) {
            $table->foreignId('insurer_authorization_id')->nullable()->after('owner_amount')
                ->constrained('insurer_authorizations')->restrictOnDelete();
            // Tal como se informa en el siniestro (no es un importe sin IVA).
            $table->decimal('deductible_amount', 14, 2)->nullable()->after('insurer_authorization_id');
        });
    }

    public function down(): void
    {
        Schema::table('budget_client_acceptances', function (Blueprint $table) {
            $table->dropConstrainedForeignId('insurer_authorization_id');
            $table->dropColumn('deductible_amount');
        });
    }
};
