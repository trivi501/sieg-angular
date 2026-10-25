<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Contrato de arrendamiento o servicio (CT-AÑO-0001) con un proveedor del padrón: vigencia, monto mensual y partida.
 * Autorizado una vez, cada mes se carga el XML de la mensualidad y se generan requisición, orden de compra y CLC.
 */
class Contrato extends Model
{
    use Auditable;

    public const TIPOS = ['arrendamiento' => 'Arrendamiento', 'servicio' => 'Servicio'];

    public const ESTADOS = [
        'captura' => 'En captura',
        'por_autorizar' => 'Por autorizar',
        'rechazado' => 'No autorizado',
        'vigente' => 'Vigente',
        'terminado' => 'Terminado',
        'cancelado' => 'Cancelado',
    ];

    public const EDITABLES = ['captura', 'rechazado'];

    /** Diferencia tolerada entre la factura del mes y el monto mensual sin avisar. */
    public const TOLERANCIA = 1.00;

    protected $table = 'contratos';

    protected $fillable = [
        'folio', 'año', 'folio_completo', 'tipo', 'numero_contrato', 'id_cat_egreso_unidad_administrativa', 'proveedor_id', 'concepto',
        'fecha_inicio', 'fecha_fin', 'monto_mensual', 'monto_total', 'fuente', 'proyecto', 'partida', 'estado', 'observaciones',
        'user_id', 'enviado_at', 'autorizado_at', 'autorizado_user_id',
    ];

    protected function casts(): array
    {
        return [
            'fecha_inicio' => 'date',
            'fecha_fin' => 'date',
            'monto_mensual' => 'float',
            'monto_total' => 'float',
            'enviado_at' => 'datetime',
            'autorizado_at' => 'datetime',
        ];
    }

    public function proveedor()
    {
        return $this->belongsTo(Proveedor::class);
    }

    public function unidadAdministrativa()
    {
        return $this->belongsTo(EgresoUnidadAdministrativa::class, 'id_cat_egreso_unidad_administrativa', 'id_cat_egreso_unidad_administrativa');
    }

    public function mensualidades()
    {
        return $this->hasMany(ContratoMensualidad::class)->orderBy('periodo')->orderBy('id');
    }

    public function usuario()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function autorizador()
    {
        return $this->belongsTo(User::class, 'autorizado_user_id');
    }

    public function editable(): bool
    {
        return in_array($this->estado, self::EDITABLES, true);
    }

    /**
     * Los meses de la vigencia (primer día de cada mes), de inicio a término.
     *
     * @return Collection<int, CarbonImmutable>
     */
    public function meses(): Collection
    {
        $meses = collect();
        $mes = CarbonImmutable::parse($this->fecha_inicio)->startOfMonth();
        $fin = CarbonImmutable::parse($this->fecha_fin)->startOfMonth();
        while ($mes <= $fin && $meses->count() < 600) {
            $meses->push($mes);
            $mes = $mes->addMonth();
        }

        return $meses;
    }

    /** Lo ya cargado al contrato: las mensualidades cuya CLC no está cancelada. */
    public function ejercido(): float
    {
        return round($this->mensualidades()->with('tramite.ordenCompra')->get()->reject(fn ($m) => $m->cancelada())->sum('importe'), 2);
    }

    public function saldo(): float
    {
        return round($this->monto_total - $this->ejercido(), 2);
    }
}
