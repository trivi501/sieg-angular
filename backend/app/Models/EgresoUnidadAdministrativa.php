<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

class EgresoUnidadAdministrativa extends Model
{
    use Auditable;

    protected $table = 'cat_egreso_unidad_administrativa';

    protected $primaryKey = 'id_cat_egreso_unidad_administrativa';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'id_cat_egreso_unidad_administrativa',
        'secretaria_id',
        'clave',
        'nombre',
        'año',
        'activo',
        'id_cat_area_x_nombre_y_puesto',
        'responsable',
        'cargo_responsable',
        'prefijo',
    ];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    public function departamentos()
    {
        return $this->hasMany(Departamento::class, 'id_cat_egreso_unidad_administrativa', 'id_cat_egreso_unidad_administrativa');
    }

    public function usuarios()
    {
        return $this->hasMany(User::class, 'id_cat_egreso_unidad_administrativa', 'id_cat_egreso_unidad_administrativa');
    }

    /**
     * Ids de todas las unidades con la misma clave (la misma secretaría o dirección en distintos años):
     * es lo que ve un usuario asignado a la unidad $id.
     */
    public static function idsMismaClave(?int $id): array
    {
        $clave = $id ? static::whereKey($id)->value('clave') : null;

        return $clave === null ? [] : static::where('clave', $clave)->pluck('id_cat_egreso_unidad_administrativa')->all();
    }

    public function tbEgresoFacturas()
    {
        return $this->hasMany(EgresoFactura::class, 'id_cat_egreso_unidad_administrativa', 'id_cat_egreso_unidad_administrativa');
    }

    public function tbEgresoPresupuestos()
    {
        return $this->hasMany(EgresoPresupuesto::class, 'id_cat_egreso_unidad_administrativa', 'id_cat_egreso_unidad_administrativa');
    }

    public function tbEgresoRequisicions()
    {
        return $this->hasMany(EgresoRequisicion::class, 'id_cat_egreso_unidad_administrativa', 'id_cat_egreso_unidad_administrativa');
    }

}
