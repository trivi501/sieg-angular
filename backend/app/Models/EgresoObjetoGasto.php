<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

class EgresoObjetoGasto extends Model
{
    use Auditable;

    /** Clasificador por tipo de gasto (CONAC). */
    public const TIPOS_GASTO = [
        1 => 'Gasto corriente',
        2 => 'Gasto de capital',
        3 => 'Amortización de la deuda y disminución de pasivos',
        4 => 'Pensiones y jubilaciones',
        5 => 'Participaciones',
    ];

    protected $table = 'cat_egreso_objeto_gasto';

    protected $primaryKey = 'id_cat_egreso_objeto_gasto';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'id_cat_egreso_objeto_gasto',
        'clave',
        'tipo_gasto',
        'nombre',
        'activo'
    ];

    protected function casts(): array
    {
        return ['activo' => 'boolean', 'tipo_gasto' => 'integer'];
    }

    /**
     * Niveles CONAC que se derivan de la partida específica de 4 dígitos (p. ej. 2111):
     * capítulo 2000, concepto 2100, partida genérica 211.
     */
    public static function niveles(?string $clave): array
    {
        if (! $clave || ! preg_match('/^\d{4}/', $clave)) {
            return ['capitulo' => null, 'concepto' => null, 'generica' => null];
        }

        return [
            'capitulo' => $clave[0].'000',
            'concepto' => substr($clave, 0, 2).'00',
            'generica' => substr($clave, 0, 3),
        ];
    }

    public function catEgresoProductos()
    {
        return $this->hasMany(EgresoProducto::class, 'id_cat_egreso_objeto_gasto', 'id_cat_egreso_objeto_gasto');
    }

    public function tbEgresoFacturas()
    {
        return $this->hasMany(EgresoFactura::class, 'id_cat_egreso_objeto_gasto', 'id_cat_egreso_objeto_gasto');
    }

    public function tbEgresoPresupuestos()
    {
        return $this->hasMany(EgresoPresupuesto::class, 'id_cat_egreso_objeto_gasto', 'id_cat_egreso_objeto_gasto');
    }

}
