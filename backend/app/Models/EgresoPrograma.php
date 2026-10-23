<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

class EgresoPrograma extends Model
{
    use Auditable;

    protected $table = 'cat_egreso_programa';

    protected $primaryKey = 'id_cat_egreso_programa';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['id_cat_egreso_programa', 'clave', 'nombre', 'activo'];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }
}
