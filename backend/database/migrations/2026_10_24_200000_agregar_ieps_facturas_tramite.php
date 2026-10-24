<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** IEPS trasladado de las facturas de trámites de servicios y fondos revolventes (para la CLC). Solo agrega. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tramite_servicio_facturas', 'ieps')) {
            Schema::table('tramite_servicio_facturas', function (Blueprint $table) {
                $table->decimal('ieps', 18, 2)->default(0)->after('iva');
            });
        }
    }

    public function down(): void
    {
        Schema::table('tramite_servicio_facturas', fn (Blueprint $table) => $table->dropColumn('ieps'));
    }
};
