<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

class Banco extends Model
{
    use Auditable;

    protected $table = 'cat_banco';

    protected $primaryKey = 'id_cat_banco';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['id_cat_banco', 'clave', 'descripcion', 'activo'];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }
}
