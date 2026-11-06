<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trámites de servicios y bitácoras de combustible: quien valida primero revisa la suficiencia presupuestal (queda
 * el resultado y el detalle por partida) y, con suficiencia, genera la requisición, la orden de compra y la CLC.
 * Solo agrega columnas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tramites_servicio', function (Blueprint $table) {
            if (! Schema::hasColumn('tramites_servicio', 'suficiencia')) {
                $table->boolean('suficiencia')->nullable()->after('validado_user_id'); // null: sin revisar
            }
            if (! Schema::hasColumn('tramites_servicio', 'suficiencia_detalle')) {
                $table->json('suficiencia_detalle')->nullable()->after('suficiencia');
            }
            if (! Schema::hasColumn('tramites_servicio', 'suficiencia_at')) {
                $table->timestamp('suficiencia_at')->nullable()->after('suficiencia_detalle');
            }
            if (! Schema::hasColumn('tramites_servicio', 'suficiencia_user_id')) {
                $table->foreignId('suficiencia_user_id')->nullable()->after('suficiencia_at')->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('tramites_servicio', function (Blueprint $table) {
            if (Schema::hasColumn('tramites_servicio', 'suficiencia_user_id')) {
                $table->dropConstrainedForeignId('suficiencia_user_id');
            }
            $table->dropColumn(array_values(array_filter(['suficiencia', 'suficiencia_detalle', 'suficiencia_at'],
                fn ($c) => Schema::hasColumn('tramites_servicio', $c))));
        });
    }
};
