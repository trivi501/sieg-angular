<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * "Quitar límite": durante 5 minutos todos los fondos revolventes aceptan facturas de más de $2,000. Cada vez que se
 * quita queda un registro (hasta cuándo, quién y si se restableció antes). Solo agrega una tabla y un permiso.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fondos_sin_limite')) {
            Schema::create('fondos_sin_limite', function (Blueprint $table) {
                $table->id();
                $table->timestamp('hasta');
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('restablecido_at')->nullable();
                $table->foreignId('restablecido_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index('hasta');
            });
        }

        $permiso = Permission::findOrCreate('fondos-sin-limite', 'web');
        foreach (['Super Admin', 'Admin', 'Control Presupuestal'] as $rol) {
            Role::where('name', $rol)->where('guard_name', 'web')->first()?->givePermissionTo($permiso);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('fondos_sin_limite');
    }
};
