<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

class OrdenCompra extends Model
{
    use Auditable;

    protected $table = 'ordenes_compra';

    protected $fillable = [
        'id_tb_egreso_requisicion', 'proveedor_id', 'folio', 'año', 'folio_completo', 'fecha', 'importe', 'xml_uuid_cancelado',
        'observaciones', 'user_id', 'xml_path', 'xml_uuid', 'xml_rfc_emisor', 'xml_nombre_emisor',
        'xml_serie', 'xml_folio', 'xml_fecha', 'xml_subtotal', 'xml_total', 'xml_isr', 'xml_cargado_at', 'xml_user_id',
        'sat_estado', 'sat_estatus_cancelacion', 'sat_efos', 'sat_verificado_at',
        'pago_fecha', 'pago_forma', 'pago_referencia', 'pago_user_id', 'pago_registrado_at',
        'transferencia_folio', 'transferencia_año', 'transferencia_numero',
        'clc_folio', 'clc_año', 'clc_folio_completo', 'clc_fecha', 'clc_user_id', 'clc_generada_at', 'clc_estado', 'clc_relacion_id',
        'clc_cancelada_at', 'clc_cancelada_user_id', 'clc_motivo_cancelacion', 'cancelada_at', 'cancelada_user_id', 'motivo_cancelacion', 'almacen_asignada_at', 'almacen_asignada_user_id',
    ];

    public const FORMAS_PAGO = ['transferencia' => 'Transferencia', 'cheque' => 'Cheque'];

    protected $hidden = ['xml_path'];

    protected function casts(): array
    {
        return [
            'fecha' => 'date:Y-m-d',
            'importe' => 'float',
            'xml_fecha' => 'datetime',
            'xml_subtotal' => 'float',
            'xml_total' => 'float',
            'xml_isr' => 'float',
            'xml_cargado_at' => 'datetime',
            'sat_efos' => 'boolean',
            'sat_verificado_at' => 'datetime',
            'pago_fecha' => 'date:Y-m-d',
            'pago_registrado_at' => 'datetime',
            'clc_fecha' => 'date:Y-m-d',
            'clc_generada_at' => 'datetime',
            'clc_cancelada_at' => 'datetime',
            'cancelada_at' => 'datetime',
            'almacen_asignada_at' => 'datetime',
        ];
    }

    public function requisicion()
    {
        return $this->belongsTo(EgresoRequisicion::class, 'id_tb_egreso_requisicion', 'id_tb_egreso_requisicion');
    }

    public function proveedor()
    {
        return $this->belongsTo(Proveedor::class);
    }

    public function movimientosClc()
    {
        return $this->hasMany(ClcMovimiento::class)->orderBy('id');
    }

    public function relacionClc()
    {
        return $this->belongsTo(ClcRelacion::class, 'clc_relacion_id');
    }

    /**
     * Lo que se le paga al proveedor: el importe menos el ISR retenido en la factura (el importe del gasto
     * no cambia por la retención).
     */
    public function netoAPagar(): float
    {
        return round((float) $this->importe - (float) ($this->xml_isr ?? 0), 2);
    }

    /** La CLC está generada y no se ha cancelado. */
    public function clcActiva(): bool
    {
        return $this->clc_folio_completo !== null && $this->clc_cancelada_at === null;
    }

    public function refacturas()
    {
        return $this->hasMany(OrdenCompraRefactura::class)->orderBy('id');
    }

    public function entradasAlmacen()
    {
        return $this->hasMany(AlmacenEntrada::class)->orderBy('id');
    }

    public function salidasAlmacen()
    {
        return $this->hasMany(AlmacenSalida::class)->orderBy('id');
    }

    public function canceladaPor()
    {
        return $this->belongsTo(User::class, 'cancelada_user_id');
    }

    public function clcCanceladaPor()
    {
        return $this->belongsTo(User::class, 'clc_cancelada_user_id');
    }

    public function usuario()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
