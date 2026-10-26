<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * Trámite de pago de personal: nómina o seguridad social (IMSS / ISSSTEZAC) importadas del Excel de Nomipac, o un
 * finiquito o laudo capturado por Recursos Humanos. Al validarlo Control Presupuestal se genera, por cada unidad
 * administrativa, la requisición (comprometida), el registro sin factura y la CLC con su afectación presupuestal.
 */
class Nomina extends Model
{
    use Auditable;

    public const TIPOS = [
        'nomina' => ['prefijo' => 'NOM', 'nombre' => 'Nómina', 'beneficiario' => 'CUENTA PAGADORA DE NÓMINA'],
        'seguridad_social' => ['prefijo' => 'SS', 'nombre' => 'Seguridad social (IMSS / ISSSTEZAC)', 'beneficiario' => 'INSTITUTO MEXICANO DEL SEGURO SOCIAL'],
        'finiquito' => ['prefijo' => 'FIN', 'nombre' => 'Finiquito', 'beneficiario' => null],
        'laudo' => ['prefijo' => 'LAU', 'nombre' => 'Laudo', 'beneficiario' => null],
    ];

    /** Finiquito y laudo: a favor de una persona, con su oficio o expediente y sin factura. */
    public const INDIVIDUALES = ['finiquito', 'laudo'];

    public const ESTADOS = [
        'captura' => 'En captura',
        'por_validar' => 'Por validar',
        'rechazado' => 'No validado',
        'validado' => 'Validado (CLC generadas)',
        'cancelado' => 'Cancelado',
    ];

    public const EDITABLES = ['captura', 'rechazado'];

    protected $table = 'nominas';

    protected $fillable = [
        'tipo', 'folio', 'año', 'folio_completo', 'periodo', 'fecha_pago', 'concepto', 'beneficiario', 'beneficiario_numero',
        'referencia', 'archivo_path', 'archivo_nombre', 'estado', 'observaciones', 'user_id', 'enviado_at', 'validado_at', 'validado_user_id',
    ];

    protected $hidden = ['archivo_path'];

    protected function casts(): array
    {
        return ['fecha_pago' => 'date:Y-m-d', 'enviado_at' => 'datetime', 'validado_at' => 'datetime'];
    }

    public function renglones()
    {
        return $this->hasMany(NominaRenglon::class)->orderBy('id');
    }

    /** Una orden sin factura (con su CLC) por unidad administrativa. */
    public function ordenes()
    {
        return $this->hasMany(OrdenCompra::class)->orderBy('id');
    }

    public function usuario()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function validador()
    {
        return $this->belongsTo(User::class, 'validado_user_id');
    }

    public function nombreTipo(): string
    {
        return self::TIPOS[$this->tipo]['nombre'] ?? 'Nómina';
    }

    public function esIndividual(): bool
    {
        return in_array($this->tipo, self::INDIVIDUALES, true);
    }

    public function editable(): bool
    {
        return in_array($this->estado, self::EDITABLES, true);
    }

    /** A favor de quién van las CLC: la cuenta de nómina, la institución o la persona (con su número de empleado). */
    public function aFavorDe(): string
    {
        return mb_strtoupper(trim($this->beneficiario)).($this->beneficiario_numero ? ' (NO. EMPLEADO '.trim($this->beneficiario_numero).')' : '');
    }

    public function total(): float
    {
        return round((float) $this->renglones()->sum('importe'), 2);
    }
}
