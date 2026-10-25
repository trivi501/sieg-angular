<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Un ticket de combustible del Excel del proveedor, en la bitácora de su secretaría y ligado a su factura (XML). */
class CombustibleCarga extends Model
{
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

    /**
     * Folio de factura comparable: serie y folio juntos, en mayúsculas y sin espacios ni guiones. El Excel trae
     * "ZC45634" y el XML Serie="ZC" Folio="45634".
     */
    public static function normalizarFolio(?string $folio): string
    {
        return mb_strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $folio));
    }
}
