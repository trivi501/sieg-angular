<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cierre de sesión por inactividad: última vez que el usuario hizo algo con el token. A diferencia de last_used_at
 * (que Sanctum actualiza en cada llamada), las consultas en segundo plano (notificaciones cada minuto) no cuentan.
 * Solo agrega una columna.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('personal_access_tokens', 'actividad_at')) {
            Schema::table('personal_access_tokens', function (Blueprint $table) {
                $table->timestamp('actividad_at')->nullable()->after('last_used_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('personal_access_tokens', 'actividad_at')) {
            Schema::table('personal_access_tokens', fn (Blueprint $table) => $table->dropColumn('actividad_at'));
        }
    }
};
