<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/** Envío de varias CLC de un área a otra (el documento que acompaña a las CLC físicas). */
class ClcRelacion extends Model
{
    use Auditable;

    protected $table = 'clc_relaciones';

    protected $fillable = ['folio', 'año', 'folio_completo', 'origen', 'destino', 'observaciones', 'user_id'];

    public function ordenes()
    {
        return $this->belongsToMany(OrdenCompra::class, 'clc_relacion_orden', 'clc_relacion_id', 'orden_compra_id')
            ->withPivot('recibida_at', 'recibida_user_id');
    }

    public function usuario()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
