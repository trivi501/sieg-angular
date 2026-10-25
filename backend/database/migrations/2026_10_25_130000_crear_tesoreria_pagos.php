<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Tesorería (módulo 10): mesa de control de pagos de Egresos, pago a proveedores y conciliación.
 *  - tesoreria_cuentas: cuentas bancarias del Ayuntamiento (banco y fuente de financiamiento, saldo inicial).
 *  - tesoreria_pagos: un pago (TRF-AÑO-0001) agrupa las CLC de un mismo beneficiario; transferencia o cheque.
 *  - tesoreria_movimientos: movimientos del estado de cuenta (captura o plantilla); un cargo se concilia con su pago.
 * Solo agrega tablas, una columna y permisos; no borra datos.
 */
return new class extends Migration
{
    private const PERMISOS = [
        'tesoreria-index' => 'Ver la mesa de control de pagos y los reportes de Tesorería',
        'tesoreria-pagar' => 'Generar pagos (agrupar CLC por proveedor), registrar y cancelar cheques',
        'tesoreria-conciliar' => 'Capturar o importar movimientos bancarios y conciliar los pagos',
    ];

    /** Catálogo de cuentas bancarias del Ayuntamiento (Catalogos: tesoreria-cuentas). */
    private const PERMISOS_CATALOGO = [
        'index' => 'Ver', 'create' => 'Crear', 'edit' => 'Editar', 'delete' => 'Dar de baja', 'import' => 'Importar',
    ];

    private const ROLES = [
        'Super Admin' => ['tesoreria-index', 'tesoreria-pagar', 'tesoreria-conciliar'],
        'Admin' => ['tesoreria-index', 'tesoreria-pagar', 'tesoreria-conciliar'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('tesoreria_cuentas')) {
            Schema::create('tesoreria_cuentas', function (Blueprint $table) {
                $table->id();
                $table->string('nombre', 200);
                $table->integer('id_cat_banco')->nullable();
                $table->string('numero', 30)->nullable();
                $table->string('clabe', 18)->nullable()->unique();
                // Clave de la fuente de financiamiento (como en el presupuesto, p. ej. 111).
                $table->string('fuente', 20)->nullable();
                $table->decimal('saldo_inicial', 18, 2)->default(0);
                $table->date('fecha_saldo_inicial')->nullable();
                $table->boolean('activo')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('tesoreria_pagos')) {
            Schema::create('tesoreria_pagos', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('folio');
                $table->unsignedSmallInteger('año');
                $table->string('numero', 30)->unique(); // TRF-2026-0001
                $table->foreignId('cuenta_id')->constrained('tesoreria_cuentas');
                $table->foreignId('proveedor_id')->nullable()->constrained('proveedores')->nullOnDelete();
                $table->string('beneficiario', 300);
                $table->string('forma', 20); // transferencia | cheque
                $table->date('fecha');
                $table->decimal('importe', 18, 2);
                $table->string('cuenta_destino', 30)->nullable(); // CLABE del proveedor
                $table->string('cheque_numero', 30)->nullable();
                // pagado → conciliado | cancelado. Cheque: además entregado (cobrado = conciliado).
                $table->string('estado', 20)->default('pagado');
                $table->timestamp('entregado_at')->nullable();
                $table->string('entregado_a', 300)->nullable();
                $table->timestamp('conciliado_at')->nullable();
                $table->foreignId('conciliado_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('cancelado_at')->nullable();
                $table->string('motivo_cancelacion', 1000)->nullable();
                $table->text('observaciones')->nullable();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['año', 'folio']);
            });
        }

        // CLC del pago, con el importe neto que se le pagó y el estado en que estaba la requisición (para cancelar).
        if (! Schema::hasTable('tesoreria_pago_clc')) {
            Schema::create('tesoreria_pago_clc', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tesoreria_pago_id')->constrained('tesoreria_pagos')->cascadeOnDelete();
                $table->foreignId('orden_compra_id')->constrained('ordenes_compra');
                $table->decimal('importe', 18, 2);
                $table->integer('requisicion_estado_anterior')->nullable();
                $table->timestamps();
                $table->unique(['tesoreria_pago_id', 'orden_compra_id']);
            });
        }

        if (! Schema::hasTable('tesoreria_movimientos')) {
            Schema::create('tesoreria_movimientos', function (Blueprint $table) {
                $table->id();
                $table->foreignId('cuenta_id')->constrained('tesoreria_cuentas');
                $table->date('fecha');
                $table->string('tipo', 10); // cargo | abono
                $table->decimal('importe', 18, 2);
                $table->string('referencia', 100)->nullable();
                $table->string('clave_rastreo', 60)->nullable();
                $table->string('concepto', 500)->nullable();
                // Un cargo conciliado con su pago (SPEI o cheque cobrado).
                $table->foreignId('tesoreria_pago_id')->nullable()->constrained('tesoreria_pagos')->nullOnDelete();
                $table->string('origen', 20)->default('captura'); // captura | plantilla
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['cuenta_id', 'fecha']);
            });
        }

        if (! Schema::hasColumn('ordenes_compra', 'tesoreria_pago_id')) {
            Schema::table('ordenes_compra', function (Blueprint $table) {
                $table->foreignId('tesoreria_pago_id')->nullable()->after('transferencia_numero')->constrained('tesoreria_pagos')->nullOnDelete();
            });
        }

        $conNombre = Schema::hasColumn('permissions', 'nombre_mostrar');
        $conCategoria = Schema::hasColumn('permissions', 'categoria');
        foreach (self::PERMISOS as $nombre => $texto) {
            $permiso = Permission::findOrCreate($nombre, 'web');
            $permiso->forceFill([...($conNombre ? ['nombre_mostrar' => $texto] : []), ...($conCategoria ? ['categoria' => 'Tesorería'] : [])])->save();
        }
        $catalogo = [];
        foreach (self::PERMISOS_CATALOGO as $accion => $verbo) {
            $permiso = Permission::findOrCreate("catalogos-tesoreria-cuentas-{$accion}", 'web');
            $permiso->forceFill([...($conNombre ? ['nombre_mostrar' => "{$verbo}: Cuentas bancarias del Ayuntamiento"] : []), ...($conCategoria ? ['categoria' => 'Catálogos'] : [])])->save();
            $catalogo[] = $permiso->name;
        }
        foreach (self::ROLES as $rol => $permisos) {
            Role::where('name', $rol)->where('guard_name', 'web')->first()?->givePermissionTo([...$permisos, ...$catalogo]);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::table('ordenes_compra', fn (Blueprint $table) => $table->dropConstrainedForeignId('tesoreria_pago_id'));
        Schema::dropIfExists('tesoreria_movimientos');
        Schema::dropIfExists('tesoreria_pago_clc');
        Schema::dropIfExists('tesoreria_pagos');
        Schema::dropIfExists('tesoreria_cuentas');
        Permission::whereIn('name', [...array_keys(self::PERMISOS), ...array_map(fn ($a) => "catalogos-tesoreria-cuentas-{$a}", array_keys(self::PERMISOS_CATALOGO))])->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
