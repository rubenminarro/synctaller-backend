<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contador por serie de los códigos internos (BUD-, OT-, COM-, REP-, SRV-,
 * DIA-). Solo estructura: la fila de cada serie la crea DocumentSequenceService
 * en su primer uso, con el máximo id de la tabla + 1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('key', 50)->unique();
            $table->unsignedBigInteger('next_value');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sequences');
    }
};
