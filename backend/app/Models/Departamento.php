<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

class Departamento extends Model
{
    use Auditable;

    protected $fillable = ['id_cat_egreso_unidad_administrativa', 'clave', 'nombre', 'responsable', 'activo'];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    public function unidadAdministrativa()
    {
        return $this->belongsTo(EgresoUnidadAdministrativa::class, 'id_cat_egreso_unidad_administrativa', 'id_cat_egreso_unidad_administrativa');
    }
}
