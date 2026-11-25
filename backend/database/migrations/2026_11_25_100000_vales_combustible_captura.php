<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Combustible, método nuevo: la unidad de combustibles sube los XML por área y captura los vales de la bitácora a
 * mano, en cualquier momento y aun incompletos. Los vales ya no vienen siempre del Excel del proveedor, así que estas
 * columnas pueden quedar vacías. Solo cambia columnas a opcionales; no toca datos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('combustible_cargas', function (Blueprint $table) {
            $table->unsignedBigInteger('combustible_lote_id')->nullable()->change();
            $table->unsignedInteger('fila')->nullable()->change();
            $table->decimal('litros', 12, 3)->nullable()->change();
            $table->decimal('importe', 18, 2)->nullable()->change();
            $table->string('factura_folio', 40)->nullable()->change();
        });
    }

    public function down(): void
    {
        // No se regresan a obligatorias: ya puede haber vales capturados a mano sin esos datos.
    }
};
