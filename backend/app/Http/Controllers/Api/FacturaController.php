<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EgresoUnidadAdministrativa;
use App\Models\OrdenCompra;
use App\Services\EstadoCfdiSat;
use App\Services\TramiteClc;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Facturas (CFDI) cargadas a las órdenes de compra: emisor, monto, folio, fecha, su validación en el SAT
 * y en qué va su trámite (requisición, orden de compra y CLC). Incluye las canceladas con su orden.
 * SIEG: FacturaController, respondiendo JSON.
 */
class FacturaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $filtros = $request->only('buscar', 'sat', 'vigencia', 'desde', 'hasta');

        // Quien participa en el trámite ve todas; el área solicitante, las de su secretaría.
        $todas = TramiteClc::veTodo($user)
            || $user->canAny(['clc-recursos-materiales', 'clc-control-presupuestal', 'clc-sindicatura', 'clc-egresos', 'ordenes-compra-create']);
        $propias = EgresoUnidadAdministrativa::idsMismaClave($user->id_cat_egreso_unidad_administrativa);

        $query = OrdenCompra::query()
            ->where(fn ($q) => $q->whereNotNull('xml_uuid')->orWhereNotNull('xml_uuid_cancelado'))
            ->when(! $todas, fn ($q) => $q->whereHas('requisicion', fn ($r) => $r->whereIn('id_cat_egreso_unidad_administrativa', $propias ?: [0])))
            ->when(filled($filtros['buscar'] ?? null), function (Builder $q) use ($filtros) {
                foreach (preg_split('/\s+/', trim($filtros['buscar'])) as $termino) {
                    $like = "%{$termino}%";
                    $q->where(fn ($w) => $w->where('xml_nombre_emisor', 'like', $like)->orWhere('xml_rfc_emisor', 'like', $like)
                        ->orWhere('xml_folio', 'like', $like)->orWhere('xml_serie', 'like', $like)
                        ->orWhere('xml_uuid', 'like', $like)->orWhere('xml_uuid_cancelado', 'like', $like)
                        ->orWhere('folio_completo', 'like', $like)->orWhere('clc_folio_completo', 'like', $like)
                        ->orWhereHas('requisicion', fn ($r) => $r->where('folio_completo', 'like', $like)));
                }
            })
            ->when(($filtros['sat'] ?? null) === 'sin_verificar', fn ($q) => $q->whereNull('sat_estado'))
            ->when(in_array($filtros['sat'] ?? null, [EstadoCfdiSat::VIGENTE, EstadoCfdiSat::CANCELADO, EstadoCfdiSat::NO_ENCONTRADO], true),
                fn ($q) => $q->where('sat_estado', $filtros['sat']))
            ->when(($filtros['vigencia'] ?? null) === 'vigentes', fn ($q) => $q->whereNotNull('xml_uuid'))
            ->when(($filtros['vigencia'] ?? null) === 'canceladas', fn ($q) => $q->whereNull('xml_uuid'))
            ->when(filled($filtros['desde'] ?? null), fn ($q) => $q->whereDate('xml_fecha', '>=', $filtros['desde']))
            ->when(filled($filtros['hasta'] ?? null), fn ($q) => $q->whereDate('xml_fecha', '<=', $filtros['hasta']));

        $totales = (clone $query)->selectRaw('COUNT(*) as facturas, COALESCE(SUM(xml_total), 0) as total')->first();

        $facturas = $query
            ->with(['requisicion:id_tb_egreso_requisicion,folio_completo,id_cat_egreso_requisicion_estado,id_cat_egreso_unidad_administrativa',
                'requisicion.catEgresoRequisicionEstado', 'requisicion.catEgresoUnidadAdministrativa:id_cat_egreso_unidad_administrativa,clave,nombre'])
            ->orderByDesc('xml_cargado_at')->orderByDesc('id')
            ->paginate(25)
            ->through(fn (OrdenCompra $o) => [
                'id' => $o->id,
                'uuid' => $o->xml_uuid ?? $o->xml_uuid_cancelado,
                'cancelada' => $o->xml_uuid === null,
                'emisor' => $o->xml_nombre_emisor,
                'rfc' => $o->xml_rfc_emisor,
                'folio' => trim(($o->xml_serie ?? '').' '.($o->xml_folio ?? '')) ?: null,
                'fecha' => $o->xml_fecha?->toIso8601String(),
                'cargada' => $o->xml_cargado_at?->toIso8601String(),
                'total' => (float) $o->xml_total,
                'isr' => (float) ($o->xml_isr ?? 0),
                'sat_estado' => $o->sat_estado,
                'sat_estatus_cancelacion' => $o->sat_estatus_cancelacion,
                'sat_efos' => (bool) $o->sat_efos,
                'sat_verificado_at' => $o->sat_verificado_at?->toIso8601String(),
                'requisicion_id' => $o->id_tb_egreso_requisicion,
                'requisicion' => $o->requisicion?->folio_completo,
                'requisicion_estado' => $o->requisicion?->catEgresoRequisicionEstado?->descripcion,
                'unidad' => $o->requisicion?->catEgresoUnidadAdministrativa
                    ? trim($o->requisicion->catEgresoUnidadAdministrativa->clave.' '.$o->requisicion->catEgresoUnidadAdministrativa->nombre) : null,
                'orden' => $o->folio_completo,
                'orden_cancelada' => $o->cancelada_at !== null,
                'clc' => $o->clc_folio_completo,
                'clc_estado' => $o->clc_folio_completo ? TramiteClc::descripcion($o->clc_estado) : null,
                'clc_clave' => $o->clc_estado,
                'xml' => $o->xml_path !== null,
            ]);

        return response()->json([
            ...$facturas->toArray(),
            'totales' => ['facturas' => (int) $totales->facturas, 'total' => round((float) $totales->total, 2)],
        ]);
    }
}
