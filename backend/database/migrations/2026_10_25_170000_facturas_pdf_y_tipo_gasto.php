<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PDF (representación impresa) de las facturas de órdenes de compra y de trámites, y en los fondos revolventes el
 * tipo de gasto de cada factura (viáticos con su comisión).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ordenes_compra', function (Blueprint $table) {
            $table->string('xml_pdf_path')->nullable()->after('xml_path');
        });
        Schema::table('tramite_servicio_facturas', function (Blueprint $table) {
            $table->string('pdf_path')->nullable()->after('xml_path');
            $table->string('tipo_gasto', 30)->nullable()->after('concepto');
            $table->text('comision')->nullable()->after('tipo_gasto');
        });
    }

    public function down(): void
    {
        Schema::table('ordenes_compra', fn (Blueprint $table) => $table->dropColumn('xml_pdf_path'));
        Schema::table('tramite_servicio_facturas', fn (Blueprint $table) => $table->dropColumn(['pdf_path', 'tipo_gasto', 'comision']));
    }
};
