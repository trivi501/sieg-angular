<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Arrendamientos y servicios por contrato (CT-AÑO-0001): el área registra el contrato (proveedor, vigencia, monto
 * mensual y partida) y Recursos Materiales lo autoriza una vez. Cada mes se carga el XML de la mensualidad y se
 * generan requisición, orden de compra y CLC (un trámite tipo "contrato"), hasta la fecha de término. Solo agrega
 * tablas y permisos; no borra datos.
 */
return new class extends Migration
{
    private const PERMISOS = [
        'contratos-index' => 'Ver contratos de arrendamiento y servicios',
        'contratos-create' => 'Registrar contratos de su secretaría y cargar el XML de cada mensualidad',
        'contratos-autorizar' => 'Autorizar contratos (después, cada mensualidad genera su CLC al cargar el XML)',
    ];

    private const ROLES = [
        'Super Admin' => ['contratos-index', 'contratos-create', 'contratos-autorizar'],
        'Admin' => ['contratos-index', 'contratos-create', 'contratos-autorizar'],
        'Área solicitante' => ['contratos-index', 'contratos-create'],
        'Recursos Materiales' => ['contratos-index', 'contratos-autorizar'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('contratos')) {
            Schema::create('contratos', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('folio');
                $table->unsignedSmallInteger('año');
                $table->string('folio_completo', 30)->unique();
                $table->string('tipo', 20)->default('arrendamiento'); // arrendamiento | servicio
                $table->string('numero_contrato', 100)->nullable();
                $table->integer('id_cat_egreso_unidad_administrativa');
                $table->foreignId('proveedor_id')->constrained('proveedores');
                $table->string('concepto', 3000);
                $table->date('fecha_inicio');
                $table->date('fecha_fin');
                $table->decimal('monto_mensual', 18, 2);
                $table->decimal('monto_total', 18, 2);
                $table->string('fuente', 200);
                $table->string('proyecto', 200);
                $table->string('partida', 200);
                // captura → por_autorizar → vigente → terminado | cancelado (rechazado regresa a captura)
                $table->string('estado', 20)->default('captura');
                $table->text('observaciones')->nullable();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('enviado_at')->nullable();
                $table->timestamp('autorizado_at')->nullable();
                $table->foreignId('autorizado_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['año', 'folio']);
            });
        }

        // Una por mes facturado: su trámite (requisición, orden y CLC) y lo que se le cargó al contrato.
        if (! Schema::hasTable('contrato_mensualidades')) {
            Schema::create('contrato_mensualidades', function (Blueprint $table) {
                $table->id();
                $table->foreignId('contrato_id')->constrained('contratos')->cascadeOnDelete();
                $table->date('periodo'); // primer día del mes
                $table->foreignId('tramite_servicio_id')->nullable()->constrained('tramites_servicio')->nullOnDelete();
                $table->decimal('importe', 18, 2);
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['contrato_id', 'periodo']);
            });
        }

        if (! Schema::hasColumn('tramites_servicio', 'contrato_id')) {
            Schema::table('tramites_servicio', function (Blueprint $table) {
                $table->foreignId('contrato_id')->nullable()->after('combustible_lote_id')->constrained('contratos')->nullOnDelete();
            });
        }

        $conNombre = Schema::hasColumn('permissions', 'nombre_mostrar');
        $conCategoria = Schema::hasColumn('permissions', 'categoria');
        foreach (self::PERMISOS as $nombre => $texto) {
            $permiso = Permission::findOrCreate($nombre, 'web');
            $permiso->forceFill([...($conNombre ? ['nombre_mostrar' => $texto] : []), ...($conCategoria ? ['categoria' => 'Contratos'] : [])])->save();
        }
        foreach (self::ROLES as $rol => $permisos) {
            Role::where('name', $rol)->where('guard_name', 'web')->first()?->givePermissionTo($permisos);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::table('tramites_servicio', fn (Blueprint $table) => $table->dropConstrainedForeignId('contrato_id'));
        Schema::dropIfExists('contrato_mensualidades');
        Schema::dropIfExists('contratos');
        Permission::whereIn('name', array_keys(self::PERMISOS))->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
