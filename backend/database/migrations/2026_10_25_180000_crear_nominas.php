<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Nómina (nuevo; SIEG no lo tenía): Recursos Humanos importa la nómina de Nomipac (o captura un finiquito o laudo),
 * Control Presupuestal la valida y se genera una CLC por unidad administrativa con su afectación presupuestal.
 * Solo agrega tablas, una columna, permisos y el rol Recursos Humanos.
 */
return new class extends Migration
{
    private const PERMISOS = [
        'nomina-index' => 'Ver nóminas, finiquitos y laudos',
        'nomina-create' => 'Importar nóminas y capturar finiquitos y laudos (Recursos Humanos)',
        'nomina-validar' => 'Validar nóminas, finiquitos y laudos y generar sus CLC (Control Presupuestal)',
        'presupuesto-capitulo-1000' => 'Usar partidas del capítulo 1000 (servicios personales) en requisiciones, trámites y modificaciones',
    ];

    private const ROLES = [
        'Super Admin' => ['nomina-index', 'nomina-create', 'nomina-validar', 'presupuesto-capitulo-1000'],
        'Admin' => ['nomina-index', 'nomina-create', 'nomina-validar', 'presupuesto-capitulo-1000'],
        'Recursos Humanos' => ['nomina-index', 'nomina-create', 'presupuesto-capitulo-1000'],
        'Control Presupuestal' => ['nomina-index', 'nomina-validar', 'presupuesto-capitulo-1000'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('nominas')) {
            Schema::create('nominas', function (Blueprint $table) {
                $table->id();
                $table->string('tipo', 20); // nomina, seguridad_social, finiquito, laudo
                $table->unsignedInteger('folio');
                $table->unsignedSmallInteger('año');
                $table->string('folio_completo', 30)->unique();
                $table->string('periodo', 150)->nullable();
                $table->date('fecha_pago')->nullable();
                $table->string('concepto', 1000);
                $table->string('beneficiario', 300);
                $table->string('beneficiario_numero', 50)->nullable(); // número de empleado (finiquito / laudo)
                $table->string('referencia', 200)->nullable(); // oficio, expediente o número de nómina de Nomipac
                $table->string('archivo_path')->nullable();
                $table->string('archivo_nombre')->nullable();
                $table->string('estado', 20)->default('captura');
                $table->text('observaciones')->nullable();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('enviado_at')->nullable();
                $table->timestamp('validado_at')->nullable();
                $table->foreignId('validado_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['tipo', 'año', 'folio']);
                $table->index('estado');
            });
        }

        if (! Schema::hasTable('nomina_renglones')) {
            Schema::create('nomina_renglones', function (Blueprint $table) {
                $table->id();
                $table->foreignId('nomina_id')->constrained('nominas')->cascadeOnDelete();
                $table->unsignedInteger('fila')->nullable(); // renglón del Excel
                $table->unsignedInteger('id_cat_egreso_unidad_administrativa');
                $table->string('unidad_clave', 50);
                $table->string('fuente', 200);
                $table->string('proyecto', 200);
                $table->string('partida', 200);
                $table->string('concepto', 300);
                $table->decimal('importe', 18, 2);
                $table->timestamps();
                $table->index('id_cat_egreso_unidad_administrativa');
            });
        }

        if (! Schema::hasColumn('ordenes_compra', 'nomina_id')) {
            Schema::table('ordenes_compra', function (Blueprint $table) {
                $table->foreignId('nomina_id')->nullable()->after('tramite_servicio_id')->constrained('nominas')->nullOnDelete();
            });
        }

        foreach (self::PERMISOS as $nombre => $descripcion) {
            Permission::findOrCreate($nombre, 'web')->forceFill([
                ...(Schema::hasColumn('permissions', 'nombre_mostrar') ? ['nombre_mostrar' => $descripcion] : []),
                ...(Schema::hasColumn('permissions', 'categoria') ? ['categoria' => 'Nómina'] : []),
            ])->save();
        }
        foreach (self::ROLES as $rol => $permisos) {
            Role::findOrCreate($rol, 'web')->givePermissionTo($permisos);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::table('ordenes_compra', fn (Blueprint $table) => $table->dropConstrainedForeignId('nomina_id'));
        Schema::dropIfExists('nomina_renglones');
        Schema::dropIfExists('nominas');
        Permission::whereIn('name', array_keys(self::PERMISOS))->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
