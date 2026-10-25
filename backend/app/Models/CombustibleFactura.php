<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * Factura (XML) del proveedor de combustible subida antes del Excel: ya verificada en el SAT, espera a que un Excel
 * diga de qué secretaría es. Al asignarse se convierte en factura de la bitácora y este registro se borra.
 */
class CombustibleFactura extends Model
{
    use Auditable;

    protected $table = 'combustible_facturas';

    protected $fillable = [
        'uuid', 'folio', 'rfc_emisor', 'nombre_emisor', 'fecha', 'total', 'xml_path', 'cfdi', 'sat_estado', 'sat_efos', 'sat_verificado_at', 'user_id',
    ];

    protected $hidden = ['xml_path', 'cfdi'];

    protected function casts(): array
    {
        return ['fecha' => 'datetime', 'total' => 'float', 'cfdi' => 'array', 'sat_efos' => 'boolean', 'sat_verificado_at' => 'datetime'];
    }
}
