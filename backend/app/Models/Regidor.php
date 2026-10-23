<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/** Regidor del Cabildo: sus enlaces (usuarios) le pertenecen y él firma sus requisiciones y CLC. */
class Regidor extends Model
{
    use Auditable;

    protected $table = 'regidores';

    protected $fillable = ['titulo', 'nombre', 'cargo', 'comision', 'activo'];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    /** "MTRA. MARIA ELENA MARQUEZ MARTINEZ". */
    public function nombreConTitulo(): string
    {
        return trim(trim((string) $this->titulo).' '.$this->nombre);
    }

    public function enlaces()
    {
        return $this->hasMany(User::class);
    }
}
