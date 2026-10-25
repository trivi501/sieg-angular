<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El número de transferencia (TRF-AÑO-0001) ahora agrupa las CLC de un mismo proveedor en un pago de Tesorería, así
 * que deja de ser único por CLC: se quitan los índices únicos de ordenes_compra (de cuando era un TRF por CLC) y se
 * deja un índice normal para buscar por número. No toca datos.
 */
return new class extends Migration
{
    public function up(): void
    {
        $indices = collect(Schema::getIndexes('ordenes_compra'))->keyBy('name');
        Schema::table('ordenes_compra', function (Blueprint $table) use ($indices) {
            foreach (['ordenes_compra_transferencia_numero_unique', 'ordenes_compra_transferencia_año_transferencia_folio_unique'] as $nombre) {
                if ($indices->has($nombre)) {
                    $table->dropUnique($nombre);
                }
            }
            if (! $indices->has('ordenes_compra_transferencia_numero_index')) {
                $table->index('transferencia_numero');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ordenes_compra', function (Blueprint $table) {
            $table->dropIndex(['transferencia_numero']);
            $table->unique('transferencia_numero');
            $table->unique(['transferencia_año', 'transferencia_folio']);
        });
    }
};
