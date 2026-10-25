<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/** Recepción de un vehículo en el taller (folio MV-AÑO-0001) y su requisición. */
class VehiculoMantenimiento extends Model
{
    use Auditable;

    public const TIPOS = ['preventivo' => 'Preventivo', 'correctivo' => 'Correctivo'];

    public const ESTADOS = ['en_taller' => 'En taller', 'entregado' => 'Entregado'];

    /** Únicas partidas permitidas en la requisición de un mantenimiento vehicular. */
    public const PARTIDAS = ['2961', '2981', '3551', '3571', '2614'];

    /**
     * El taller (vehiculos-mantenimiento) hace en las requisiciones de mantenimiento lo que en las demás hace
     * Recursos Materiales: enviarla, recibirla, cotizarla, generar la orden de compra y cargar la factura.
     * La aprobación (Admin), la suficiencia (Control Presupuestal) y la CLC (el área de resguardo) no cambian.
     */
    public static function delTaller(?User $user, int|string|null $idRequisicion): bool
    {
        return $user !== null && $idRequisicion !== null && $user->can('vehiculos-mantenimiento')
            && static::where('id_tb_egreso_requisicion', $idRequisicion)->exists();
    }

    protected $table = 'vehiculo_mantenimientos';

    protected $fillable = [
        'vehiculo_id', 'folio', 'año', 'folio_completo', 'id_cat_egreso_unidad_administrativa', 'departamento_id',
        'fecha_recepcion', 'kilometraje', 'tipo', 'descripcion', 'entrega', 'user_id', 'estado',
        'fecha_entrega', 'recibe_entrega', 'observaciones_entrega', 'id_tb_egreso_requisicion',
    ];

    protected function casts(): array
    {
        return ['fecha_recepcion' => 'date:Y-m-d', 'fecha_entrega' => 'date:Y-m-d', 'kilometraje' => 'integer'];
    }

    public function vehiculo()
    {
        return $this->belongsTo(Vehiculo::class);
    }

    public function unidadAdministrativa()
    {
        return $this->belongsTo(EgresoUnidadAdministrativa::class, 'id_cat_egreso_unidad_administrativa', 'id_cat_egreso_unidad_administrativa');
    }

    public function departamento()
    {
        return $this->belongsTo(Departamento::class);
    }

    public function requisicion()
    {
        return $this->belongsTo(EgresoRequisicion::class, 'id_tb_egreso_requisicion', 'id_tb_egreso_requisicion');
    }

    public function usuario()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Costo del mantenimiento y de dónde sale: el importe de su orden de compra (pagado o comprometido); si aún no
     * hay orden, el total de la requisición (estimado); cero si no tiene requisición o se canceló. Requiere
     * requisicion.ordenCompra cargado.
     *
     * @return array{0: float, 1: string, 2: ?OrdenCompra}
     */
    public function costo(): array
    {
        $req = $this->requisicion;
        // Una orden cancelada no cuenta: si la requisición sigue viva, vale su total (estimado).
        $orden = $req?->ordenCompra && ! $req->ordenCompra->cancelada_at ? $req->ordenCompra : null;
        [$costo, $situacion] = match (true) {
            $req === null => [0.0, 'sin_requisicion'],
            $req->cancelada_at !== null => [0.0, 'cancelado'],
            $orden && $orden->pago_registrado_at => [(float) $orden->importe, 'pagado'],
            $orden !== null => [(float) $orden->importe, 'comprometido'],
            default => [(float) $req->total, 'estimado'],
        };

        return [round($costo, 2), $situacion, $orden];
    }

    /** Texto corto del vehículo para el concepto de la requisición y las listas. */
    public function textoVehiculo(): string
    {
        $v = $this->vehiculo;

        return trim('ECO '.$v?->numero_economico.($v?->placas ? " · PLACAS {$v->placas}" : '').' · '.trim(($v?->marca ?? '').' '.($v?->linea ?? '').' '.($v?->modelo ?? '')));
    }
}
