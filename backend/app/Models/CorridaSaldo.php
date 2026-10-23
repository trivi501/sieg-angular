<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

class CorridaSaldo extends Model
{
    use Auditable;

    protected $table = 'corridas_saldo';

    protected $fillable = ['ejercicio', 'mes', 'origen', 'user_id', 'lineas', 'total_corrido'];

    protected function casts(): array
    {
        return ['ejercicio' => 'integer', 'mes' => 'integer', 'lineas' => 'integer', 'total_corrido' => 'float'];
    }

    public function saldos()
    {
        return $this->hasMany(SaldoMensual::class);
    }

    public function usuario()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
