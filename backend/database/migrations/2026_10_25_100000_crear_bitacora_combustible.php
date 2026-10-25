<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Bitácora de combustible: usa la tabla de trámites de servicios con tipo = 'combustible' (BC-…). La unidad de
 * combustibles carga el Excel del proveedor (un lote); cada factura del Excel es de una secretaría, así que se
 * crea una bitácora por secretaría con sus tickets. Luego se suben los XML, se clasifican y se validan: al
 * validarse se generan requisición, orden de compra y CLC, igual que un trámite de servicios. Solo agrega tablas,
 * una columna y permisos; no borra datos.
 */
return new class extends Migration
{
    private const PERMISOS = [
        'combustible-index' => 'Ver bitácoras de combustible',
        'combustible-create' => 'Cargar el Excel del proveedor y capturar bitácoras de combustible (todas las secretarías)',
        'combustible-validar' => 'Validar bitácoras de combustible (genera requisición, orden de compra y CLC)',
    ];

    private const ROLES = [
        'Super Admin' => ['combustible-index', 'combustible-create', 'combustible-validar'],
        'Admin' => ['combustible-index', 'combustible-create', 'combustible-validar'],
        'Control de combustible' => ['combustible-index', 'combustible-create'],
        'Recursos Materiales' => ['combustible-index', 'combustible-validar'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('combustible_lotes')) {
            Schema::create('combustible_lotes', function (Blueprint $table) {
                $table->id();
                $table->string('periodo', 100);
                $table->string('archivo_nombre', 255);
                $table->string('archivo_path', 500);
                $table->string('hoja', 100)->nullable();
                $table->unsignedInteger('tickets')->default(0);
                $table->decimal('litros', 14, 3)->default(0);
                $table->decimal('importe', 18, 2)->default(0);
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasColumn('tramites_servicio', 'combustible_lote_id')) {
            Schema::table('tramites_servicio', function (Blueprint $table) {
                $table->foreignId('combustible_lote_id')->nullable()->after('tipo')->constrained('combustible_lotes')->nullOnDelete();
            });
        }

        // Un renglón por ticket del Excel del proveedor.
        if (! Schema::hasTable('combustible_cargas')) {
            Schema::create('combustible_cargas', function (Blueprint $table) {
                $table->id();
                $table->foreignId('combustible_lote_id')->constrained('combustible_lotes')->cascadeOnDelete();
                $table->foreignId('tramite_servicio_id')->constrained('tramites_servicio')->cascadeOnDelete();
                // La factura (XML) que ampara el ticket, cuando ya se subió.
                $table->foreignId('tramite_servicio_factura_id')->nullable()->constrained('tramite_servicio_facturas')->nullOnDelete();
                $table->unsignedInteger('fila');
                $table->date('fecha_ticket')->nullable();
                $table->date('fecha_vale')->nullable();
                $table->string('ticket', 40)->nullable();
                $table->string('vale', 40)->nullable();
                $table->string('numero_economico', 30)->nullable();
                $table->foreignId('vehiculo_id')->nullable()->constrained('vehiculos')->nullOnDelete();
                $table->string('departamento', 200)->nullable();
                $table->string('conductor', 200)->nullable();
                $table->string('producto', 40)->nullable();
                $table->decimal('litros', 12, 3);
                $table->decimal('precio', 12, 4)->nullable();
                $table->decimal('importe', 18, 2);
                // Folio de la factura tal como viene en el Excel (serie y folio juntos, p. ej. ZC45634).
                $table->string('factura_folio', 40);
                $table->date('fecha_factura')->nullable();
                $table->timestamps();
                $table->index('factura_folio');
                $table->index('fecha_ticket');
            });
        }

        // Permisos y su asignación a los roles que existan (no quita ninguno).
        $conNombre = Schema::hasColumn('permissions', 'nombre_mostrar');
        $conCategoria = Schema::hasColumn('permissions', 'categoria');
        foreach (self::PERMISOS as $nombre => $texto) {
            $permiso = Permission::findOrCreate($nombre, 'web');
            $permiso->forceFill([...($conNombre ? ['nombre_mostrar' => $texto] : []), ...($conCategoria ? ['categoria' => 'Bitácora de combustible'] : [])])->save();
        }
        foreach (self::ROLES as $rol => $permisos) {
            Role::where('name', $rol)->where('guard_name', 'web')->first()?->givePermissionTo($permisos);
        }
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('combustible_cargas');
        Schema::table('tramites_servicio', function (Blueprint $table) {
            $table->dropConstrainedForeignId('combustible_lote_id');
        });
        Schema::dropIfExists('combustible_lotes');
        Permission::whereIn('name', array_keys(self::PERMISOS))->delete();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
