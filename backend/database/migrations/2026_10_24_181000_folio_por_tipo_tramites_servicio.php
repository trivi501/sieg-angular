<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trámites de servicios (TS-…) y fondos revolventes (FR-…) llevan cada uno su numeración: el folio es único por
 * tipo y año (antes, por año). Solo cambia el índice; no toca datos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tramites_servicio', function (Blueprint $table) {
            $table->unique(['tipo', 'año', 'folio'], 'tramites_servicio_tipo_año_folio_unique');
            $table->dropUnique('tramites_servicio_año_folio_unique');
        });
    }

    public function down(): void
    {
        Schema::table('tramites_servicio', function (Blueprint $table) {
            $table->unique(['año', 'folio'], 'tramites_servicio_año_folio_unique');
            $table->dropUnique('tramites_servicio_tipo_año_folio_unique');
        });
    }
};
