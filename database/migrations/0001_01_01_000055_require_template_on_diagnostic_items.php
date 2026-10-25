<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El ítem del diagnóstico se elige siempre del catálogo (como el repuesto en una línea de compra; decisión del
 * 2026-10-24). Los ítems libres existentes pasan al catálogo: se vinculan a la entrada vigente con su mismo título o a
 * una nueva creada con sus datos. Después el vínculo es obligatorio y la entrada usada no se borra físicamente (la baja
 * del catálogo es lógica).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('diagnostic_items')
            ->whereNull('diagnostic_item_template_id')
            ->orderBy('id')
            ->get()
            ->each(function (object $item) {
                $templateId = DB::table('diagnostic_item_templates')
                    ->whereNull('deleted_at')
                    ->where('title', $item->title)
                    ->value('id');

                if (!$templateId) {
                    $nextId = (DB::table('diagnostic_item_templates')->max('id') ?? 0) + 1;

                    $templateId = DB::table('diagnostic_item_templates')->insertGetId([
                        'code' => 'DIA-' . str_pad($nextId, 6, '0', STR_PAD_LEFT),
                        'title' => $item->title,
                        'severity' => $item->severity,
                        'requires_repair' => $item->requires_repair,
                        'requires_replacement' => $item->requires_replacement,
                        'estimated_time' => $item->estimated_time,
                        'recommendation' => $item->recommendation,
                        'status' => 'active',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                DB::table('diagnostic_items')->where('id', $item->id)->update(['diagnostic_item_template_id' => $templateId]);
            });

        Schema::table('diagnostic_items', function (Blueprint $table) {
            $table->dropForeign(['diagnostic_item_template_id']);
        });

        Schema::table('diagnostic_items', function (Blueprint $table) {
            $table->unsignedBigInteger('diagnostic_item_template_id')->nullable(false)->change();
            $table->foreign('diagnostic_item_template_id')->references('id')->on('diagnostic_item_templates')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('diagnostic_items', function (Blueprint $table) {
            $table->dropForeign(['diagnostic_item_template_id']);
        });

        Schema::table('diagnostic_items', function (Blueprint $table) {
            $table->unsignedBigInteger('diagnostic_item_template_id')->nullable()->change();
            $table->foreign('diagnostic_item_template_id')->references('id')->on('diagnostic_item_templates')->nullOnDelete();
        });
    }
};
