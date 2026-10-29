<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Roles de los módulos de combustible, Tesorería, contratos, padrón de proveedores y Contabilidad, con sus permisos.
 * Apto para producción: crea los roles que falten y solo AGREGA permisos (nunca quita los que cada rol ya tenga ni
 * crea usuarios). Se puede correr las veces que sea.
 *
 *   php artisan db:seed --class=RolesSistemaSeeder --force
 *
 * Super Admin y Admin reciben todo con `php artisan permissions:sync`.
 */
class RolesSistemaSeeder extends Seeder
{
    public const ROLES = [
        // Cada secretaría: captura servicios y fondos, llena sus bitácoras de combustible y sus contratos.
        'Área solicitante' => [
            'servicios-index', 'servicios-create', 'fondos-index', 'fondos-create',
            'combustible-index', 'combustible-create', 'contratos-index', 'contratos-create',
        ],
        // Valida trámites y bitácoras, autoriza contratos y consulta el padrón.
        'Recursos Materiales' => [
            'servicios-index', 'servicios-validar', 'combustible-index', 'combustible-validar',
            'contratos-index', 'contratos-autorizar', 'padron-index',
        ],
        // Unidad de combustibles: sube XML y Excel del proveedor (todas las secretarías); puede llenar bitácoras.
        'Control de combustible' => ['combustible-index', 'combustible-cargar', 'combustible-create'],
        // Padrón de proveedores y contratistas: expediente, cédulas, suspensión.
        'Órgano Interno de Control' => ['padron-index', 'padron-gestionar'],
        // Mesa de control de pagos, conciliación, reportes y cuentas bancarias del Ayuntamiento.
        'Tesorería' => [
            'tesoreria-index', 'tesoreria-pagar', 'tesoreria-conciliar',
            'catalogos-tesoreria-cuentas-index', 'catalogos-tesoreria-cuentas-create', 'catalogos-tesoreria-cuentas-edit',
        ],
        // Reporte mensual del ISR retenido.
        'Contabilidad' => ['contabilidad-isr'],
        // Importa la nómina de Nomipac y captura finiquitos y laudos (capítulo 1000).
        'Recursos Humanos' => ['nomina-index', 'nomina-create', 'presupuesto-capitulo-1000'],
        // Valida la nómina, los finiquitos y laudos y los fondos revolventes (factura por factura); al validar se
        // generan las CLC.
        'Control Presupuestal' => ['nomina-index', 'nomina-validar', 'presupuesto-capitulo-1000', 'fondos-index', 'fondos-validar'],
    ];

    public function run(): void
    {
        foreach (self::ROLES as $rol => $permisos) {
            $role = Role::findOrCreate($rol, 'web');
            // findOrCreate: si una migración todavía no creó el permiso, se crea aquí.
            $role->givePermissionTo(array_map(fn ($p) => Permission::findOrCreate($p, 'web'), $permisos));
            $this->command?->info("{$rol}: ".count($permisos).' permisos asegurados.');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
