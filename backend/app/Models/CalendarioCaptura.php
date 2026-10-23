<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/** Periodo de captura de requisiciones de un mes: del día fecha_inicio al fecha_fin (inclusive). */
class CalendarioCaptura extends Model
{
    use Auditable;

    public const MESES = [
        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio',
        7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
    ];

    protected $table = 'calendario_captura';

    protected $fillable = ['año', 'mes', 'fecha_inicio', 'fecha_fin', 'activo'];

    protected function casts(): array
    {
        return ['año' => 'integer', 'mes' => 'integer', 'fecha_inicio' => 'date:Y-m-d', 'fecha_fin' => 'date:Y-m-d', 'activo' => 'boolean'];
    }
}
