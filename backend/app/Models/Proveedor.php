<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

class Proveedor extends Model
{
    use Auditable;

    protected $table = 'proveedores';

    protected $fillable = ['nombre', 'rfc', 'correo', 'telefono', 'domicilio', 'id_cat_banco', 'clabe', 'cuenta_bancaria', 'vigencia', 'activo'];

    protected function casts(): array
    {
        return ['activo' => 'boolean', 'vigencia' => 'date:Y-m-d'];
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

        return $this->vigente() ? null
            : "La vigencia del proveedor {$this->nombre} venció el {$this->vigencia->format('d/m/Y')}; actualízala en el catálogo de proveedores.";
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
