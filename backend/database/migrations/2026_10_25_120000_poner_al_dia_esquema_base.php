<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pone al día una base de SIEG anterior a esta versión: agrega las tablas y columnas que el código usa y que no
 * crea ninguna otra migración de este repo (venían del SIEG original). Solo agrega lo que falte; en una base que
 * ya las tiene no hace nada, y no toca datos.
 *
 *  - ordenes_compra: ISR retenido del XML, cancelación de la orden y de su CLC, asignación al almacén.
 *  - proveedores.vigencia y vehiculos.departamento_id.
 *  - almacén (entradas y salidas), calendarios de captura y de modificaciones, refacturas, mantenimiento vehicular.
 *    Con los calendarios vacíos rigen los periodos por omisión del ejercicio.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->columnas('ordenes_compra', [
            'xml_uuid_cancelado' => fn (Blueprint $t) => $t->string('xml_uuid_cancelado', 60)->nullable(),
            'xml_isr' => fn (Blueprint $t) => $t->decimal('xml_isr', 18, 2)->nullable(),
            'clc_cancelada_at' => fn (Blueprint $t) => $t->timestamp('clc_cancelada_at')->nullable(),
            'clc_cancelada_user_id' => fn (Blueprint $t) => $t->foreignId('clc_cancelada_user_id')->nullable()->constrained('users')->nullOnDelete(),
            'clc_motivo_cancelacion' => fn (Blueprint $t) => $t->string('clc_motivo_cancelacion', 1000)->nullable(),
            'cancelada_at' => fn (Blueprint $t) => $t->timestamp('cancelada_at')->nullable(),
            'cancelada_user_id' => fn (Blueprint $t) => $t->foreignId('cancelada_user_id')->nullable()->constrained('users')->nullOnDelete(),
            'motivo_cancelacion' => fn (Blueprint $t) => $t->string('motivo_cancelacion', 1000)->nullable(),
            'almacen_asignada_at' => fn (Blueprint $t) => $t->timestamp('almacen_asignada_at')->nullable(),
            'almacen_asignada_user_id' => fn (Blueprint $t) => $t->foreignId('almacen_asignada_user_id')->nullable()->constrained('users')->nullOnDelete(),
        ]);
        $this->columnas('proveedores', [
            'vigencia' => fn (Blueprint $t) => $t->date('vigencia')->nullable(),
        ]);
        $this->columnas('vehiculos', [
            'departamento_id' => fn (Blueprint $t) => $t->foreignId('departamento_id')->nullable()->constrained('departamentos')->nullOnDelete(),
        ]);

        foreach (['calendario_captura', 'calendario_modificaciones'] as $tabla) {
            $this->tabla($tabla, function (Blueprint $t) {
                $t->id();
                $t->unsignedSmallInteger('año');
                $t->unsignedTinyInteger('mes');
                $t->date('fecha_inicio');
                $t->date('fecha_fin');
                $t->boolean('activo')->default(true);
                $t->timestamps();
                $t->unique(['año', 'mes']);
            });
        }

        $this->tabla('ordenes_compra_refacturas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('orden_compra_id')->constrained('ordenes_compra')->cascadeOnDelete();
            $t->string('xml_uuid_anterior', 60)->nullable();
            $t->string('xml_folio_anterior', 60)->nullable();
            $t->decimal('xml_total_anterior', 18, 2)->nullable();
            $t->string('xml_path_anterior', 500)->nullable();
            $t->string('clc_folio_anterior', 30)->nullable();
            $t->string('clc_estado_anterior', 30)->nullable();
            $t->string('clc_nueva', 30)->nullable();
            $t->string('motivo', 1000)->nullable();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        $this->tabla('almacen_entradas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('orden_compra_id')->constrained('ordenes_compra')->cascadeOnDelete();
            $t->unsignedInteger('folio');
            $t->unsignedSmallInteger('año');
            $t->string('folio_completo', 30)->unique();
            $t->date('fecha');
            $t->text('observaciones')->nullable();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
        $this->tabla('almacen_entrada_detalles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('almacen_entrada_id')->constrained('almacen_entradas')->cascadeOnDelete();
            $t->integer('id_tb_egreso_requisicion_detalle')->nullable()->index();
            $t->string('descripcion', 3000)->nullable();
            $t->decimal('cantidad', 14, 3);
            $t->decimal('precio_unitario', 18, 4)->nullable();
            $t->timestamps();
        });
        $this->tabla('almacen_salidas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('orden_compra_id')->constrained('ordenes_compra')->cascadeOnDelete();
            $t->unsignedInteger('folio');
            $t->unsignedSmallInteger('año');
            $t->string('folio_completo', 30)->unique();
            $t->date('fecha');
            $t->integer('id_cat_egreso_unidad_administrativa')->nullable();
            $t->string('recibe', 300)->nullable();
            $t->text('observaciones')->nullable();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
        $this->tabla('almacen_salida_detalles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('almacen_salida_id')->constrained('almacen_salidas')->cascadeOnDelete();
            $t->integer('id_tb_egreso_requisicion_detalle')->nullable()->index();
            $t->string('descripcion', 3000)->nullable();
            $t->decimal('cantidad', 14, 3);
            $t->timestamps();
        });

        $this->tabla('vehiculo_mantenimientos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('vehiculo_id')->constrained('vehiculos')->cascadeOnDelete();
            $t->unsignedInteger('folio');
            $t->unsignedSmallInteger('año');
            $t->string('folio_completo', 30)->unique();
            $t->integer('id_cat_egreso_unidad_administrativa')->nullable();
            $t->foreignId('departamento_id')->nullable()->constrained('departamentos')->nullOnDelete();
            $t->date('fecha_recepcion');
            $t->unsignedInteger('kilometraje')->nullable();
            $t->string('tipo', 20);
            $t->text('descripcion')->nullable();
            $t->string('entrega', 300)->nullable();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('estado', 20)->default('en_taller');
            $t->date('fecha_entrega')->nullable();
            $t->string('recibe_entrega', 300)->nullable();
            $t->text('observaciones_entrega')->nullable();
            $t->integer('id_tb_egreso_requisicion')->nullable()->index();
            $t->timestamps();
        });
    }

    /** Sin down(): son tablas y columnas del SIEG original; quitarlas rompería una base que ya las traía. */
    public function down(): void {}

    /** @param  array<string, callable(Blueprint): mixed>  $columnas  Solo agrega las que no existan. */
    private function columnas(string $tabla, array $columnas): void
    {
        $faltan = array_filter($columnas, fn ($c) => ! Schema::hasColumn($tabla, $c), ARRAY_FILTER_USE_KEY);
        if ($faltan) {
            Schema::table($tabla, function (Blueprint $t) use ($faltan) {
                foreach ($faltan as $definir) {
                    $definir($t);
                }
            });
        }
    }

    private function tabla(string $tabla, callable $definir): void
    {
        if (! Schema::hasTable($tabla)) {
            Schema::create($tabla, $definir);
        }
    }
};
