<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/** Una factura (CFDI) de un trámite de servicios: su contenido, su estado en el SAT y su clasificación presupuestal. */
class TramiteServicioFactura extends Model
{
    use Auditable;

    protected $table = 'tramite_servicio_facturas';

    protected $fillable = [
        'tramite_servicio_id', 'xml_path', 'uuid', 'rfc_emisor', 'nombre_emisor', 'rfc_receptor', 'serie', 'folio', 'fecha',
        'subtotal', 'descuento', 'iva', 'isr', 'total', 'conceptos', 'sat_estado', 'sat_verificado_at',
        'fuente', 'proyecto', 'partida', 'concepto',
    ];

    protected $hidden = ['xml_path'];

    protected function casts(): array
    {
        return [
            'fecha' => 'datetime',
            'subtotal' => 'float',
            'descuento' => 'float',
            'iva' => 'float',
            'isr' => 'float',
            'total' => 'float',
            'conceptos' => 'array',
            'sat_verificado_at' => 'datetime',
        ];
    }

    public function tramite()
    {
        return $this->belongsTo(TramiteServicio::class, 'tramite_servicio_id');
    }

    /**
     * Importe del gasto: lo que cuesta antes de retener el ISR (Total del CFDI + ISR retenido). Es lo que se
     * compromete en la requisición; al proveedor se le paga el Total (importe − ISR), igual que en las órdenes.
     */
    public function importe(): float
    {
        return round($this->total + $this->isr, 2);
    }

    /** Base antes de IVA (SubTotal − Descuento). */
    public function base(): float
    {
        return round($this->subtotal - $this->descuento, 2);
    }

    public function serieFolio(): ?string
    {
        return trim(($this->serie ?? '').' '.($this->folio ?? '')) ?: null;
    }

    public function clasificada(): bool
    {
        return filled($this->partida) && filled($this->proyecto) && filled($this->fuente) && filled($this->concepto);
    }
}
