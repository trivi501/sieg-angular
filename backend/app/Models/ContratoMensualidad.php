<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Un mes facturado de un contrato: su trámite (requisición, orden de compra y CLC) y el importe que se cargó. */
class ContratoMensualidad extends Model
{
    protected $table = 'contrato_mensualidades';

    protected $fillable = ['contrato_id', 'periodo', 'tramite_servicio_id', 'importe', 'user_id'];

    protected function casts(): array
    {
        return ['periodo' => 'date', 'importe' => 'float'];
    }

    public function contrato()
    {
        return $this->belongsTo(Contrato::class);
    }

    public function tramite()
    {
        return $this->belongsTo(TramiteServicio::class, 'tramite_servicio_id');
    }

    /** Su CLC se canceló: el mes vuelve a estar pendiente y no cuenta como ejercido. */
    public function cancelada(): bool
    {
        return $this->tramite?->ordenCompra?->clc_estado === 'cancelada' || $this->tramite?->estado === 'cancelado';
    }

    /** generada (CLC en trámite) | pagada | cancelada */
    public function estado(): string
    {
        return match (true) {
            $this->cancelada() => 'cancelada',
            $this->tramite?->ordenCompra?->clc_estado === 'pagada' => 'pagada',
            default => 'generada',
        };
    }
}
