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
        'tramite_servicio_id', 'xml_path', 'pdf_path', 'uuid', 'rfc_emisor', 'nombre_emisor', 'rfc_receptor', 'serie', 'folio', 'fecha',
        'subtotal', 'descuento', 'iva', 'ieps', 'isr', 'total', 'conceptos', 'sat_estado', 'sat_verificado_at',
        'fuente', 'proyecto', 'partida', 'concepto', 'tipo_gasto', 'comision',
    ];

    protected $hidden = ['xml_path', 'pdf_path'];

    protected function casts(): array
    {
        return [
            'fecha' => 'datetime',
            'subtotal' => 'float',
            'descuento' => 'float',
            'iva' => 'float',
            'ieps' => 'float',
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

    public function clasificada(bool $porArticulo = false): bool
    {
        if ($porArticulo) {
            // Fondo revolvente: tipo de gasto (viáticos, con su comisión) y cada artículo con su partida, proyecto y fuente.
            $articulos = $this->conceptos ?? [];

            return filled($this->concepto) && filled($this->tipo_gasto) && ($this->tipo_gasto !== 'viaticos' || filled($this->comision)) && $articulos !== []
                && collect($articulos)->every(fn ($a) => filled($a['partida'] ?? null) && filled($a['proyecto'] ?? null) && filled($a['fuente'] ?? null));
        }

        return filled($this->partida) && filled($this->proyecto) && filled($this->fuente) && filled($this->concepto);
    }

    /**
     * Fondo revolvente: los montos de la factura por partida / proyecto / fuente, sumando sus artículos. Una
     * factura con dos partidas da dos renglones, cada uno solo con lo de sus artículos.
     *
     * @return array<int, array{fuente: string, proyecto: string, partida: string, subtotal: float, descuento: float, base: float, iva: float, ieps: float, isr: float, importe: float}>
     */
    public function porPartida(): array
    {
        $grupos = [];
        foreach ($this->conceptos ?? [] as $a) {
            $clave = ($a['fuente'] ?? '').'|'.($a['proyecto'] ?? '').'|'.($a['partida'] ?? '');
            $base = round((float) ($a['importe'] ?? 0) - (float) ($a['descuento'] ?? 0), 2);
            $grupos[$clave] ??= ['fuente' => $a['fuente'] ?? '', 'proyecto' => $a['proyecto'] ?? '', 'partida' => $a['partida'] ?? '', 'subtotal' => 0.0, 'descuento' => 0.0, 'base' => 0.0, 'iva' => 0.0, 'ieps' => 0.0, 'isr' => 0.0];
            $grupos[$clave]['subtotal'] += (float) ($a['importe'] ?? 0);
            $grupos[$clave]['descuento'] += (float) ($a['descuento'] ?? 0);
            $grupos[$clave]['base'] += $base;
            $grupos[$clave]['iva'] += (float) ($a['iva'] ?? 0);
            $grupos[$clave]['ieps'] += (float) ($a['ieps'] ?? 0);
            $grupos[$clave]['isr'] += (float) ($a['isr'] ?? 0);
        }

        // Importe = subtotal − descuento + IVA + IEPS (antes de retener el ISR).
        return array_values(array_map(fn ($g) => [...array_map(fn ($v) => is_float($v) ? round($v, 2) : $v, $g),
            'importe' => round($g['base'] + $g['iva'] + $g['ieps'], 2)], $grupos));
    }
}
