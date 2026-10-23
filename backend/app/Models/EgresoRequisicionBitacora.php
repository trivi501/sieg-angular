<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EgresoRequisicionBitacora extends Model
{
    protected $table = 'tb_egreso_requisicion_bitacora';

    protected $primaryKey = 'id_tb_egreso_requisicion_bitacora';

    public $incrementing = false;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'id_tb_egreso_requisicion_bitacora',
        'id_tb_egreso_requisicion',
        'id_cat_egreso_requisicion_estado_anterior',
        'id_cat_egreso_requisicion_estado_nuevo',
        'id_tb_usuarios',
        'registro',
        'observaciones'
    ];

    public function catEgresoRequisicionEstadoAnterior()
    {
        return $this->belongsTo(EgresoRequisicionEstado::class, 'id_cat_egreso_requisicion_estado_anterior', 'id_cat_egreso_requisicion_estado');
    }

    public function catEgresoRequisicionEstadoNuevo()
    {
        return $this->belongsTo(EgresoRequisicionEstado::class, 'id_cat_egreso_requisicion_estado_nuevo', 'id_cat_egreso_requisicion_estado');
    }

    public function tbEgresoRequisicion()
    {
        return $this->belongsTo(EgresoRequisicion::class, 'id_tb_egreso_requisicion', 'id_tb_egreso_requisicion');
    }

}
