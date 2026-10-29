<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Las facturas de un trámite cancelado se pueden volver a usar: la unicidad pasa de uuid a uuid_activo, que es el
 * UUID mientras el trámite está vigente y queda vacío al cancelarlo (la factura se conserva como historial). No
 * borra datos: agrega la columna, la llena y cambia el índice único.
 */
return new class extends Migration
{
    private const TABLA = 'tramite_servicio_facturas';

    public function up(): void
    {
        if (! Schema::hasColumn(self::TABLA, 'uuid_activo')) {
            Schema::table(self::TABLA, function (Blueprint $table) {
                $table->string('uuid_activo', 60)->nullable()->after('uuid');
            });
        }

        // Vigentes: todas menos las de trámites ya cancelados (esas quedan libres desde ahora).
        DB::table(self::TABLA)->whereNull('uuid_activo')
            ->whereNotIn('tramite_servicio_id', DB::table('tramites_servicio')->where('estado', 'cancelado')->select('id'))
            ->update(['uuid_activo' => DB::raw('uuid')]);

        $indices = collect(Schema::getIndexes(self::TABLA))->keyBy('name');
        Schema::table(self::TABLA, function (Blueprint $table) use ($indices) {
            if (! $indices->has('tramite_servicio_facturas_uuid_activo_unique')) {
                $table->unique('uuid_activo');
            }
            if ($indices->has('tramite_servicio_facturas_uuid_unique')) {
                $table->dropUnique('tramite_servicio_facturas_uuid_unique');
            }
            if (! $indices->has('tramite_servicio_facturas_uuid_index')) {
                $table->index('uuid');
            }
        });
    }

    public function down(): void
    {
        // No se regresa el índice único de uuid: ya puede haber una factura repetida (cancelada y vuelta a usar).
        Schema::table(self::TABLA, function (Blueprint $table) {
            $table->dropUnique('tramite_servicio_facturas_uuid_activo_unique');
            $table->dropColumn('uuid_activo');
        });
    }
};
