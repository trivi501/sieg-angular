<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La CLC de un regidor (o de su asistente) lleva el nombre del regidor: se guarda en la orden al generar la CLC y se
 * llena en las que ya existen (por quien capturó la requisición o el trámite, o quien generó la CLC). Solo agrega
 * una columna y la llena.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('regidores')) {
            return;
        }
        if (! Schema::hasColumn('ordenes_compra', 'regidor_id')) {
            Schema::table('ordenes_compra', function (Blueprint $table) {
                $table->foreignId('regidor_id')->nullable()->after('clc_user_id')->constrained('regidores')->nullOnDelete();
            });
        }

        $regidorDe = fn ($userId) => $userId ? DB::table('users')->where('id', $userId)->value('regidor_id') : null;
        DB::table('ordenes_compra as o')
            ->leftJoin('tb_egreso_requisicion as r', 'r.id_tb_egreso_requisicion', '=', 'o.id_tb_egreso_requisicion')
            ->leftJoin('tramites_servicio as t', 't.id', '=', 'o.tramite_servicio_id')
            ->whereNotNull('o.clc_folio_completo')->whereNull('o.regidor_id')
            ->select('o.id', 'r.id_user_laravel', 't.user_id as tramite_user', 'o.clc_user_id')
            ->orderBy('o.id')
            ->each(function ($o) use ($regidorDe) {
                $regidor = $regidorDe($o->id_user_laravel) ?? $regidorDe($o->tramite_user) ?? $regidorDe($o->clc_user_id);
                if ($regidor) {
                    DB::table('ordenes_compra')->where('id', $o->id)->update(['regidor_id' => $regidor]);
                }
            });
    }

    public function down(): void
    {
        if (Schema::hasColumn('ordenes_compra', 'regidor_id')) {
            Schema::table('ordenes_compra', fn (Blueprint $table) => $table->dropConstrainedForeignId('regidor_id'));
        }
    }
};
