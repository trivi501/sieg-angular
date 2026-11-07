<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Viáticos (trámite VI-…): como el fondo revolvente pero sin límite de monto por factura. Usa las mismas tablas
 * (tramites_servicio, tipo = viatico); solo agrega sus permisos y los da a los roles. No toca datos.
 */
return new class extends Migration
{
    private const ROLES = [
        'Super Admin' => ['viaticos-index', 'viaticos-create', 'viaticos-validar'],
        'Admin' => ['viaticos-index', 'viaticos-create', 'viaticos-validar'],
        'Área solicitante' => ['viaticos-index', 'viaticos-create'],
        'Control Presupuestal' => ['viaticos-index', 'viaticos-validar'],
    ];

    public function up(): void
    {
        foreach (['viaticos-index', 'viaticos-create', 'viaticos-validar'] as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }
        foreach (self::ROLES as $rol => $permisos) {
            Role::where('name', $rol)->where('guard_name', 'web')->first()?->givePermissionTo($permisos);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Los permisos se quedan: quitarlos borraría lo asignado a usuarios y roles.
    }
};
