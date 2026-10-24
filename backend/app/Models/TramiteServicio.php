<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * Trámite de servicios (folio TS-AÑO-0001): el área sube las facturas (XML) de un mismo proveedor, asigna
 * partida / proyecto / fuente y concepto a cada una y lo envía a validar. Al validarlo Recursos Materiales se
 * generan la requisición (comprometida), la orden de compra con las facturas y la CLC (un renglón por factura).
 */
class TramiteServicio extends Model
{
    use Auditable;

    public const ESTADOS = [
        'captura' => 'En captura',
        'por_validar' => 'Por validar',
        'rechazado' => 'No validado',
        'validado' => 'Validado',
        'cancelado' => 'Cancelado',
    ];

    /** Estados en los que el área todavía puede subir, quitar o clasificar facturas. */
    public const EDITABLES = ['captura', 'rechazado'];

    protected $table = 'tramites_servicio';

    protected $fillable = [
        'folio', 'año', 'folio_completo', 'id_cat_egreso_unidad_administrativa', 'concepto', 'proveedor_rfc', 'proveedor_nombre',
        'estado', 'observaciones', 'user_id', 'enviado_at', 'validado_at', 'validado_user_id', 'id_tb_egreso_requisicion',
    ];

    protected function casts(): array
    {
        return ['enviado_at' => 'datetime', 'validado_at' => 'datetime'];
    }

    public function facturas()
    {
        return $this->hasMany(TramiteServicioFactura::class)->orderBy('id');
    }

    public function unidadAdministrativa()
    {
        return $this->belongsTo(EgresoUnidadAdministrativa::class, 'id_cat_egreso_unidad_administrativa', 'id_cat_egreso_unidad_administrativa');
    }

    public function requisicion()
    {
        return $this->belongsTo(EgresoRequisicion::class, 'id_tb_egreso_requisicion', 'id_tb_egreso_requisicion');
    }

    public function ordenCompra()
    {
        return $this->hasOne(OrdenCompra::class, 'tramite_servicio_id');
    }

    public function usuario()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function validador()
    {
        return $this->belongsTo(User::class, 'validado_user_id');
    }

    /** Proveedor del catálogo con el RFC del emisor de las facturas (null si no está registrado). */
    public function proveedor(): ?Proveedor
    {
        return $this->proveedor_rfc
            ? Proveedor::whereRaw('UPPER(TRIM(rfc)) = ?', [mb_strtoupper(trim($this->proveedor_rfc))])->orderByDesc('activo')->first()
            : null;
    }

    /** Por qué todavía no se puede avanzar con este proveedor; null si está registrado y vigente. */
    public function impedimentoProveedor(): ?string
    {
        if (! $this->proveedor_rfc) {
            return null;
        }
        $proveedor = $this->proveedor();
        if (! $proveedor) {
            return "El proveedor con RFC {$this->proveedor_rfc} no está registrado en el padrón de proveedores. Pide a Administración que lo dé de alta; puedes seguir capturando, pero no se puede enviar a validar hasta que esté registrado.";
        }
        if (! $proveedor->activo) {
            return "El proveedor {$proveedor->nombre} ({$this->proveedor_rfc}) está dado de baja en el padrón. Pide a Administración que lo reactive.";
        }

        return $proveedor->impedimentoParaOrden();
    }

    public function editable(): bool
    {
        return in_array($this->estado, self::EDITABLES, true);
    }
}
