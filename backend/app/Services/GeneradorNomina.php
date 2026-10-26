<?php

namespace App\Services;

use App\Http\Controllers\Api\OrdenCompraController;
use App\Models\EgresoRequisicion;
use App\Models\EgresoRequisicionBitacora;
use App\Models\EgresoRequisicionDetalle;
use App\Models\EgresoUnidadAdministrativa;
use App\Models\Nomina;
use App\Models\OrdenCompra;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Al validar Control Presupuestal una nómina (o finiquito o laudo) se genera todo de una vez, en una transacción,
 * por cada unidad administrativa (clave presupuestal) de sus renglones:
 *  1. La requisición, ya aprobada y con su suficiencia validada contra el presupuesto (si alguna partida de
 *     cualquier unidad no alcanza, no se valida nada y se dice cuáles).
 *  2. El registro sin factura que guarda la CLC (folio PN-AÑO-0001), a favor de la cuenta de nómina, la
 *     institución o la persona.
 *  3. La CLC, que entra al trámite ya en Control Presupuestal (la nómina no pasa por el área ni por Recursos
 *     Materiales: ya la revisó Control Presupuestal al validarla).
 */
class GeneradorNomina
{
    public function __construct(private PresupuestoService $presupuesto, private TramiteClc $tramite) {}

    /** @return Collection<int, OrdenCompra> Los registros con su CLC, uno por unidad. */
    public function generar(Nomina $nomina, User $user): Collection
    {
        return DB::transaction(function () use ($nomina, $user) {
            $nomina = Nomina::whereKey($nomina->id)->lockForUpdate()->first();
            if ($nomina->estado !== 'por_validar') {
                throw ValidationException::withMessages(['nomina' => 'Solo se valida una nómina enviada a validar.']);
            }
            $grupos = $nomina->renglones()->get()->groupBy('unidad_clave');
            if ($grupos->isEmpty()) {
                throw ValidationException::withMessages(['nomina' => 'La nómina no tiene renglones.']);
            }

            // Primero todas las requisiciones con su suficiencia: cada una queda comprometida antes de validar la
            // siguiente, así dos unidades no se reparten el mismo disponible.
            $requisiciones = [];
            $faltantes = [];
            foreach ($grupos as $clave => $renglones) {
                $requisicion = $this->requisicion($nomina, $renglones, $user);
                $resultado = $this->presupuesto->validarRequisicion($requisicion->fresh());
                if (! $resultado['suficiente']) {
                    foreach (collect($resultado['partidas'])->where('suficiente', false) as $p) {
                        $faltantes[] = "unidad {$clave}, partida {$p['partida']} (proy. {$p['proyecto']}, fte. {$p['fuente']}): "
                            .($p['existe'] ? 'faltan $'.number_format($p['faltante'], 2) : 'no existe en el presupuesto');
                    }

                    continue;
                }
                $requisicion->update(['suficiencia' => true, 'suficiencia_fecha' => now(), 'suficiencia_user_id' => $user->id]);
                $this->bitacora($requisicion, EgresoRequisicion::COMPROMETIDA, 'Validada contra presupuesto: CON SUFICIENCIA por $'.number_format($resultado['total'], 2).'. Requisición comprometida.');
                $requisiciones[] = $requisicion;
            }
            if ($faltantes) {
                throw ValidationException::withMessages(['presupuesto' => 'Sin suficiencia presupuestal: '.implode('; ', $faltantes).'. Se requiere una modificación presupuestal antes de validar.']);
            }

            $registros = collect();
            foreach ($requisiciones as $requisicion) {
                $registro = $this->registro($nomina, $requisicion, $user);
                app(OrdenCompraController::class)->crearClc($registro, " ({$nomina->nombreTipo()} {$nomina->folio_completo})");
                // Entra directo a Control Presupuestal: queda en su bandeja para enviarla a Sindicatura.
                $registro->refresh()->update(['clc_estado' => 'recibida_cp']);
                $this->tramite->movimiento($registro, 'recibir_cp', 'generada', 'recibida_cp', null, "Validada con {$nomina->folio_completo} por Control Presupuestal.", $user);
                $registros->push($registro);
            }

            $nomina->update(['estado' => 'validado', 'validado_at' => now(), 'validado_user_id' => $user->id, 'observaciones' => null]);

            return $registros;
        });
    }

