<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

class EgresoRequisicionDetalle extends Model
{
    use Auditable;

    protected $table = 'tb_egreso_requisicion_detalle';

    protected $primaryKey = 'id_tb_egreso_requisicion_detalle';

    public $incrementing = false;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'cantidad',
        'id_tb_egreso_requisicion',
        'descripcion',
        'fuente',
        'proyecto',
        'partida',
        'articulo',
        'precio_unitario',
        'sub_total',
        'iva',
        'descuento',
        'total',
        'id_tb_egreso_requisicion_detalle',
        'id_cat_egreso_producto',
        'iva_factor',
        'id_tb_egreso_presupuesto_detalle',
        'id_tb_egreso_presupuesto'
    ];

    public function tbEgresoRequisicion()
    {
        return $this->belongsTo(EgresoRequisicion::class, 'id_tb_egreso_requisicion', 'id_tb_egreso_requisicion');
    }

    public function catEgresoProducto()
    {
        return $this->belongsTo(EgresoProducto::class, 'id_cat_egreso_producto', 'id_cat_egreso_producto');
    }

    public function tbEgresoPresupuesto()
    {
        return $this->belongsTo(EgresoPresupuesto::class, 'id_tb_egreso_presupuesto', 'id_tb_egreso_presupuesto');
    }

    public function tbEgresoPresupuestoDetalle()
    {
        return $this->belongsTo(EgresoPresupuestoDetalle::class, 'id_tb_egreso_presupuesto_detalle', 'id_tb_egreso_presupuesto_detalle');
    }

}
