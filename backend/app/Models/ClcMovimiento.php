<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Historial del trámite de una CLC: cada envío, recepción, devolución y el pago. */
class ClcMovimiento extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'clc_movimientos';

    protected $fillable = ['orden_compra_id', 'accion', 'estado_anterior', 'estado_nuevo', 'clc_relacion_id', 'observaciones', 'user_id'];

    public function usuario()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function relacion()
    {
        return $this->belongsTo(ClcRelacion::class, 'clc_relacion_id');
    }
}
