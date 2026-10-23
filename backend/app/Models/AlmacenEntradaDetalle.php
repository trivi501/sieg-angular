<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

class AlmacenEntradaDetalle extends Model
{
    use Auditable;

    protected $table = 'almacen_entrada_detalles';

    protected $fillable = ['almacen_entrada_id', 'id_tb_egreso_requisicion_detalle', 'descripcion', 'cantidad', 'precio_unitario'];

    protected function casts(): array
    {
        return ['cantidad' => 'float', 'precio_unitario' => 'float'];
    }

    public function entrada()
    {
        return $this->belongsTo(AlmacenEntrada::class, 'almacen_entrada_id');
    }
}
