<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Requisiciones sin factura (apoyos a un beneficiario): solo agrega columnas, no borra datos.
 *
 * - tb_egreso_requisicion: sin_factura y la fecha del documento de referencia. El nombre del beneficiario
 *   y el folio de referencia usan las columnas que ya existían (beneficiario, beneficiario_referencia).
 * - ordenes_compra: la CLC de un apoyo se guarda en un registro marcado sin_factura (folio AP-AÑO-0001),
 *   sin proveedor; por eso proveedor_id pasa a admitir nulo (las órdenes normales lo siguen teniendo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_egreso_requisicion', function (Blueprint $table) {
            if (! Schema::hasColumn('tb_egreso_requisicion', 'sin_factura')) {
                $table->boolean('sin_factura')->default(false);
            }
            if (! Schema::hasColumn('tb_egreso_requisicion', 'beneficiario_fecha')) {
                $table->date('beneficiario_fecha')->nullable();
            }
        });

        Schema::table('ordenes_compra', function (Blueprint $table) {
            if (! Schema::hasColumn('ordenes_compra', 'sin_factura')) {
                $table->boolean('sin_factura')->default(false)->after('proveedor_id');
            }
            $table->unsignedBigInteger('proveedor_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Sin pérdida de datos: solo se quitan las columnas nuevas. proveedor_id se deja admitiendo nulo,
        // porque puede haber apoyos ya registrados sin proveedor.
        Schema::table('ordenes_compra', fn (Blueprint $table) => $table->dropColumn('sin_factura'));
        Schema::table('tb_egreso_requisicion', fn (Blueprint $table) => $table->dropColumn(['sin_factura', 'beneficiario_fecha']));
    }
};
