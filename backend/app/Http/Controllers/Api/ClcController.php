<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClcRelacion;
use App\Models\EgresoUnidadAdministrativa;
use App\Models\OrdenCompra;
use App\Services\TramiteClc;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Trámite de CLC: bandejas por área para recibir, enviar (relación), devolver y pagar (SIEG: ClcController,
 * respondiendo JSON). Las reglas de cada paso están en App\Services\TramiteClc.
 */
class ClcController extends Controller
{
    /**
     * Control Presupuestal y Admin ven todas las CLC y todas las bandejas. Cada área (Recursos
     * Materiales, Sindicatura, Egresos) ve las CLC que están en su bandeja; el área solicitante, el
     * seguimiento de las de su secretaría.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $veTodo = TramiteClc::veTodo($user);
        $propias = EgresoUnidadAdministrativa::idsMismaClave($user->id_cat_egreso_unidad_administrativa);
        $estadosBandeja = TramiteClc::estadosDeSusBandejas($user);
        // Quien participa en el trámite (RM, CP, Sindicatura, Egresos) sigue todas las CLC, también las
        // pagadas; el área solicitante, solo las de su secretaría.
        $todas = $veTodo || $user->canAny(['clc-recursos-materiales', 'clc-control-presupuestal', 'clc-sindicatura', 'clc-egresos']);

        $clcs = OrdenCompra::query()->whereNotNull('clc_folio_completo')
            ->when(! $todas, fn ($q) => $q->where(fn ($w) => $w
                ->whereIn('clc_estado', $estadosBandeja ?: ['__ninguno__'])
                ->orWhereHas('requisicion', fn ($r) => $r->whereIn('id_cat_egreso_unidad_administrativa', $propias ?: [0]))))
            ->with(['proveedor:id,nombre', 'requisicion:id_tb_egreso_requisicion,folio_completo,id_cat_egreso_unidad_administrativa,observaciones',
                'requisicion.catEgresoUnidadAdministrativa:id_cat_egreso_unidad_administrativa,clave,nombre', 'relacionClc:id,folio_completo,origen,destino',
                'movimientosClc' => fn ($q) => $q->where('accion', 'devolver')->reorder()->latest('id')])
            ->orderByDesc('clc_generada_at')
            ->get()
            ->map(fn (OrdenCompra $o) => [
                'id' => $o->id,
                'clc' => $o->clc_folio_completo,
                'clc_fecha' => $o->clc_fecha?->toDateString(),
                'estado' => $o->clc_estado,
                'estado_texto' => TramiteClc::descripcion($o->clc_estado),
                'orden' => $o->folio_completo,
                'importe' => (float) $o->importe,
                // ISR retenido en la factura: no cambia el importe; al proveedor se le paga importe − ISR.
                'isr' => (float) ($o->xml_isr ?? 0),
                'neto' => $o->netoAPagar(),
                'proveedor' => $o->proveedor?->nombre,
                'requisicion_id' => $o->id_tb_egreso_requisicion,
                'requisicion' => $o->requisicion?->folio_completo,
                'concepto' => $o->requisicion?->observaciones,
                'unidad' => $o->requisicion?->catEgresoUnidadAdministrativa
                    ? trim($o->requisicion->catEgresoUnidadAdministrativa->clave.' '.$o->requisicion->catEgresoUnidadAdministrativa->nombre)
                    : null,
                'relacion' => $o->relacionClc?->folio_completo,
                'relacion_id' => $o->clc_relacion_id,
                'pago_fecha' => $o->pago_fecha?->toDateString(),
                'pago_forma' => $o->pago_forma,
                'transferencia' => $o->transferencia_numero,
                'cancelacion' => $o->clc_cancelada_at ? $o->clc_motivo_cancelacion : null,
                'sat_estado' => $o->sat_estado,
                'sat_estatus_cancelacion' => $o->sat_estatus_cancelacion,
                'sat_efos' => (bool) $o->sat_efos,
                'sat_verificado_at' => $o->sat_verificado_at?->toIso8601String(),
                'devolucion' => $o->clc_estado === 'devuelta' ? $o->movimientosClc->first()?->observaciones : null,
                // Entra en "Seguimiento": todas para quien participa en el trámite; las de su secretaría para los demás.
                'seguimiento' => $todas || in_array((int) $o->requisicion?->id_cat_egreso_unidad_administrativa, $propias, true),
            ]);
        $verRelaciones = $veTodo || $user->can('clc-sindicatura');

        return response()->json([
            'clcs' => $clcs,
            'veTodo' => $veTodo,
            'seguimientoTodas' => $todas,
            'verRelaciones' => $verRelaciones,
            'relaciones' => ! $verRelaciones ? [] : ClcRelacion::withCount('ordenes')->with('usuario:id,name,titulo')->latest('id')->limit(100)->get()
                ->map(fn (ClcRelacion $r) => [
                    'id' => $r->id,
                    'folio' => $r->folio_completo,
                    'origen' => TramiteClc::AREAS[$r->origen] ?? $r->origen,
                    'destino' => TramiteClc::AREAS[$r->destino] ?? $r->destino,
                    'clcs' => $r->ordenes_count,
                    'usuario' => $r->usuario?->name,
                    'fecha' => $r->created_at?->toIso8601String(),
                ]),
            'estados' => collect(TramiteClc::ESTADOS)->map(fn ($e) => $e[0]),
            'acciones' => collect(TramiteClc::ACCIONES)->map(fn ($a) => [
                'etiqueta' => $a['etiqueta'],
                'permiso' => $a['permiso'],
                'envio' => isset($a['envio']) ? TramiteClc::AREAS[$a['envio'][1]] : null,
                'relacion' => $a['relacion'] ?? false,
                'recibe' => $a['recibe'] ?? false,
                'observaciones' => $a['observaciones'] ?? false,
                'pago' => $a['pago'] ?? false,
                'a' => TramiteClc::descripcion($a['a']),
            ]),
            'formasPago' => OrdenCompra::FORMAS_PAGO,
        ]);
    }

    public function accion(Request $request, TramiteClc $tramite): JsonResponse
    {
        $validated = $request->validate([
            'accion' => 'required|string|in:'.implode(',', array_keys(TramiteClc::ACCIONES)),
            'ordenes' => 'required|array|min:1',
            'ordenes.*' => 'integer',
            'observaciones' => 'nullable|string|max:2000',
            'pago_fecha' => 'nullable|date',
            'pago_forma' => 'nullable|string',
            'pago_referencia' => 'nullable|string|max:100',
        ], ['ordenes.required' => 'Selecciona al menos una CLC.']);

        $relacion = $tramite->ejecutar($validated['accion'], $validated['ordenes'], $request->user(), $validated);
        $cuantas = count(array_unique($validated['ordenes']));
        $mensaje = TramiteClc::ACCIONES[$validated['accion']]['etiqueta'].": {$cuantas} CLC.";

        return response()->json([
            'message' => $relacion ? "{$mensaje} Relación {$relacion->folio_completo} generada." : $mensaje,
            'relacion' => $relacion ? ['id' => $relacion->id, 'folio' => $relacion->folio_completo] : null,
        ]);
    }

    /** Documento de la relación: las CLC que se envían de un área a otra (sin O.C., requisición ni firmas). */
    public function relacionPdf(Request $request, ClcRelacion $relacion)
    {
        abort_unless(TramiteClc::veTodo($request->user()) || $request->user()->can('clc-sindicatura'), 403);

        $relacion->load(['ordenes.proveedor:id,nombre', 'ordenes.requisicion:id_tb_egreso_requisicion,id_cat_egreso_unidad_administrativa',
            'ordenes.requisicion.catEgresoUnidadAdministrativa:id_cat_egreso_unidad_administrativa,clave,nombre']);

        $pdf = Pdf::loadView('clc.relacion', ['relacion' => $relacion, 'areas' => TramiteClc::AREAS])->setPaper('letter');
        $pdf->render();
        $canvas = $pdf->getDomPDF()->getCanvas();
        $canvas->page_text($canvas->get_width() - 110, $canvas->get_height() - 28, 'Página {PAGE_NUM} de {PAGE_COUNT}',
            $pdf->getDomPDF()->getFontMetrics()->getFont('Helvetica'), 7);

        return $pdf->stream("{$relacion->folio_completo}.pdf");
    }
}
