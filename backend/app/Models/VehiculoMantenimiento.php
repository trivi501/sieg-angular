<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/** Recepción de un vehículo en el taller (folio MV-AÑO-0001) y su requisición. */
class VehiculoMantenimiento extends Model
{
    use Auditable;

    public const TIPOS = ['preventivo' => 'Preventivo', 'correctivo' => 'Correctivo'];

    public const ESTADOS = ['en_taller' => 'En taller', 'entregado' => 'Entregado'];

    protected $table = 'vehiculo_mantenimientos';

    protected $fillable = [
        'vehiculo_id', 'folio', 'año', 'folio_completo', 'id_cat_egreso_unidad_administrativa', 'departamento_id',
        'fecha_recepcion', 'kilometraje', 'tipo', 'descripcion', 'entrega', 'user_id', 'estado',
        'fecha_entrega', 'recibe_entrega', 'observaciones_entrega', 'id_tb_egreso_requisicion',
    ];

    protected function casts(): array
    {
        return ['fecha_recepcion' => 'date:Y-m-d', 'fecha_entrega' => 'date:Y-m-d', 'kilometraje' => 'integer'];
    }

    public function vehiculo()
    {
        return $this->belongsTo(Vehiculo::class);
    }

    public function unidadAdministrativa()
    {
        return $this->belongsTo(EgresoUnidadAdministrativa::class, 'id_cat_egreso_unidad_administrativa', 'id_cat_egreso_unidad_administrativa');
    }

    public function departamento()
    {
        return $this->belongsTo(Departamento::class);
    }

    public function requisicion()
    {
        return $this->belongsTo(EgresoRequisicion::class, 'id_tb_egreso_requisicion', 'id_tb_egreso_requisicion');
    }

    public function usuario()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Texto corto del vehículo para el concepto de la requisición y las listas. */
    public function textoVehiculo(): string
    {
        $v = $this->vehiculo;

        return trim('ECO '.$v?->numero_economico.($v?->placas ? " · PLACAS {$v->placas}" : '').' · '.trim(($v?->marca ?? '').' '.($v?->linea ?? '').' '.($v?->modelo ?? '')));
    }
}
