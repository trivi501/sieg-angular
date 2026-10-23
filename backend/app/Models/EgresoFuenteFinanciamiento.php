<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

class EgresoFuenteFinanciamiento extends Model
{
    use Auditable;

    protected $table = 'cat_egreso_fuente_financiamiento';

    protected $primaryKey = 'id_cat_egreso_fuente_financiamiento';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'id_cat_egreso_fuente_financiamiento',
        'clave',
        'nombre',
        'activo'
    ];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    public function tbEgresoPresupuestos()
    {
        return $this->hasMany(EgresoPresupuesto::class, 'id_cat_egreso_fuente_financiamiento', 'id_cat_egreso_fuente_financiamiento');
    }

}
