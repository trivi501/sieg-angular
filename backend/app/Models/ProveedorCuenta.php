<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

class ProveedorCuenta extends Model
{
    use Auditable;

    protected $table = 'proveedor_cuentas';

    protected $fillable = ['proveedor_id', 'id_cat_banco', 'clabe', 'cuenta', 'sucursal', 'principal', 'activo'];

    protected function casts(): array
    {
        return ['activo' => 'boolean', 'principal' => 'boolean'];
    }

    /** Dígito verificador de la CLABE: pesos 3, 7, 1 sobre los primeros 17 dígitos, módulo 10. */
    public static function clabeValida(string $clabe): bool
    {
        if (! preg_match('/^\d{18}$/', $clabe)) {
            return false;
        }

        $pesos = [3, 7, 1];
        $suma = 0;

        for ($i = 0; $i < 17; $i++) {
            $suma += ((int) $clabe[$i] * $pesos[$i % 3]) % 10;
        }

        return (10 - $suma % 10) % 10 === (int) $clabe[17];
    }

    public function proveedor()
    {
        return $this->belongsTo(Proveedor::class);
    }

    public function banco()
    {
        return $this->belongsTo(Banco::class, 'id_cat_banco', 'id_cat_banco');
    }
}
