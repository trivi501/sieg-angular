<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

class TipoDocumento extends Model
{
    use Auditable;

    protected $table = 'tipos_documento';

    protected $fillable = ['clave', 'nombre', 'prefijo', 'activo'];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    public function firmantes()
    {
        return $this->hasMany(Firmante::class);
    }
}
