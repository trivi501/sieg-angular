<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

class AlmacenSalidaDetalle extends Model
{
    use Auditable;

    protected $table = 'almacen_salida_detalles';

    protected $fillable = ['almacen_salida_id', 'id_tb_egreso_requisicion_detalle', 'descripcion', 'cantidad'];

    protected function casts(): array
    {
        return ['cantidad' => 'float'];
    }

    public function salida()
    {
        return $this->belongsTo(AlmacenSalida::class, 'almacen_salida_id');
    }
}
