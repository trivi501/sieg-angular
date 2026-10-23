<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EgresoRequisicionEstado extends Model
{
    protected $table = 'cat_egreso_requisicion_estado';

    protected $primaryKey = 'id_cat_egreso_requisicion_estado';

    public $timestamps = false;

    protected $fillable = [
        'id_cat_egreso_requisicion_estado',
        'descripcion',
        'activo'
    ];

    public function reRequisicionEstadoMomentoContables()
    {
        return $this->hasMany(RequisicionEstadoMomentoContable::class, 'id_cat_egreso_requisicion_estado', 'id_cat_egreso_requisicion_estado');
    }

    public function tbEgresoFacturas()
    {
        return $this->hasMany(EgresoFactura::class, 'id_cat_egreso_requisicion_estado', 'id_cat_egreso_requisicion_estado');
    }

    public function tbEgresoOrdenCompras()
    {
        return $this->hasMany(EgresoOrdenCompra::class, 'id_cat_egreso_requisicion_estado', 'id_cat_egreso_requisicion_estado');
    }

    public function tbEgresoRequisicions()
    {
        return $this->hasMany(EgresoRequisicion::class, 'id_cat_egreso_requisicion_estado', 'id_cat_egreso_requisicion_estado');
    }

    public function bitacorasAnteriores()
    {
        return $this->hasMany(EgresoRequisicionBitacora::class, 'id_cat_egreso_requisicion_estado_anterior', 'id_cat_egreso_requisicion_estado');
    }

    public function bitacorasNuevas()
    {
        return $this->hasMany(EgresoRequisicionBitacora::class, 'id_cat_egreso_requisicion_estado_nuevo', 'id_cat_egreso_requisicion_estado');
    }

}
