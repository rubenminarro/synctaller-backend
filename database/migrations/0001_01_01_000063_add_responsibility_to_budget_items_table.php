<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Responsable de cada línea del presupuesto (M5): el dueño o la aseguradora del
 * siniestro. Las líneas existentes quedan a cargo del dueño (valor por defecto).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('budget_items', function (Blueprint $table) {
            $table->string('responsibility', 20)->default('owner')->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('budget_items', function (Blueprint $table) {
            $table->dropColumn('responsibility');
        });
    }
};
