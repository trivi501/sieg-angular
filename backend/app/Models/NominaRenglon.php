<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Un concepto de la nómina con su clasificación presupuestal (unidad, fuente, proyecto, partida) e importe. */
class NominaRenglon extends Model
{
    protected $table = 'nomina_renglones';

    protected $fillable = [
        'nomina_id', 'fila', 'id_cat_egreso_unidad_administrativa', 'unidad_clave', 'fuente', 'proyecto', 'partida', 'concepto', 'importe',
    ];

    protected function casts(): array
    {
        return ['importe' => 'float'];
    }

    public function nomina()
    {
        return $this->belongsTo(Nomina::class);
    }

    public function unidadAdministrativa()
    {
        return $this->belongsTo(EgresoUnidadAdministrativa::class, 'id_cat_egreso_unidad_administrativa', 'id_cat_egreso_unidad_administrativa');
    }
}
