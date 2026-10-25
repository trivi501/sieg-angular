<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Padrón de proveedores y contratistas del Órgano Interno de Control: datos del padrón en el proveedor (tipo de
 * persona, representante, giro, régimen, compra única, estado en el padrón), su expediente (documentos con vigencia)
 * y sus cédulas (CED-AÑO-0001, vigencia de un año; la última se copia a proveedores.vigencia, que ya bloquea las
 * órdenes de compra cuando vence). Solo agrega columnas, tablas y permisos; no borra datos.
 */
return new class extends Migration
{
    private const PERMISOS = [
        'padron-index' => 'Ver el padrón de proveedores, sus cédulas y alertas de vencimiento',
        'padron-gestionar' => 'Órgano Interno de Control: registrar proveedores, su expediente, emitir y suspender cédulas',
    ];

    private const ROLES = [
        'Super Admin' => ['padron-index', 'padron-gestionar'],
        'Admin' => ['padron-index', 'padron-gestionar'],
        'Órgano Interno de Control' => ['padron-index', 'padron-gestionar'],
        'Recursos Materiales' => ['padron-index'],
    ];

    public function up(): void
    {
        $columnas = [
            'tipo_persona' => fn (Blueprint $t) => $t->string('tipo_persona', 10)->nullable(), // fisica | moral
            'representante_legal' => fn (Blueprint $t) => $t->string('representante_legal', 300)->nullable(),
            'giro' => fn (Blueprint $t) => $t->string('giro', 500)->nullable(),
            'regimen_fiscal' => fn (Blueprint $t) => $t->string('regimen_fiscal', 200)->nullable(),
            // Proveedor eventual (una compra): se identifica aparte y no necesita cédula.
            'compra_unica' => fn (Blueprint $t) => $t->boolean('compra_unica')->default(false),
            // null (sin registro) | en_revision | registrado | rechazado | suspendido
            'padron_estado' => fn (Blueprint $t) => $t->string('padron_estado', 20)->nullable(),
            'padron_observaciones' => fn (Blueprint $t) => $t->text('padron_observaciones')->nullable(),
        ];
        $faltan = array_filter($columnas, fn ($c) => ! Schema::hasColumn('proveedores', $c), ARRAY_FILTER_USE_KEY);
        if ($faltan) {
            Schema::table('proveedores', function (Blueprint $table) use ($faltan) {
                foreach ($faltan as $definir) {
                    $definir($table);
                }
            });
        }

        if (! Schema::hasTable('proveedor_documentos')) {
            Schema::create('proveedor_documentos', function (Blueprint $table) {
                $table->id();
                $table->foreignId('proveedor_id')->constrained('proveedores')->cascadeOnDelete();
                $table->string('tipo', 40);
                $table->string('nombre_archivo', 255);
                $table->string('archivo_path', 500);
                $table->date('fecha_documento')->nullable();
                $table->date('vigente_hasta')->nullable();
                $table->string('sentido', 20)->nullable(); // opinión del SAT: positiva | negativa
                $table->string('observaciones', 1000)->nullable();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['proveedor_id', 'tipo']);
            });
        }

        if (! Schema::hasTable('proveedor_cedulas')) {
            Schema::create('proveedor_cedulas', function (Blueprint $table) {
                $table->id();
                $table->foreignId('proveedor_id')->constrained('proveedores')->cascadeOnDelete();
                $table->unsignedInteger('folio');
                $table->unsignedSmallInteger('año');
                $table->string('numero', 30)->unique(); // CED-2026-0001
                $table->date('fecha_emision');
                $table->date('vigencia');
                $table->string('observaciones', 1000)->nullable();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['año', 'folio']);
            });
        }

        $conNombre = Schema::hasColumn('permissions', 'nombre_mostrar');
        $conCategoria = Schema::hasColumn('permissions', 'categoria');
        foreach (self::PERMISOS as $nombre => $texto) {
            $permiso = Permission::findOrCreate($nombre, 'web');
            $permiso->forceFill([...($conNombre ? ['nombre_mostrar' => $texto] : []), ...($conCategoria ? ['categoria' => 'Padrón de proveedores'] : [])])->save();
        }
        foreach (self::ROLES as $rol => $permisos) {
            Role::where('name', $rol)->where('guard_name', 'web')->first()?->givePermissionTo($permisos);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('proveedor_cedulas');
        Schema::dropIfExists('proveedor_documentos');
        Schema::table('proveedores', fn (Blueprint $table) => $table->dropColumn(['tipo_persona', 'representante_legal', 'giro', 'regimen_fiscal', 'compra_unica', 'padron_estado', 'padron_observaciones']));
        Permission::whereIn('name', array_keys(self::PERMISOS))->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
