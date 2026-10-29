<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cliente persona o empresa (M1). Los clientes existentes quedan como personas
 * (valor por defecto); nombre y apellido pasan a nulos solo para las empresas,
 * que llevan razón social.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('kind', 20)->default('person')->after('id');
            $table->string('business_name', 150)->nullable()->after('last_name');
            $table->string('address', 255)->nullable()->after('email');
            $table->string('first_name')->nullable()->change();
            $table->string('last_name')->nullable()->change();

            $table->index('business_name');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropIndex(['business_name']);
            $table->dropColumn(['kind', 'business_name', 'address']);
            // Volver a NOT NULL falla si quedaron empresas sin nombre: hay que resolverlas antes.
            $table->string('first_name')->nullable(false)->change();
            $table->string('last_name')->nullable(false)->change();
        });
    }
};
