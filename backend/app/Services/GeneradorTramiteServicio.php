<?php

namespace App\Services;

use App\Http\Controllers\Api\OrdenCompraController;
use App\Models\EgresoRequisicion;
use App\Models\EgresoRequisicionBitacora;
use App\Models\EgresoRequisicionDetalle;
use App\Models\OrdenCompra;
use App\Models\TramiteServicio;
use App\Models\TramiteServicioFactura;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Al validar un trámite de servicios se genera todo de una vez, en una transacción:
 *  1. La requisición, ya aprobada, con un renglón por factura y su suficiencia validada contra el presupuesto
 *     (si alguna partida no alcanza, no se valida y se dice cuál).
 *  2. La orden de compra con las facturas ya cargadas (no se sube XML aparte).
 *  3. La CLC, con un renglón por factura aunque sean de la misma partida.
 */
class GeneradorTramiteServicio
{
    public function __construct(private PresupuestoService $presupuesto) {}

    public function generar(TramiteServicio $tramite, User $user): EgresoRequisicion
    {
        return DB::transaction(function () use ($tramite, $user) {
            $tramite = TramiteServicio::whereKey($tramite->id)->lockForUpdate()->first();
            if ($tramite->estado !== 'por_validar') {
                throw ValidationException::withMessages(['tramite' => 'Solo se valida un trámite enviado a validar.']);
            }
            $facturas = $tramite->facturas()->get();
            $proveedor = $tramite->proveedor();
            if ($impedimento = $tramite->impedimentoProveedor()) {
                throw ValidationException::withMessages(['proveedor' => $impedimento]);
            }

            $requisicion = $this->requisicion($tramite, $facturas, $user);

            // Suficiencia automática: se valida contra el presupuesto en el momento.
            $resultado = $this->presupuesto->validarRequisicion($requisicion->fresh());
            if (! $resultado['suficiente']) {
                $faltan = collect($resultado['partidas'])->where('suficiente', false)
                    ->map(fn ($p) => "{$p['partida']} (proy. {$p['proyecto']}, fte. {$p['fuente']}): faltan $".number_format($p['faltante'], 2))
                    ->implode('; ');
                throw ValidationException::withMessages(['presupuesto' => "Sin suficiencia presupuestal: {$faltan}. Se requiere una modificación presupuestal antes de validar."]);
            }
            $requisicion->update(['suficiencia' => true, 'suficiencia_fecha' => now(), 'suficiencia_user_id' => $user->id]);
            $this->bitacora($requisicion, EgresoRequisicion::COMPROMETIDA, 'Validada contra presupuesto: CON SUFICIENCIA por $'.number_format($resultado['total'], 2).'. Requisición comprometida.');

            $orden = $this->orden($tramite, $requisicion, $facturas, $proveedor, $user);
            app(OrdenCompraController::class)->crearClc($orden, " (trámite de servicios {$tramite->folio_completo}; un renglón por factura)");

            $tramite->update(['estado' => 'validado', 'validado_at' => now(), 'validado_user_id' => $user->id,
                'id_tb_egreso_requisicion' => $requisicion->id_tb_egreso_requisicion, 'observaciones' => null]);

            return $requisicion->fresh();
        });
    }

