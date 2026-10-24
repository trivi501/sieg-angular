<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Fondos revolventes: usan la tabla de trámites de servicios con tipo = 'fondo'. Muchas facturas de distintos
 * proveedores, cada artículo con su partida, y la CLC a favor de un trabajador. Solo agrega columnas y permisos.
 */
return new class extends Migration
{
    private const PERMISOS = [
        'fondos-index' => 'Ver fondos revolventes',
        'fondos-create' => 'Capturar fondos revolventes (subir facturas)',
        'fondos-validar' => 'Validar fondos revolventes (genera requisición y CLC)',
    ];

    /** Cada área captura los suyos; validar se asigna desde Roles a quien corresponda. */
    private const ROLES = [
        'Super Admin' => ['fondos-index', 'fondos-create', 'fondos-validar'],
        'Admin' => ['fondos-index', 'fondos-create', 'fondos-validar'],
        'Área solicitante' => ['fondos-index', 'fondos-create'],
    ];

    public function up(): void
    {
        Schema::table('tramites_servicio', function (Blueprint $table) {
            if (! Schema::hasColumn('tramites_servicio', 'tipo')) {
                $table->string('tipo', 20)->default('servicio')->after('id');
            }
            // Trabajador a cuyo favor va la CLC del fondo revolvente (sin banco, cuenta, CLABE ni RFC).
            if (! Schema::hasColumn('tramites_servicio', 'trabajador_nombre')) {
                $table->string('trabajador_nombre', 300)->nullable();
                $table->string('trabajador_numero', 30)->nullable();
                $table->string('trabajador_puesto', 200)->nullable();
            }
        });

        $conNombre = Schema::hasColumn('permissions', 'nombre_mostrar');
        $conCategoria = Schema::hasColumn('permissions', 'categoria');
        foreach (self::PERMISOS as $nombre => $texto) {
            $permiso = Permission::findOrCreate($nombre, 'web');
            $permiso->forceFill([...($conNombre ? ['nombre_mostrar' => $texto] : []), ...($conCategoria ? ['categoria' => 'Fondos revolventes'] : [])])->save();
        }
        foreach (self::ROLES as $rol => $permisos) {
            Role::where('name', $rol)->where('guard_name', 'web')->first()?->givePermissionTo($permisos);
        }
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::table('tramites_servicio', fn (Blueprint $table) => $table->dropColumn(['tipo', 'trabajador_nombre', 'trabajador_numero', 'trabajador_puesto']));
        Permission::whereIn('name', array_keys(self::PERMISOS))->delete();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
