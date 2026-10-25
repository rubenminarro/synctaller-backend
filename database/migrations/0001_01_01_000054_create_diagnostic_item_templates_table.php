<?php

use App\Enums\DiagnosticItemTemplateStatus;
use App\Enums\Severity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de ítems de diagnóstico reutilizables. El ítem del diagnóstico copia sus valores al elegirlo: cambiar o
 * eliminar el catálogo no altera los diagnósticos ya cargados.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('diagnostic_item_templates', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->foreignId('service_category_id')->nullable()->constrained('service_categories')->nullOnDelete();
            $table->string('title');
            $table->longText('description')->nullable();
            $table->string('severity')->default(Severity::MEDIUM->value);
            $table->boolean('requires_repair')->default(false);
            $table->boolean('requires_replacement')->default(false);
            $table->integer('estimated_time')->nullable()->comment('Tiempo estimado en minutos');
            $table->text('recommendation')->nullable();
            $table->string('status', 20)->default(DiagnosticItemTemplateStatus::ACTIVE->value);
            $table->softDeletes();
            $table->timestamps();

            $table->index('title');
        });

        Schema::table('diagnostic_items', function (Blueprint $table) {
            $table->foreignId('diagnostic_item_template_id')
                ->nullable()
                ->after('diagnostic_id')
                ->constrained('diagnostic_item_templates')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('diagnostic_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('diagnostic_item_template_id');
        });

        Schema::dropIfExists('diagnostic_item_templates');
    }
};
