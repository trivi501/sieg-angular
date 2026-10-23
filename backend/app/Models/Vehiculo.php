<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

class Vehiculo extends Model
{
    use Auditable;

    public const ESTADOS = [
        'en_servicio' => 'En servicio',
        'taller' => 'En taller',
        'fuera_de_servicio' => 'Fuera de servicio',
    ];

    protected $fillable = [
        'numero_economico', 'placas', 'serie', 'marca', 'linea', 'modelo', 'color', 'tipo', 'id_cat_egreso_unidad_administrativa', 'departamento_id',
        'resguardante', 'cargo_resguardante', 'numero_resguardo', 'fecha_resguardo', 'estado', 'observaciones', 'activo',
    ];

    protected function casts(): array
    {
        return ['activo' => 'boolean', 'fecha_resguardo' => 'date', 'modelo' => 'integer'];
    }

    public function departamento()
    {
        return $this->belongsTo(Departamento::class);
    }

    public function mantenimientos()
    {
        return $this->hasMany(VehiculoMantenimiento::class)->orderByDesc('id');
    }

    public function unidadAdministrativa()
    {
        return $this->belongsTo(EgresoUnidadAdministrativa::class, 'id_cat_egreso_unidad_administrativa', 'id_cat_egreso_unidad_administrativa');
    }
}
