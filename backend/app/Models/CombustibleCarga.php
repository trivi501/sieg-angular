<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un vale de combustible de la bitácora de una secretaría, ligado (si se sabe) a su factura (XML). Lo captura la
 * unidad de combustibles en cualquier momento, o vino del Excel del proveedor (método anterior).
 */
class CombustibleCarga extends Model
{
    /** Datos que debe tener cada vale para la bitácora; los que falten se avisan. */
    public const CAMPOS_BITACORA = [
        'vale' => 'No. de vale',
        'numero_economico' => 'No. económico',
        'fecha_vale' => 'Fecha del vale',
        'departamento' => 'Departamento',
        'conductor' => 'Quien carga',
        'producto' => 'Tipo',
        'litros' => 'Litros',
        'precio' => 'Precio',
        'importe' => 'Monto',
    ];

    protected $table = 'combustible_cargas';

    protected $fillable = [
        'combustible_lote_id', 'tramite_servicio_id', 'tramite_servicio_factura_id', 'fila', 'fecha_ticket', 'fecha_vale',
        'ticket', 'vale', 'numero_economico', 'vehiculo_id', 'departamento', 'conductor', 'producto', 'litros', 'precio',
        'importe', 'factura_folio', 'fecha_factura',
    ];

    protected function casts(): array
    {
        return [
            'fecha_ticket' => 'date',
            'fecha_vale' => 'date',
            'fecha_factura' => 'date',
            'litros' => 'float',
            'precio' => 'float',
            'importe' => 'float',
        ];
    }

    public function bitacora()
    {
        return $this->belongsTo(TramiteServicio::class, 'tramite_servicio_id');
    }

    public function factura()
    {
        return $this->belongsTo(TramiteServicioFactura::class, 'tramite_servicio_factura_id');
    }

    public function vehiculo()
    {
        return $this->belongsTo(Vehiculo::class);
    }

    /** Nombres de los datos de la bitácora que le faltan a este vale. */
    public function faltantes(): array
    {
        return array_values(array_filter(self::CAMPOS_BITACORA, fn ($nombre, $campo) => blank($this->{$campo}), ARRAY_FILTER_USE_BOTH));
    }

    /** Vales a los que les falta algún dato de la bitácora. */
    public function scopeIncompletos($query)
    {
        return $query->where(fn ($q) => collect(array_keys(self::CAMPOS_BITACORA))
            ->each(fn ($campo) => $q->orWhereNull($campo)->when(! in_array($campo, ['litros', 'precio', 'importe', 'fecha_vale'], true), fn ($w) => $w->orWhere($campo, ''))));
    }

    /**
     * Folio de factura comparable: serie y folio juntos, en mayúsculas y sin espacios ni guiones. El Excel trae
     * "ZC45634" y el XML Serie="ZC" Folio="45634".
     */
    public static function normalizarFolio(?string $folio): string
    {
        return mb_strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $folio));
    }
}
