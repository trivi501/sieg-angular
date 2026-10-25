<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

class Proveedor extends Model
{
    use Auditable;

    protected $table = 'proveedores';

    /** Estado en el padrón del OIC (derivado): ver estadoPadron(). */
    public const ESTADOS_PADRON = [
        'sin_registro' => 'Sin registro en el padrón',
        'en_revision' => 'En revisión',
        'vigente' => 'Cédula vigente',
        'por_vencer' => 'Cédula por vencer',
        'vencido' => 'Cédula vencida',
        'rechazado' => 'Rechazado',
        'suspendido' => 'Suspendido',
        'compra_unica' => 'Compra única (eventual)',
    ];

    /** Días antes del vencimiento de la cédula en que se avisa. */
    public const DIAS_ALERTA = 30;

    protected $fillable = [
        'nombre', 'rfc', 'correo', 'telefono', 'domicilio', 'id_cat_banco', 'clabe', 'cuenta_bancaria', 'vigencia', 'activo',
        'tipo_persona', 'representante_legal', 'giro', 'regimen_fiscal', 'compra_unica', 'padron_estado', 'padron_observaciones',
    ];

    protected function casts(): array
    {
        return ['activo' => 'boolean', 'vigencia' => 'date:Y-m-d', 'compra_unica' => 'boolean'];
    }

    /** Sin vigencia capturada no vence; con ella, vale hasta ese día inclusive (hora de México). */
    public function vigente(): bool
    {
        return $this->vigencia === null || $this->vigencia->toDateString() >= now('America/Mexico_City')->toDateString();
    }

    /**
     * Para asignarle una orden de compra: activo y con vigencia. Devuelve el motivo si no se puede.
     */
    public function impedimentoParaOrden(): ?string
    {
        if (! $this->activo) {
            return 'El proveedor está desactivado.';
        }
        if ($this->padron_estado === 'suspendido') {
            return "El Órgano Interno de Control suspendió al proveedor {$this->nombre} en el padrón".($this->padron_observaciones ? ": {$this->padron_observaciones}" : '.');
        }

        return $this->vigente() ? null
            : "La cédula del proveedor {$this->nombre} en el padrón venció el {$this->vigencia->format('d/m/Y')}; el Órgano Interno de Control debe renovarla.";
    }

    /**
     * Estado en el padrón: compra única, suspendido, rechazado, en revisión, sin registro o, ya registrado, según su
     * cédula (vigente, por vencer en DIAS_ALERTA días o vencida).
     */
    public function estadoPadron(): string
    {
        if ($this->compra_unica) {
            return 'compra_unica';
        }
        if (in_array($this->padron_estado, ['suspendido', 'rechazado', 'en_revision'], true)) {
            return $this->padron_estado;
        }
        if ($this->padron_estado !== 'registrado' || ! $this->vigencia) {
            return 'sin_registro';
        }
        $hoy = now('America/Mexico_City')->startOfDay();

        return match (true) {
            $this->vigencia->lt($hoy) => 'vencido',
            $this->vigencia->lte($hoy->copy()->addDays(self::DIAS_ALERTA)) => 'por_vencer',
            default => 'vigente',
        };
    }

    /**
     * Lo que falta en el expediente para emitir la cédula (vacío = completo): los documentos obligatorios (el acta,
     * solo persona moral) y una opinión del SAT positiva y vigente.
     *
     * @return string[]
     */
    public function faltantesParaCedula(): array
    {
        $faltan = [];
        if (! in_array($this->tipo_persona, ['fisica', 'moral'], true)) {
            $faltan[] = 'Indica si es persona física o moral.';
        }
        if (blank($this->rfc)) {
            $faltan[] = 'Captura el RFC.';
        }
        $documentos = $this->documentos()->get()->groupBy('tipo');
        foreach (ProveedorDocumento::TIPOS as $tipo => [$nombre, $obligatorio, $soloMoral]) {
            if ($obligatorio && (! $soloMoral || $this->tipo_persona === 'moral') && ! $documentos->has($tipo)) {
                $faltan[] = "Falta: {$nombre}.";
            }
        }
        $opinion = $this->opinionVigente();
        if ($opinion && $opinion->sentido !== 'positiva') {
            $faltan[] = 'La opinión de cumplimiento del SAT más reciente no es positiva.';
        } elseif ($opinion && ! $opinion->vigente()) {
            $faltan[] = 'La opinión de cumplimiento del SAT está vencida: sube una reciente.';
        }

        return $faltan;
    }

    /** La opinión de cumplimiento del SAT más reciente: la de fecha mayor y, si empatan, la última que se subió. */
    public function opinionVigente(): ?ProveedorDocumento
    {
        $opiniones = $this->relationLoaded('documentos') ? $this->documentos->where('tipo', 'opinion_cumplimiento') : $this->documentos()->where('tipo', 'opinion_cumplimiento')->get();

        return $opiniones->sortByDesc('id')->sortByDesc(fn ($d) => $d->fecha_documento?->toDateString())->first();
    }

    public function documentos()
    {
        return $this->hasMany(ProveedorDocumento::class)->orderBy('tipo')->orderByDesc('fecha_documento');
    }

    public function cedulas()
    {
        return $this->hasMany(ProveedorCedula::class)->orderByDesc('id');
    }

    public function ordenesCompra()
    {
        return $this->hasMany(OrdenCompra::class);
    }

    public function banco()
    {
        return $this->belongsTo(Banco::class, 'id_cat_banco', 'id_cat_banco');
    }

    public function cuentas()
    {
        return $this->hasMany(ProveedorCuenta::class);
    }
}
