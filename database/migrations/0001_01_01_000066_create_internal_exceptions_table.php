<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Excepciones internas del flujo de siniestros (M7, decisión C): empezar la OT
 * sin la autorización de la aseguradora (sobre el presupuesto) o absorber una
 * parte del dueño (sobre la OT, se usa en el reparto). Inmutables: solo se
 * registra una vez la operación que la consumió.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('internal_exceptions', function (Blueprint $table) {
            $table->id();
            $table->string('type', 40);
            $table->morphs('subject');
            $table->decimal('amount', 14, 2)->nullable();
            $table->string('reason', 1000);
            $table->foreignId('granted_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('granted_at');
            $table->timestamp('consumed_at')->nullable();
            $table->nullableMorphs('consumed_by');
            $table->timestamps();

            $table->index(['type', 'subject_type', 'subject_id', 'consumed_at'], 'internal_exceptions_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('internal_exceptions');
    }
};
