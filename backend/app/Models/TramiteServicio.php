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

    /**
     * Tipos: trámite de servicios (un proveedor, con orden de compra), fondo revolvente (varios proveedores, a favor
     * de un trabajador) o bitácora de combustible (como un servicio, pero se crea desde el Excel del proveedor y
     * lleva sus tickets) o mensualidad de contrato (como un servicio; la genera el contrato con el XML del mes).
     */
    public const TIPOS = [
        'servicio' => ['prefijo' => 'TS', 'permiso' => 'servicios', 'nombre' => 'Trámite de servicios'],
        'fondo' => ['prefijo' => 'FR', 'permiso' => 'fondos', 'nombre' => 'Fondo revolvente'],
        'combustible' => ['prefijo' => 'BC', 'permiso' => 'combustible', 'nombre' => 'Bitácora de combustible'],
        'contrato' => ['prefijo' => 'TC', 'permiso' => 'contratos', 'nombre' => 'Mensualidad de contrato'],
    ];

    /** Monto máximo de cada factura de un fondo revolvente (Total del CFDI). */
    public const LIMITE_FACTURA_FONDO = 2000.00;

    /** Fondo revolvente: tipo de gasto de cada factura. Los viáticos llevan además su comisión (destino, fechas y motivo). */
    public const TIPOS_GASTO_FONDO = [
        'viaticos' => 'Viáticos',
        'alimentos' => 'Alimentos y bebidas',
        'papeleria' => 'Papelería y consumibles',
        'limpieza' => 'Material de limpieza',
        'mantenimiento' => 'Mantenimiento y refacciones menores',
        'transporte' => 'Transporte, casetas y estacionamiento',
        'otros' => 'Otros gastos menores',
    ];

    /** Estados en los que el área todavía puede subir, quitar o clasificar facturas. */
    public const EDITABLES = ['captura', 'rechazado'];

    protected $table = 'tramites_servicio';

    protected $fillable = [
        'tipo', 'combustible_lote_id', 'contrato_id', 'trabajador_nombre', 'trabajador_numero', 'trabajador_puesto',
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

    /** Bitácora de combustible: la carga del Excel de la que salió y sus tickets. */
    public function lote()
    {
        return $this->belongsTo(CombustibleLote::class, 'combustible_lote_id');
    }

    /** Mensualidad de contrato: el contrato del que salió. */
    public function contrato()
    {
        return $this->belongsTo(Contrato::class);
    }

    public function cargas()
    {
        return $this->hasMany(CombustibleCarga::class, 'tramite_servicio_id')->orderBy('fila');
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
        if ($this->esFondo() || ! $this->proveedor_rfc) {
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

    public function esFondo(): bool
    {
        return $this->tipo === 'fondo';
    }

    public function esCombustible(): bool
    {
        return $this->tipo === 'combustible';
    }

    public function nombreTipo(): string
    {
        return self::TIPOS[$this->tipo]['nombre'] ?? 'Trámite';
    }

    /** Prefijo de los permisos del tipo: servicios-* o fondos-*. */
    public function permiso(string $accion): string
    {
        return self::TIPOS[$this->tipo]['permiso'].'-'.$accion;
    }

    /** A favor de quién va la CLC del fondo: el trabajador, con su número de empleado. */
    public function beneficiarioFondo(): ?string
    {
        if (! $this->trabajador_nombre) {
            return null;
        }

        return mb_strtoupper(trim($this->trabajador_nombre)).($this->trabajador_numero ? ' (NO. EMPLEADO '.trim($this->trabajador_numero).')' : '');
    }

    /** Al cancelarlo: sus facturas se conservan como historial, pero se pueden volver a usar en otro trámite. */
    public function liberarFacturas(): void
    {
        $this->facturas()->reorder()->update(['uuid_activo' => null]);
    }

    public function editable(): bool
    {
        return in_array($this->estado, self::EDITABLES, true);
    }
}
