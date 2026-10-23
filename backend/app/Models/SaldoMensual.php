<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Saldo corrido de una línea del presupuesto a un mes (lo registra una CorridaSaldo, que es lo auditado). */
class SaldoMensual extends Model
{
    protected $table = 'saldos_mensuales';

    protected $fillable = [
        'corrida_saldo_id', 'ejercicio', 'mes', 'clave', 'saldo_anterior',
        'asignado_mes_anterior', 'modificado_mes_anterior', 'comprometido_mes_anterior',
    ];

    protected function casts(): array
    {
        return [
            'saldo_anterior' => 'float',
            'asignado_mes_anterior' => 'float',
            'modificado_mes_anterior' => 'float',
            'comprometido_mes_anterior' => 'float',
        ];
    }

    public function corrida()
    {
        return $this->belongsTo(CorridaSaldo::class, 'corrida_saldo_id');
    }
}
