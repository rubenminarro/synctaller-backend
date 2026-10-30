<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Discrepancias D3, D7 y D8 (handoff 14.1; decisiones del usuario del 2026-10-30):
 * - D3: receptions.approved_by nunca se asignaba (siempre null): se elimina.
 * - D7: el email del cliente era único solo en la validación: índice único en la base.
 * - D8: documento y email del cliente, obligatorios en la API, pasan a NOT NULL.
 *
 * No inventa datos: si hay clientes sin documento o sin email, o emails repetidos, se detiene y hay que completarlos
 * a mano. Los dados de baja ya liberan sus campos únicos con el sufijo //deleted_.
 */
return new class extends Migration
{
    public function up(): void
    {
        $missing = DB::table('clients')->whereNull('email')->orWhereNull('document_number')->count();
        $repeated = DB::table('clients')->whereNotNull('email')->select('email')->groupBy('email')->havingRaw('count(*) > 1')->get()->count();

        if ($missing > 0 || $repeated > 0) {
            throw new RuntimeException(
                "No se puede migrar: {$missing} cliente(s) sin documento o sin email y {$repeated} email(s) repetido(s). Complételos antes de migrar."
            );
        }

        Schema::table('clients', function (Blueprint $table) {
            $table->string('document_number', 30)->nullable(false)->change();
            $table->string('email')->nullable(false)->change();
            $table->unique('email');
        });

        Schema::table('receptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
        });
    }

    public function down(): void
    {
        Schema::table('receptions', function (Blueprint $table) {
            $table->foreignId('approved_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->dropUnique(['email']);
            $table->string('email')->nullable()->change();
            $table->string('document_number', 30)->nullable()->change();
        });
    }
};
