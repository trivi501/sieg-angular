<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

class EgresoRequisicion extends Model
{
    use Auditable;

    protected $table = 'tb_egreso_requisicion';

    protected $primaryKey = 'id_tb_egreso_requisicion';

    public $incrementing = false;

    protected $keyType = 'int';

    public $timestamps = false;

    /** Estados de cat_egreso_requisicion_estado (1–6 del sistema anterior). */
    public const EN_COTIZACION = 6;

    public const COMPROMETIDA = 7;

    public const CON_ORDEN_COMPRA = 8;

    public const CON_FACTURA = 9;

    public const CLC_GENERADA = 10;

    public const PAGADA = 11;

    public const CANCELADA = 12;

    /** Estados en los que todavía se puede cancelar (antes de la orden de compra). */
    public const CANCELABLES = [1, 2, 3, 4, 5, 6, 7];

    protected $fillable = [
        'id_tb_egreso_requisicion',
        'id_cat_egreso_requisicion_estado',
        'id_cat_egreso_solicitud_tipo',
        'id_tb_egreso_presupuesto',
        'id_cat_egreso_unidad_administrativa',
        'id_cat_area_x_nombre_y_puesto_Solicita',
        'id_cat_area_x_nombre_y_puesto_Autoriza',
        'id_tb_usuarios',
        'id_user_laravel',
        'id_tb_egreso_presupuesto_detalle',
        'id_tb_egreso_proveedor',
        'folio',
        'año',
        'registro',
        'solicitado',
        'observaciones',
        'sub_total',
        'isr',
        'iva',
        'descuento',
        'total',
        'folio_completo',
        'sub_direccion',
        'departamento',
        'monto_requisicion',
        'beneficiario',
        'beneficiario_referencia',
        'suficiencia',
        'suficiencia_fecha',
        'suficiencia_user_id',
        'extemporanea',
        'extemporanea_user_id',
        'extemporanea_autorizada_at',
        'cancelada_at',
        'cancelada_user_id',
        'motivo_cancelacion',
    ];

    protected function casts(): array
    {
        return [
            'suficiencia' => 'boolean',
            'suficiencia_fecha' => 'datetime',
            'extemporanea' => 'boolean',
            'extemporanea_autorizada_at' => 'datetime',
            'cancelada_at' => 'datetime',
        ];
    }

    /** Capturada fuera del periodo y aún sin autorización de la Subdirección Administrativa. */
    public function extemporaneaPendiente(): bool
    {
        return (bool) $this->extemporanea && $this->extemporanea_autorizada_at === null;
    }

    public function canceladaPor()
    {
        return $this->belongsTo(User::class, 'cancelada_user_id');
    }

    public function extemporaneaAutorizador()
    {
        return $this->belongsTo(User::class, 'extemporanea_user_id');
    }

    public function ordenCompra()
    {
        return $this->hasOne(OrdenCompra::class, 'id_tb_egreso_requisicion', 'id_tb_egreso_requisicion');
    }

    public function catEgresoRequisicionEstado()
    {
        return $this->belongsTo(EgresoRequisicionEstado::class, 'id_cat_egreso_requisicion_estado', 'id_cat_egreso_requisicion_estado');
    }

    public function catEgresoUnidadAdministrativa()
    {
        return $this->belongsTo(EgresoUnidadAdministrativa::class, 'id_cat_egreso_unidad_administrativa', 'id_cat_egreso_unidad_administrativa');
    }

    public function catEgresoSolicitudTipo()
    {
        return $this->belongsTo(EgresoSolicitudTipo::class, 'id_cat_egreso_solicitud_tipo', 'id_cat_egreso_solicitud_tipo');
    }

    public function tbEgresoPresupuesto()
    {
        return $this->belongsTo(EgresoPresupuesto::class, 'id_tb_egreso_presupuesto', 'id_tb_egreso_presupuesto');
    }

    public function tbEgresoPresupuestoDetalle()
    {
        return $this->belongsTo(EgresoPresupuestoDetalle::class, 'id_tb_egreso_presupuesto_detalle', 'id_tb_egreso_presupuesto_detalle');
    }

    public function tbEgresoProveedor()
    {
        return $this->belongsTo(EgresoProveedor::class, 'id_tb_egreso_proveedor', 'id_tb_egreso_proveedor');
    }

    public function solicitante()
    {
        return $this->belongsTo(EgresoAreaNombrePuesto::class, 'id_cat_area_x_nombre_y_puesto_Solicita', 'id');
    }

    public function autorizante()
    {
        return $this->belongsTo(EgresoAreaNombrePuesto::class, 'id_cat_area_x_nombre_y_puesto_Autoriza', 'id');
    }

    public function tbEgresoOrdenCompras()
    {
        return $this->hasMany(EgresoOrdenCompra::class, 'id_tb_egreso_requisicion', 'id_tb_egreso_requisicion');
    }

    public function tbEgresoRequisicionBitacoras()
    {
        return $this->hasMany(EgresoRequisicionBitacora::class, 'id_tb_egreso_requisicion', 'id_tb_egreso_requisicion');
    }

    public function tbEgresoRequisicionDetalles()
    {
        return $this->hasMany(EgresoRequisicionDetalle::class, 'id_tb_egreso_requisicion', 'id_tb_egreso_requisicion');
    }

    public function tbEgresoRequisicionPartidas()
    {
        return $this->hasMany(EgresoRequisicionPartida::class, 'id_tb_egreso_requisicion', 'id_tb_egreso_requisicion');
    }
}
