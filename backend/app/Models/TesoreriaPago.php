<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * Pago de Tesorería (TRF-AÑO-0001): agrupa las CLC de un mismo beneficiario y sale de una cuenta del Ayuntamiento,
 * por transferencia o cheque. Se concilia con el cargo del estado de cuenta (SPEI o cheque cobrado) si el monto
 * cuadra.
 */
class TesoreriaPago extends Model
{
    use Auditable;

    public const FORMAS = ['transferencia' => 'Transferencia', 'cheque' => 'Cheque'];

    public const ESTADOS = [
        'pagado' => 'Pagado (sin conciliar)',
        'entregado' => 'Cheque entregado',
        'conciliado' => 'Conciliado',
        'cancelado' => 'Cancelado',
    ];

    /** Cheques: emitido (pagado), entregado, cobrado (conciliado) o cancelado. */
    public const ESTADOS_CHEQUE = ['pagado' => 'Emitido', 'entregado' => 'Entregado', 'conciliado' => 'Cobrado', 'cancelado' => 'Cancelado'];

    protected $table = 'tesoreria_pagos';

    protected $fillable = [
        'folio', 'año', 'numero', 'cuenta_id', 'proveedor_id', 'beneficiario', 'forma', 'fecha', 'importe', 'cuenta_destino',
        'cheque_numero', 'estado', 'entregado_at', 'entregado_a', 'conciliado_at', 'conciliado_user_id', 'cancelado_at',
        'motivo_cancelacion', 'observaciones', 'user_id',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'importe' => 'float',
            'entregado_at' => 'datetime',
            'conciliado_at' => 'datetime',
            'cancelado_at' => 'datetime',
        ];
    }

    public function cuenta()
    {
        return $this->belongsTo(TesoreriaCuenta::class, 'cuenta_id');
    }

    public function proveedor()
    {
        return $this->belongsTo(Proveedor::class);
    }

    public function ordenes()
    {
        return $this->belongsToMany(OrdenCompra::class, 'tesoreria_pago_clc', 'tesoreria_pago_id', 'orden_compra_id')
            ->withPivot('importe', 'requisicion_estado_anterior')->withTimestamps();
    }

    public function movimientos()
    {
        return $this->hasMany(TesoreriaMovimiento::class, 'tesoreria_pago_id');
    }

    public function usuario()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function esCheque(): bool
    {
        return $this->forma === 'cheque';
    }

    public function estadoTexto(): string
    {
        return ($this->esCheque() ? self::ESTADOS_CHEQUE : self::ESTADOS)[$this->estado] ?? $this->estado;
    }

    /** Pendiente de conciliar: ya salió el pago y el banco todavía no lo refleja. */
    public function enTransito(): bool
    {
        return in_array($this->estado, ['pagado', 'entregado'], true);
    }
}
