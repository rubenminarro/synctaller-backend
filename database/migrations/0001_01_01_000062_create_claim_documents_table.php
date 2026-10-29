<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Documentos privados de un siniestro (M4): denuncia, póliza, informe del perito,
 * autorización… El archivo vive en el disco claim_documents con un nombre
 * interno (UUID); `path` nunca se expone. Baja lógica con motivo: el archivo se
 * conserva (la retención legal depende de G10). El vínculo con la autorización
 * de la aseguradora llega con M6.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('claim_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('claim_id')->constrained('claims')->restrictOnDelete();
            $table->string('type', 30);
            $table->string('original_name', 255);
            $table->string('path', 255)->unique();
            $table->string('mime', 100);
            $table->unsignedBigInteger('size');
            $table->char('sha256', 64);
            $table->string('notes', 500)->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('deletion_reason', 1000)->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['claim_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('claim_documents');
    }
};
