<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Fondos revolventes: Control Presupuestal revisa cada factura por separado (validada o no validada, con su
 * observación) y valida el fondo. Solo agrega columnas y da al rol Control Presupuestal los permisos de fondos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tramite_servicio_facturas', function (Blueprint $table) {
            if (! Schema::hasColumn('tramite_servicio_facturas', 'revision')) {
                $table->string('revision', 20)->nullable()->after('comision'); // null (pendiente), validada, rechazada
            }
            if (! Schema::hasColumn('tramite_servicio_facturas', 'revision_observaciones')) {
                $table->text('revision_observaciones')->nullable()->after('revision');
            }
            if (! Schema::hasColumn('tramite_servicio_facturas', 'revisada_at')) {
                $table->timestamp('revisada_at')->nullable()->after('revision_observaciones');
            }
            if (! Schema::hasColumn('tramite_servicio_facturas', 'revisada_user_id')) {
                $table->foreignId('revisada_user_id')->nullable()->after('revisada_at')->constrained('users')->nullOnDelete();
            }
        });

        $permisos = array_map(fn ($p) => Permission::findOrCreate($p, 'web'), ['fondos-index', 'fondos-validar']);
        foreach (['Control Presupuestal', 'Super Admin', 'Admin'] as $rol) {
            Role::where('name', $rol)->where('guard_name', 'web')->first()?->givePermissionTo($permisos);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::table('tramite_servicio_facturas', function (Blueprint $table) {
            if (Schema::hasColumn('tramite_servicio_facturas', 'revisada_user_id')) {
                $table->dropConstrainedForeignId('revisada_user_id');
            }
            $table->dropColumn(array_values(array_filter(['revision', 'revision_observaciones', 'revisada_at'],
                fn ($c) => Schema::hasColumn('tramite_servicio_facturas', $c))));
        });
    }
};
