<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/** Cuenta bancaria del Ayuntamiento de la que salen los pagos, con su fuente de financiamiento y saldo inicial. */
class TesoreriaCuenta extends Model
{
    use Auditable;

    protected $table = 'tesoreria_cuentas';

    protected $fillable = ['nombre', 'id_cat_banco', 'numero', 'clabe', 'fuente', 'saldo_inicial', 'fecha_saldo_inicial', 'activo'];

    protected function casts(): array
    {
        return ['saldo_inicial' => 'float', 'fecha_saldo_inicial' => 'date', 'activo' => 'boolean'];
    }

    public function banco()
    {
        return $this->belongsTo(Banco::class, 'id_cat_banco', 'id_cat_banco');
    }

    public function movimientos()
    {
        return $this->hasMany(TesoreriaMovimiento::class, 'cuenta_id');
    }

    public function pagos()
    {
        return $this->hasMany(TesoreriaPago::class, 'cuenta_id');
    }

    /** "BANORTE · OPERACIÓN FAISMUN (…4521)" para listas y reportes. */
    public function etiqueta(): string
    {
        $cuenta = $this->numero ?: $this->clabe;

        return trim(($this->banco?->descripcion ? $this->banco->descripcion.' · ' : '').$this->nombre.($cuenta ? ' (…'.substr($cuenta, -4).')' : ''));
    }
}
