<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * Una carga del Excel del proveedor de combustible (una hoja, p. ej. ENERO). De ella salen las bitácoras de
 * combustible, una por secretaría: cada factura del Excel es de una sola secretaría.
 */
class CombustibleLote extends Model
{
    use Auditable;

    protected $table = 'combustible_lotes';

    protected $fillable = ['periodo', 'archivo_nombre', 'archivo_path', 'hoja', 'tickets', 'litros', 'importe', 'user_id'];

    protected $hidden = ['archivo_path'];

    protected function casts(): array
    {
        return ['tickets' => 'integer', 'litros' => 'float', 'importe' => 'float'];
    }

    public function bitacoras()
    {
        return $this->hasMany(TramiteServicio::class, 'combustible_lote_id')->orderBy('id');
    }

    public function cargas()
    {
        return $this->hasMany(CombustibleCarga::class, 'combustible_lote_id');
    }

    public function usuario()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
