<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EgresoRequisicion;
use App\Models\EgresoUnidadAdministrativa;
use App\Models\OrdenCompra;
use App\Models\TramiteServicio;
use App\Services\EstadoCfdiSat;
use App\Services\TramiteClc;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Todas las facturas (CFDI) cargadas al sistema, con el tipo de trámite al que pertenecen: las de las órdenes de
 * compra (también las canceladas con su orden), las de los trámites (servicios, fondos revolventes, bitácoras de
 * combustible y mensualidades de contrato) y las de combustible que esperan el Excel del proveedor. De cada una:
 * emisor, monto, folio, fecha, su validación en el SAT y en qué va su trámite (requisición, orden de compra y CLC).
 * SIEG: FacturaController (solo las de las órdenes), respondiendo JSON.
 */
class FacturaController extends Controller
{
    /** Tipo de trámite de cada factura (columna y filtro). */
    public const TIPOS = [
        'orden' => 'Orden de compra',
        'servicio' => 'Trámite de servicios',
        'fondo' => 'Fondo revolvente',
        'combustible' => 'Bitácora de combustible',
        'contrato' => 'Mensualidad de contrato',
        'por_asignar' => 'Combustible (por asignar)',
    ];

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $filtros = $request->only('buscar', 'sat', 'vigencia', 'desde', 'hasta', 'tipo');

        // Quien participa en el trámite ve todas; el área solicitante, las de su secretaría. Las de combustible por
        // asignar (aún sin secretaría), quien ve todas y la unidad de combustibles.
        $todas = TramiteClc::veTodo($user)
            || $user->canAny(['clc-recursos-materiales', 'clc-control-presupuestal', 'clc-sindicatura', 'clc-egresos', 'ordenes-compra-create']);
        $porAsignar = $todas || $user->can('combustible-cargar');
        $propias = EgresoUnidadAdministrativa::idsMismaClave($user->id_cat_egreso_unidad_administrativa) ?: [0];

        $query = DB::query()->fromSub($this->union($porAsignar), 'f')
            ->when(! $todas, fn (Builder $q) => $q->where(fn ($w) => $w->whereIn('unidad_id', $propias)
                ->when($porAsignar, fn ($w) => $w->orWhere('origen', 'por_asignar'))))
            ->when(filled($filtros['buscar'] ?? null), function (Builder $q) use ($filtros) {
                foreach (preg_split('/\s+/', trim($filtros['buscar'])) as $termino) {
                    $like = "%{$termino}%";
                    $q->where(fn ($w) => collect(['emisor', 'rfc', 'folio', 'serie', 'uuid', 'orden_folio', 'clc_folio', 'requisicion_folio', 'tramite_folio'])
                        ->each(fn ($c) => $w->orWhere($c, 'like', $like)));
                }
            })
            ->when(array_key_exists($filtros['tipo'] ?? '', self::TIPOS), fn ($q) => $q->where('tipo', $filtros['tipo']))
            ->when(($filtros['sat'] ?? null) === 'sin_verificar', fn ($q) => $q->whereNull('sat_estado'))
            ->when(in_array($filtros['sat'] ?? null, [EstadoCfdiSat::VIGENTE, EstadoCfdiSat::CANCELADO, EstadoCfdiSat::NO_ENCONTRADO], true),
                fn ($q) => $q->where('sat_estado', $filtros['sat']))
            ->when(($filtros['vigencia'] ?? null) === 'vigentes', fn ($q) => $q->where('cancelada', 0))
            ->when(($filtros['vigencia'] ?? null) === 'canceladas', fn ($q) => $q->where('cancelada', 1))
            ->when(filled($filtros['desde'] ?? null), fn ($q) => $q->whereDate('fecha', '>=', $filtros['desde']))
            ->when(filled($filtros['hasta'] ?? null), fn ($q) => $q->whereDate('fecha', '<=', $filtros['hasta']));

        $totales = (clone $query)->selectRaw('COUNT(*) as facturas, COALESCE(SUM(total), 0) as total')->first();
        $pagina = $query->orderByDesc('cargada')->orderByDesc('id')->paginate(25);

