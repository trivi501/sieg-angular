<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/** Salida de almacén: lo que el almacén entrega al área de lo recibido de una orden de compra (folio SA-AÑO-0001). */
class AlmacenSalida extends Model
{
    use Auditable;

    protected $table = 'almacen_salidas';

    protected $fillable = ['orden_compra_id', 'folio', 'año', 'folio_completo', 'fecha', 'id_cat_egreso_unidad_administrativa', 'recibe', 'observaciones', 'user_id'];

    protected function casts(): array
    {
        return ['fecha' => 'date:Y-m-d'];
    }

    public function ordenCompra()
    {
        return $this->belongsTo(OrdenCompra::class);
    }

    public function detalles()
    {
        return $this->hasMany(AlmacenSalidaDetalle::class);
    }

    public function usuario()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function unidadAdministrativa()
    {
        return $this->belongsTo(EgresoUnidadAdministrativa::class, 'id_cat_egreso_unidad_administrativa', 'id_cat_egreso_unidad_administrativa');
    }
}
