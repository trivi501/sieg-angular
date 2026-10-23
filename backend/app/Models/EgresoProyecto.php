<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

class EgresoProyecto extends Model
{
    use Auditable;

    protected $table = 'cat_egreso_proyecto';

    protected $primaryKey = 'id_cat_egreso_proyecto';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'id_cat_egreso_proyecto',
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
        return $this->hasMany(EgresoPresupuesto::class, 'id_cat_egreso_proyecto', 'id_cat_egreso_proyecto');
    }

}