    private function requisicion(Nomina $nomina, Collection $renglones, User $user): EgresoRequisicion
    {
        $año = (int) now('America/Mexico_City')->format('Y');
        $unidad = EgresoUnidadAdministrativa::find($renglones->first()->id_cat_egreso_unidad_administrativa);
        $prefijo = $unidad?->prefijo ?: 'REQ';
        $folio = (EgresoRequisicion::where('año', $año)->lockForUpdate()->max('folio') ?? 0) + 1;
        $id = (EgresoRequisicion::lockForUpdate()->max('id_tb_egreso_requisicion') ?? 0) + 1;
        $total = round((float) $renglones->sum('importe'), 2);

        $requisicion = EgresoRequisicion::create([
            'id_tb_egreso_requisicion' => $id,
            'id_cat_egreso_requisicion_estado' => 3, // aprobada: la validó Control Presupuestal con la nómina
            'id_cat_egreso_solicitud_tipo' => 1,
            'id_tb_egreso_presupuesto' => 1,
            'id_cat_egreso_unidad_administrativa' => $unidad->id_cat_egreso_unidad_administrativa,
            'id_cat_area_x_nombre_y_puesto_Solicita' => 1,
            'id_cat_area_x_nombre_y_puesto_Autoriza' => 1,
            'id_tb_usuarios' => 1,
            'id_user_laravel' => $nomina->user_id ?? $user->id,
            'folio' => $folio,
            'año' => $año,
            'folio_completo' => $prefijo.'-'.str_pad($folio, 4, '0', STR_PAD_LEFT),
            'solicitado' => now('America/Mexico_City')->toDateString(),
            'observaciones' => mb_substr($nomina->concepto.($nomina->periodo ? " — {$nomina->periodo}" : ''), 0, 3000),
            'sub_total' => $total,
            'isr' => null,
            'iva' => 0,
            'descuento' => 0,
            'total' => $total,
            'monto_requisicion' => $total,
            'extemporanea' => false,
            // Sin factura: la CLC va a favor de la cuenta de nómina, la institución o la persona, con su referencia.
            'beneficiario' => $nomina->aFavorDe(),
            'beneficiario_referencia' => mb_substr($nomina->referencia ?: $nomina->folio_completo, 0, 200),
            'beneficiario_fecha' => $nomina->fecha_pago,
        ]);

        $detalleId = (EgresoRequisicionDetalle::max('id_tb_egreso_requisicion_detalle') ?? 0) + 1;
        foreach ($renglones as $r) {
            EgresoRequisicionDetalle::create([
                'id_tb_egreso_requisicion_detalle' => $detalleId++,
                'id_tb_egreso_requisicion' => $id,
                'id_cat_egreso_producto' => 1,
                'cantidad' => 1,
                'descripcion' => $r->concepto,
                'articulo' => $r->concepto,
                'fuente' => $r->fuente,
                'proyecto' => $r->proyecto,
                'partida' => $r->partida,
                'precio_unitario' => $r->importe,
                'sub_total' => $r->importe,
                'iva' => 0,
                'iva_factor' => 0,
                'descuento' => 0,
                'total' => $r->importe,
                'id_tb_egreso_presupuesto_detalle' => null,
                'id_tb_egreso_presupuesto' => null,
            ]);
        }

        $this->bitacora($requisicion, 3, "Requisición generada de {$nomina->nombreTipo()} {$nomina->folio_completo} ({$renglones->count()} concepto(s)), validada por {$user->name}.");

        return $requisicion;
    }

    /** El registro que guarda la CLC (folio PN-AÑO-0001), sin proveedor. */
    private function registro(Nomina $nomina, EgresoRequisicion $requisicion, User $user): OrdenCompra
    {
        $año = (int) now('America/Mexico_City')->format('Y');
        $folio = (OrdenCompra::where('sin_factura', true)->where('folio_completo', 'like', "PN-{$año}-%")->lockForUpdate()->max('folio') ?? 0) + 1;

        $registro = OrdenCompra::create([
            'id_tb_egreso_requisicion' => $requisicion->id_tb_egreso_requisicion,
            'proveedor_id' => null,
            'sin_factura' => true,
            'nomina_id' => $nomina->id,
            'folio' => $folio,
            'año' => $año,
            'folio_completo' => sprintf('PN-%d-%04d', $año, $folio),
            'fecha' => now('America/Mexico_City')->toDateString(),
            'importe' => round((float) $requisicion->total, 2),
            'observaciones' => "{$nomina->nombreTipo()} {$nomina->folio_completo}".($nomina->periodo ? " ({$nomina->periodo})" : '').'.',
            'user_id' => $user->id,
        ]);

        $this->bitacora($requisicion, EgresoRequisicion::CON_FACTURA,
            "{$nomina->nombreTipo()} {$nomina->folio_completo}: registro {$registro->folio_completo} por $".number_format($registro->importe, 2)." a favor de {$nomina->aFavorDe()}.");

        return $registro;
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
