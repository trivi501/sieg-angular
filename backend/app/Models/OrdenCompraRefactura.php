<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/** Historial de refacturas de una orden de compra: factura y CLC anteriores, y la CLC nueva. */
class OrdenCompraRefactura extends Model
{
    use Auditable;

    protected $table = 'ordenes_compra_refacturas';

    protected $fillable = [
        'orden_compra_id', 'xml_uuid_anterior', 'xml_folio_anterior', 'xml_total_anterior', 'xml_path_anterior',
        'clc_folio_anterior', 'clc_estado_anterior', 'clc_nueva', 'motivo', 'user_id',
    ];

    protected $hidden = ['xml_path_anterior'];

    protected function casts(): array
    {
        return ['xml_total_anterior' => 'float'];
    }

    public function ordenCompra()
    {
        return $this->belongsTo(OrdenCompra::class);
    }

    public function usuario()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