        // Requisición, secretaría y orden / CLC de la página, de una vez.
        $filas = collect($pagina->items());
        $requisiciones = EgresoRequisicion::with('catEgresoRequisicionEstado')->whereIn('id_tb_egreso_requisicion', $filas->pluck('requisicion_id')->filter()->unique())
            ->get(['id_tb_egreso_requisicion', 'folio_completo', 'id_cat_egreso_requisicion_estado'])->keyBy('id_tb_egreso_requisicion');
        $unidades = EgresoUnidadAdministrativa::whereIn('id_cat_egreso_unidad_administrativa', $filas->pluck('unidad_id')->filter()->unique())
            ->get(['id_cat_egreso_unidad_administrativa', 'clave', 'nombre'])->keyBy('id_cat_egreso_unidad_administrativa');
        $ordenes = OrdenCompra::whereIn('id', $filas->pluck('orden_id')->filter()->unique())
            ->get(['id', 'folio_completo', 'cancelada_at', 'clc_folio_completo', 'clc_estado'])->keyBy('id');

        return response()->json([
            ...$pagina->through(function ($f) use ($requisiciones, $unidades, $ordenes) {
                $req = $requisiciones->get($f->requisicion_id);
                $unidad = $unidades->get($f->unidad_id);
                $orden = $ordenes->get($f->orden_id);

                return [
                    'clave' => "{$f->origen}-{$f->id}",
                    'origen' => $f->origen, // orden | tramite | por_asignar
                    'id' => (int) $f->id,
                    'tipo' => $f->tipo,
                    'tipo_texto' => self::TIPOS[$f->tipo] ?? $f->tipo,
                    'uuid' => $f->uuid,
                    'cancelada' => (bool) $f->cancelada,
                    'emisor' => $f->emisor,
                    'rfc' => $f->rfc,
                    'folio' => trim(($f->serie ?? '').' '.($f->folio ?? '')) ?: null,
                    'fecha' => $f->fecha ? date(DATE_ATOM, strtotime($f->fecha)) : null,
                    'cargada' => $f->cargada ? date(DATE_ATOM, strtotime($f->cargada)) : null,
                    'total' => (float) $f->total,
                    'isr' => (float) ($f->isr ?? 0),
                    'sat_estado' => $f->sat_estado,
                    'sat_estatus_cancelacion' => $f->sat_estatus_cancelacion,
                    'sat_efos' => (bool) $f->sat_efos,
                    'sat_verificado_at' => $f->sat_verificado_at ? date(DATE_ATOM, strtotime($f->sat_verificado_at)) : null,
                    'tramite_id' => $f->tramite_id ? (int) $f->tramite_id : null,
                    'tramite' => $f->tramite_folio,
                    'contrato_id' => $f->contrato_id ? (int) $f->contrato_id : null,
                    'requisicion_id' => $f->requisicion_id ? (int) $f->requisicion_id : null,
                    'requisicion' => $req?->folio_completo,
                    'requisicion_estado' => $req?->catEgresoRequisicionEstado?->descripcion,
                    'unidad' => $unidad ? trim($unidad->clave.' '.$unidad->nombre) : null,
                    'orden_id' => $orden?->id,
                    'orden' => $orden?->folio_completo,
                    'orden_cancelada' => $orden?->cancelada_at !== null,
                    'clc' => $orden?->clc_folio_completo,
                    'clc_estado' => $orden?->clc_folio_completo ? TramiteClc::descripcion($orden->clc_estado) : null,
                    'clc_clave' => $orden?->clc_estado,
                    'xml' => (bool) $f->xml,
                ];
            })->toArray(),
            'totales' => ['facturas' => (int) $totales->facturas, 'total' => round((float) $totales->total, 2)],
            'tipos' => self::TIPOS,
        ]);
    }

    /** Las tres fuentes de facturas con las mismas columnas. */
    private function union(bool $porAsignar): Builder
    {
        // Órdenes de compra con su factura (las de los trámites no la llevan: sus facturas están en el trámite).
        $ordenes = DB::table('ordenes_compra as o')
            ->leftJoin('tb_egreso_requisicion as r', 'r.id_tb_egreso_requisicion', '=', 'o.id_tb_egreso_requisicion')
            ->where(fn ($q) => $q->whereNotNull('o.xml_uuid')->orWhereNotNull('o.xml_uuid_cancelado'))
            ->selectRaw("'orden' as origen, o.id, 'orden' as tipo, COALESCE(o.xml_uuid, o.xml_uuid_cancelado) as uuid,
                CASE WHEN o.xml_uuid IS NULL THEN 1 ELSE 0 END as cancelada, o.xml_nombre_emisor as emisor, o.xml_rfc_emisor as rfc,
                o.xml_serie as serie, o.xml_folio as folio, o.xml_fecha as fecha, o.xml_cargado_at as cargada, o.xml_total as total, o.xml_isr as isr,
                o.sat_estado, o.sat_estatus_cancelacion, o.sat_efos, o.sat_verificado_at, r.id_cat_egreso_unidad_administrativa as unidad_id,
                o.id_tb_egreso_requisicion as requisicion_id, o.id as orden_id, NULL as tramite_id, NULL as tramite_folio, NULL as contrato_id,
                o.folio_completo as orden_folio, o.clc_folio_completo as clc_folio, r.folio_completo as requisicion_folio,
                CASE WHEN o.xml_path IS NULL THEN 0 ELSE 1 END as xml");

        // Facturas de los trámites; las de un trámite cancelado quedan como canceladas (ya se pueden volver a usar).
        $ordenDelTramite = '(SELECT MAX(oc.id) FROM ordenes_compra oc WHERE oc.tramite_servicio_id = t.id)';
        $tramites = DB::table('tramite_servicio_facturas as f')
            ->join('tramites_servicio as t', 't.id', '=', 'f.tramite_servicio_id')
            ->leftJoin('tb_egreso_requisicion as r', 'r.id_tb_egreso_requisicion', '=', 't.id_tb_egreso_requisicion')
            ->selectRaw("'tramite' as origen, f.id, t.tipo, f.uuid,
                CASE WHEN t.estado = 'cancelado' THEN 1 ELSE 0 END as cancelada, f.nombre_emisor as emisor, f.rfc_emisor as rfc,
                f.serie, f.folio, f.fecha, f.created_at as cargada, f.total, f.isr,
                f.sat_estado, NULL as sat_estatus_cancelacion, 0 as sat_efos, f.sat_verificado_at, t.id_cat_egreso_unidad_administrativa as unidad_id,
                t.id_tb_egreso_requisicion as requisicion_id, {$ordenDelTramite} as orden_id, t.id as tramite_id, t.folio_completo as tramite_folio, t.contrato_id,
                (SELECT folio_completo FROM ordenes_compra WHERE id = {$ordenDelTramite}) as orden_folio,
                (SELECT clc_folio_completo FROM ordenes_compra WHERE id = {$ordenDelTramite}) as clc_folio, r.folio_completo as requisicion_folio,
                CASE WHEN f.xml_path IS NULL THEN 0 ELSE 1 END as xml");

        if (! $porAsignar) {
            return $ordenes->unionAll($tramites);
        }

        // Combustible: XML subidos antes del Excel del proveedor (todavía sin bitácora ni secretaría).
        $sinBitacora = DB::table('combustible_facturas as c')
            ->selectRaw("'por_asignar' as origen, c.id, 'por_asignar' as tipo, c.uuid, 0 as cancelada, c.nombre_emisor as emisor, c.rfc_emisor as rfc,
                NULL as serie, c.folio, c.fecha, c.created_at as cargada, c.total, 0 as isr,
                c.sat_estado, NULL as sat_estatus_cancelacion, COALESCE(c.sat_efos, 0) as sat_efos, c.sat_verificado_at, NULL as unidad_id,
                NULL as requisicion_id, NULL as orden_id, NULL as tramite_id, NULL as tramite_folio, NULL as contrato_id,
                NULL as orden_folio, NULL as clc_folio, NULL as requisicion_folio, 0 as xml");

        return $ordenes->unionAll($tramites)->unionAll($sinBitacora);
    }
}
