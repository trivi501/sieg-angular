<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/** Entrada de almacén: lo que el almacén recibe del proveedor de una orden de compra (folio EA-AÑO-0001). */
class AlmacenEntrada extends Model
{
    use Auditable;

    protected $table = 'almacen_entradas';

    protected $fillable = ['orden_compra_id', 'folio', 'año', 'folio_completo', 'fecha', 'observaciones', 'user_id'];

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
        return $this->hasMany(AlmacenEntradaDetalle::class);
    }

    public function usuario()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
