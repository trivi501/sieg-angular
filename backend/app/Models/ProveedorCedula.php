<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/** Cédula de proveedor y contratista (CED-AÑO-0001) que emite el Órgano Interno de Control, con vigencia de un año. */
class ProveedorCedula extends Model
{
    use Auditable;

    protected $table = 'proveedor_cedulas';

    protected $fillable = ['proveedor_id', 'folio', 'año', 'numero', 'fecha_emision', 'vigencia', 'observaciones', 'user_id'];

    protected function casts(): array
    {
        return ['fecha_emision' => 'date', 'vigencia' => 'date'];
    }

    public function proveedor()
    {
        return $this->belongsTo(Proveedor::class);
    }

    public function usuario()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
