<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Bitácora de combustible, facturas primero: la unidad de combustibles sube los XML del proveedor antes del Excel y
 * quedan "por asignar"; al cargar el Excel, cada una se va a la bitácora de su secretaría. Separa los permisos:
 * combustible-cargar (unidad de combustibles: XML, Excel, todas las secretarías) y combustible-create (cada
 * secretaría: clasifica sus bitácoras y las envía a validar). Solo agrega una tabla y permisos; no borra datos.
 */
return new class extends Migration
{
    private const PERMISOS = [
        'combustible-cargar' => 'Subir las facturas (XML) y el Excel del proveedor de combustible (todas las secretarías)',
        'combustible-create' => 'Llenar la bitácora de combustible de su secretaría (presupuesto y concepto) y enviarla a validar',
    ];

    private const ROLES = [
        'Super Admin' => ['combustible-cargar'],
        'Admin' => ['combustible-cargar'],
        'Control de combustible' => ['combustible-cargar'],
        'Área solicitante' => ['combustible-index', 'combustible-create'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('combustible_facturas')) {
            Schema::create('combustible_facturas', function (Blueprint $table) {
                $table->id();
                $table->string('uuid', 60)->unique();
                // Serie y folio juntos, como en el Excel del proveedor (ZC45634).
                $table->string('folio', 40)->index();
                $table->string('rfc_emisor', 15);
                $table->string('nombre_emisor', 300)->nullable();
                $table->dateTime('fecha')->nullable();
                $table->decimal('total', 18, 2);
                $table->string('xml_path', 500);
                // El CFDI leído (Cfdi::leer) para crear la factura de la bitácora al asignarla.
                $table->json('cfdi');
                $table->string('sat_estado', 20)->nullable();
                $table->boolean('sat_efos')->default(false);
                $table->timestamp('sat_verificado_at')->nullable();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        $conNombre = Schema::hasColumn('permissions', 'nombre_mostrar');
        $conCategoria = Schema::hasColumn('permissions', 'categoria');
        foreach (self::PERMISOS as $nombre => $texto) {
            $permiso = Permission::findOrCreate($nombre, 'web');
            $permiso->forceFill([...($conNombre ? ['nombre_mostrar' => $texto] : []), ...($conCategoria ? ['categoria' => 'Bitácora de combustible'] : [])])->save();
        }
        foreach (self::ROLES as $rol => $permisos) {
            Role::where('name', $rol)->where('guard_name', 'web')->first()?->givePermissionTo($permisos);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('combustible_facturas');
        Permission::where('name', 'combustible-cargar')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
