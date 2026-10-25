<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/** Movimiento del estado de cuenta de una cuenta del Ayuntamiento (cargo o abono); un cargo se concilia con su pago. */
class TesoreriaMovimiento extends Model
{
    use Auditable;

    public const TIPOS = ['cargo' => 'Cargo', 'abono' => 'Abono'];

    protected $table = 'tesoreria_movimientos';

    protected $fillable = ['cuenta_id', 'fecha', 'tipo', 'importe', 'referencia', 'clave_rastreo', 'concepto', 'tesoreria_pago_id', 'origen', 'user_id'];

    protected function casts(): array
    {
        return ['fecha' => 'date', 'importe' => 'float'];
    }

    public function cuenta()
    {
        return $this->belongsTo(TesoreriaCuenta::class, 'cuenta_id');
    }

    public function pago()
    {
        return $this->belongsTo(TesoreriaPago::class, 'tesoreria_pago_id');
    }
}
