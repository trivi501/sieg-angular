<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

class PresupuestoEgreso extends Model
{
    use Auditable;

    protected $table = 'presupuesto 2024';

    protected $primaryKey = 'id_presupuesto';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'id_presupuesto',
        'CLAVE',
        'UNIDAD_ADMINISTRATIVA',
        'CLAVE3',
        'FUENTE_DE_FINANCIAMIENTO',
        'CLAVE6',
        'PROYECTO',
        'PARTIDA',
        'capitulo',
        'TIPO_DE_GASTO',
        'NOMBRE_PARTIDA',
        'IMPORTE_TOTAL',
        'ENERO',
        'FEBRERO',
        'MARZO',
        'ABRIL',
        'MAYO',
        'JUNIO',
        'JULIO',
        'AGOSTO',
        'SEP',
        'OCTUBRE',
        'NOV',
        'DIC',
    ];

    protected function casts(): array
    {
        return [
            'IMPORTE_TOTAL' => 'float',
            'ENERO' => 'float',
            'FEBRERO' => 'float',
            'MARZO' => 'float',
            'ABRIL' => 'float',
            'MAYO' => 'float',
            'JUNIO' => 'float',
            'JULIO' => 'float',
            'AGOSTO' => 'float',
            'SEP' => 'float',
            'OCTUBRE' => 'float',
            'NOV' => 'float',
            'DIC' => 'float',
        ];
    }
}
