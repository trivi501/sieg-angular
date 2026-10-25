<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** Reporte mensual del ISR retenido para el Departamento de Contabilidad. Solo agrega el permiso. */
return new class extends Migration
{
    private const PERMISO = 'contabilidad-isr';

    public function up(): void
    {
        $permiso = Permission::findOrCreate(self::PERMISO, 'web');
        $permiso->forceFill([
            ...(Schema::hasColumn('permissions', 'nombre_mostrar') ? ['nombre_mostrar' => 'Ver y exportar el reporte mensual de ISR retenido'] : []),
            ...(Schema::hasColumn('permissions', 'categoria') ? ['categoria' => 'Contabilidad'] : []),
        ])->save();
        foreach (['Super Admin', 'Admin', 'Contabilidad'] as $rol) {
            Role::where('name', $rol)->where('guard_name', 'web')->first()?->givePermissionTo(self::PERMISO);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('name', self::PERMISO)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
