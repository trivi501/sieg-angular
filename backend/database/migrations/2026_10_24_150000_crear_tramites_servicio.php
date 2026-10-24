<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Trámites de servicios: primero se suben las facturas (XML) y, al validarlas Recursos Materiales, se generan
 * la requisición, la orden de compra y la CLC. Solo agrega tablas, una columna y permisos; no borra datos.
 */
return new class extends Migration
{
    private const PERMISOS = [
        'servicios-index' => 'Ver trámites de servicios',
        'servicios-create' => 'Capturar trámites de servicios (subir facturas)',
        'servicios-validar' => 'Validar trámites de servicios (genera requisición, orden de compra y CLC)',
    ];

    private const ROLES = [
        'Super Admin' => ['servicios-index', 'servicios-create', 'servicios-validar'],
        'Admin' => ['servicios-index', 'servicios-create', 'servicios-validar'],
        'Área solicitante' => ['servicios-index', 'servicios-create'],
        'Recursos Materiales' => ['servicios-index', 'servicios-validar'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('tramites_servicio')) {
            Schema::create('tramites_servicio', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('folio');
                $table->unsignedSmallInteger('año');
                $table->string('folio_completo', 30)->unique();
                $table->integer('id_cat_egreso_unidad_administrativa');
                $table->string('concepto', 3000);
                $table->string('proveedor_rfc', 15)->nullable();
                $table->string('proveedor_nombre', 300)->nullable();
                // captura → por_validar → validado | rechazado (vuelve a captura) | cancelado
                $table->string('estado', 20)->default('captura');
                $table->text('observaciones')->nullable();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('enviado_at')->nullable();
                $table->timestamp('validado_at')->nullable();
                $table->foreignId('validado_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->integer('id_tb_egreso_requisicion')->nullable();
                $table->timestamps();
                $table->unique(['año', 'folio']);
            });
        }

        if (! Schema::hasTable('tramite_servicio_facturas')) {
            Schema::create('tramite_servicio_facturas', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tramite_servicio_id')->constrained('tramites_servicio')->cascadeOnDelete();
                $table->string('xml_path', 500);
                $table->string('uuid', 60)->unique();
                $table->string('rfc_emisor', 15);
                $table->string('nombre_emisor', 300)->nullable();
                $table->string('rfc_receptor', 15)->nullable();
                $table->string('serie', 25)->nullable();
                $table->string('folio', 40)->nullable();
                $table->dateTime('fecha')->nullable();
                $table->decimal('subtotal', 18, 2)->default(0);
                $table->decimal('descuento', 18, 2)->default(0);
                $table->decimal('iva', 18, 2)->default(0);
                $table->decimal('isr', 18, 2)->default(0);
                $table->decimal('total', 18, 2);
                $table->json('conceptos')->nullable();
                $table->string('sat_estado', 20)->nullable();
                $table->timestamp('sat_verificado_at')->nullable();
                // Clasificación presupuestal y concepto de esta factura (un renglón de la requisición y de la CLC).
                $table->string('fuente', 200)->nullable();
                $table->string('proyecto', 200)->nullable();
                $table->string('partida', 200)->nullable();
                $table->string('concepto', 3000)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasColumn('ordenes_compra', 'tramite_servicio_id')) {
            Schema::table('ordenes_compra', function (Blueprint $table) {
                $table->unsignedBigInteger('tramite_servicio_id')->nullable()->after('sin_factura');
            });
        }

        // Permisos y su asignación a los roles que existan (no quita ninguno).
        $conNombre = Schema::hasColumn('permissions', 'nombre_mostrar');
        $conCategoria = Schema::hasColumn('permissions', 'categoria');
        foreach (self::PERMISOS as $nombre => $texto) {
            $permiso = Permission::findOrCreate($nombre, 'web');
            $permiso->forceFill([...($conNombre ? ['nombre_mostrar' => $texto] : []), ...($conCategoria ? ['categoria' => 'Trámites de servicios'] : [])])->save();
        }
        foreach (self::ROLES as $rol => $permisos) {
            Role::where('name', $rol)->where('guard_name', 'web')->first()?->givePermissionTo($permisos);
        }
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::table('ordenes_compra', fn (Blueprint $table) => $table->dropColumn('tramite_servicio_id'));
        Schema::dropIfExists('tramite_servicio_facturas');
        Schema::dropIfExists('tramites_servicio');
        Permission::whereIn('name', array_keys(self::PERMISOS))->delete();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
