<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modalidad de facturación del deducible (M10): la aseguradora tiene una por defecto y el siniestro la propia, más si
 * el deducible informado incluye el IVA (V15: se indica en cada siniestro, sin valor por defecto). Todo nulo: nada cambia
 * hasta cargarlo (y encender insurer.deductible_net_mode).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('insurers', function (Blueprint $table) {
            $table->string('deductible_billing_mode', 30)->nullable()->after('payment_term_days');
        });

        Schema::table('claims', function (Blueprint $table) {
            $table->string('deductible_billing_mode', 30)->nullable()->after('deductible_amount');
            $table->boolean('deductible_includes_tax')->nullable()->after('deductible_billing_mode');
        });
    }

    public function down(): void
    {
        Schema::table('claims', function (Blueprint $table) {
            $table->dropColumn(['deductible_billing_mode', 'deductible_includes_tax']);
        });

        Schema::table('insurers', function (Blueprint $table) {
            $table->dropColumn('deductible_billing_mode');
        });
    }
};