    private function requisicion(TramiteServicio $tramite, $facturas, User $user): EgresoRequisicion
    {
        $año = (int) now('America/Mexico_City')->format('Y');
        $prefijo = $tramite->unidadAdministrativa?->prefijo ?: 'REQ';
        $folio = (EgresoRequisicion::where('año', $año)->lockForUpdate()->max('folio') ?? 0) + 1;
        $id = (EgresoRequisicion::lockForUpdate()->max('id_tb_egreso_requisicion') ?? 0) + 1;

        $base = round($facturas->sum(fn (TramiteServicioFactura $f) => $f->base()), 2);
        $total = round($facturas->sum(fn (TramiteServicioFactura $f) => $f->importe()), 2);

        $requisicion = EgresoRequisicion::create([
            'id_tb_egreso_requisicion' => $id,
            'id_cat_egreso_requisicion_estado' => 3, // aprobada: la validó Recursos Materiales con las facturas
            'id_cat_egreso_solicitud_tipo' => 1,
            'id_tb_egreso_presupuesto' => 1,
            'id_cat_egreso_unidad_administrativa' => $tramite->id_cat_egreso_unidad_administrativa,
            'id_cat_area_x_nombre_y_puesto_Solicita' => 1,
            'id_cat_area_x_nombre_y_puesto_Autoriza' => 1,
            'id_tb_usuarios' => 1,
            'id_user_laravel' => $tramite->user_id ?? $user->id,
            'folio' => $folio,
            'año' => $año,
            'folio_completo' => $prefijo.'-'.str_pad($folio, 4, '0', STR_PAD_LEFT),
            'solicitado' => now('America/Mexico_City')->toDateString(),
            'observaciones' => $tramite->concepto,
            'sub_total' => $base,
            'isr' => null,
            'iva' => round($total - $base, 2),
            'descuento' => 0,
            'total' => $total,
            'monto_requisicion' => $total,
            'extemporanea' => false,
        ]);

        $detalleId = (EgresoRequisicionDetalle::max('id_tb_egreso_requisicion_detalle') ?? 0) + 1;
        foreach ($facturas as $f) {
            $base = $f->base();
            $importe = $f->importe();
            EgresoRequisicionDetalle::create([
                'id_tb_egreso_requisicion_detalle' => $detalleId++,
                'id_tb_egreso_requisicion' => $id,
                'id_cat_egreso_producto' => 1,
                'cantidad' => 1,
                'descripcion' => "{$f->concepto} — Factura ".($f->serieFolio() ?? $f->uuid),
                'articulo' => $f->concepto,
                'fuente' => $f->fuente,
                'proyecto' => $f->proyecto,
                'partida' => $f->partida,
                'precio_unitario' => $base,
                'sub_total' => $base,
                'iva' => round($importe - $base, 2),
                'iva_factor' => $base > 0 ? round(($importe - $base) / $base, 4) : 0,
                'descuento' => 0,
                'total' => $importe,
                'id_tb_egreso_presupuesto_detalle' => null,
                'id_tb_egreso_presupuesto' => null,
            ]);
        }

        $this->bitacora($requisicion, 3, "Requisición generada del trámite de servicios {$tramite->folio_completo} ({$facturas->count()} factura(s)), validado por {$user->name}.");

        return $requisicion;
    }

    private function orden(TramiteServicio $tramite, EgresoRequisicion $requisicion, $facturas, $proveedor, User $user): OrdenCompra
    {
        $año = (int) now('America/Mexico_City')->format('Y');
        $folio = (OrdenCompra::where('sin_factura', false)->where('año', $año)->lockForUpdate()->max('folio') ?? 0) + 1;
        $primera = $facturas->first();

        $orden = OrdenCompra::create([
            'id_tb_egreso_requisicion' => $requisicion->id_tb_egreso_requisicion,
            'proveedor_id' => $proveedor->id,
            'tramite_servicio_id' => $tramite->id,
            'folio' => $folio,
            'año' => $año,
            'folio_completo' => sprintf('OC-%d-%04d', $año, $folio),
            'fecha' => now('America/Mexico_City')->toDateString(),
            'importe' => round((float) $requisicion->total, 2),
            'observaciones' => "Trámite de servicios {$tramite->folio_completo}: {$facturas->count()} factura(s).",
            'user_id' => $user->id,
            // Resumen de las facturas (el detalle está en el trámite). xml_uuid queda vacío: son varias.
            'xml_rfc_emisor' => $primera?->rfc_emisor,
            'xml_nombre_emisor' => $primera?->nombre_emisor,
            'xml_fecha' => $primera?->fecha,
            'xml_subtotal' => round($facturas->sum('subtotal'), 2),
            'xml_total' => round($facturas->sum('total'), 2),
            'xml_isr' => round($facturas->sum('isr'), 2),
            'xml_cargado_at' => now(),
            'xml_user_id' => $user->id,
            'sat_estado' => $facturas->every(fn ($f) => $f->sat_estado === EstadoCfdiSat::VIGENTE) ? EstadoCfdiSat::VIGENTE : null,
            'sat_verificado_at' => $facturas->max('sat_verificado_at'),
        ]);

        $this->bitacora($requisicion, EgresoRequisicion::CON_FACTURA,
            "Orden de compra {$orden->folio_completo} generada para {$proveedor->nombre} por $".number_format($orden->importe, 2)
            ." con {$facturas->count()} factura(s) del trámite {$tramite->folio_completo}.");

        return $orden;
    }

    private function bitacora(EgresoRequisicion $requisicion, int $nuevo, string $observaciones): void
    {
        $anterior = (int) $requisicion->id_cat_egreso_requisicion_estado;
        if ($anterior !== $nuevo) {
            $requisicion->update(['id_cat_egreso_requisicion_estado' => $nuevo]);
        }
        EgresoRequisicionBitacora::create([
            'id_tb_egreso_requisicion_bitacora' => (EgresoRequisicionBitacora::max('id_tb_egreso_requisicion_bitacora') ?? 0) + 1,
            'id_tb_egreso_requisicion' => $requisicion->id_tb_egreso_requisicion,
            'id_cat_egreso_requisicion_estado_anterior' => $anterior,
            'id_cat_egreso_requisicion_estado_nuevo' => $nuevo,
            'id_tb_usuarios' => 1,
            'observaciones' => $observaciones,
        ]);
    }
}
