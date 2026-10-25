<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClcRelacion;
use App\Models\EgresoUnidadAdministrativa;
use App\Models\OrdenCompra;
use App\Services\ExcelTabla;
use App\Services\TramiteClc;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

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
            ->with(['proveedor:id,nombre', 'requisicion:id_tb_egreso_requisicion,folio_completo,id_cat_egreso_unidad_administrativa,observaciones,beneficiario',
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
                'proveedor' => $o->aFavorDe(),
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
            'cuenta_id' => 'nullable|integer', // pago: cuenta del Ayuntamiento de la que sale (TesoreriaPagos)
        ], ['ordenes.required' => 'Selecciona al menos una CLC.']);

        $relacion = $tramite->ejecutar($validated['accion'], $validated['ordenes'], $request->user(), $validated);
        $cuantas = count(array_unique($validated['ordenes']));
        $mensaje = TramiteClc::ACCIONES[$validated['accion']]['etiqueta'].": {$cuantas} CLC.";

        return response()->json([
            'message' => $relacion ? "{$mensaje} Relación {$relacion->folio_completo} generada." : $mensaje,
            'relacion' => $relacion ? ['id' => $relacion->id, 'folio' => $relacion->folio_completo] : null,
        ]);
    }

    /**
     * Reporte de CLC en Excel con filtros: estado, fechas de la CLC, secretaría, fuente y búsqueda (folio, orden,
     * requisición, beneficiario o concepto). Cada quien exporta las CLC que puede ver en la pantalla.
     */
    public function excel(Request $request)
    {
        $f = $request->validate([
            'estado' => 'nullable|string', 'desde' => 'nullable|date', 'hasta' => 'nullable|date', 'unidad' => 'nullable|integer',
            'fuente' => 'nullable|string|max:20', 'buscar' => 'nullable|string|max:100', 'canceladas' => 'nullable|boolean',
        ]);
        $user = $request->user();
        $todas = TramiteClc::veTodo($user) || $user->canAny(['clc-recursos-materiales', 'clc-control-presupuestal', 'clc-sindicatura', 'clc-egresos']);
        $propias = EgresoUnidadAdministrativa::idsMismaClave($user->id_cat_egreso_unidad_administrativa);
        $estadosBandeja = TramiteClc::estadosDeSusBandejas($user);

        $ordenes = OrdenCompra::query()->whereNotNull('clc_folio_completo')
            ->when(! $todas, fn ($q) => $q->where(fn ($w) => $w->whereIn('clc_estado', $estadosBandeja ?: ['__ninguno__'])
                ->orWhereHas('requisicion', fn ($r) => $r->whereIn('id_cat_egreso_unidad_administrativa', $propias ?: [0]))))
            ->when($f['estado'] ?? null, fn ($q, $v) => $q->where('clc_estado', $v))
            ->when(! ($f['canceladas'] ?? false) && ($f['estado'] ?? null) !== 'cancelada', fn ($q) => $q->where('clc_estado', '!=', 'cancelada'))
            ->when($f['desde'] ?? null, fn ($q, $v) => $q->whereDate('clc_fecha', '>=', $v))
            ->when($f['hasta'] ?? null, fn ($q, $v) => $q->whereDate('clc_fecha', '<=', $v))
            ->when($f['unidad'] ?? null, fn ($q, $v) => $q->whereHas('requisicion', fn ($r) => $r->whereIn('id_cat_egreso_unidad_administrativa', EgresoUnidadAdministrativa::idsMismaClave((int) $v))))
            ->when($f['fuente'] ?? null, fn ($q, $v) => $q->whereHas('requisicion.tbEgresoRequisicionDetalles', fn ($d) => $d->whereRaw('TRIM(fuente) = ?', [trim($v)])))
            ->when(filled($f['buscar'] ?? null), function ($q) use ($f) {
                $like = '%'.trim($f['buscar']).'%';
                $q->where(fn ($w) => $w->where('clc_folio_completo', 'like', $like)->orWhere('folio_completo', 'like', $like)
                    ->orWhereHas('proveedor', fn ($p) => $p->where('nombre', 'like', $like)->orWhere('rfc', 'like', $like))
                    ->orWhereHas('requisicion', fn ($r) => $r->where('folio_completo', 'like', $like)->orWhere('observaciones', 'like', $like)->orWhere('beneficiario', 'like', $like)));
            })
            ->with(['proveedor:id,nombre,rfc', 'requisicion:id_tb_egreso_requisicion,folio_completo,id_cat_egreso_unidad_administrativa,observaciones,beneficiario',
                'requisicion.catEgresoUnidadAdministrativa:id_cat_egreso_unidad_administrativa,clave,nombre', 'requisicion.tbEgresoRequisicionDetalles:id_tb_egreso_requisicion,fuente,partida',
                'tramiteServicio:id,folio_completo', 'tesoreriaPago:id,numero'])
            ->orderBy('clc_año')->orderBy('clc_folio')->get();

        $filas = $ordenes->map(fn (OrdenCompra $o) => [
            $o->clc_folio_completo,
            $o->clc_fecha?->format('d/m/Y'),
            TramiteClc::descripcion($o->clc_estado),
            trim(($o->requisicion?->catEgresoUnidadAdministrativa?->clave ?? '').' '.($o->requisicion?->catEgresoUnidadAdministrativa?->nombre ?? '')),
            $o->requisicion?->folio_completo,
            $o->folio_completo,
            $o->tramiteServicio?->folio_completo,
            $o->aFavorDe(),
            $o->sin_factura ? null : $o->proveedor?->rfc,
            $o->requisicion?->observaciones,
            $o->requisicion?->tbEgresoRequisicionDetalles->pluck('fuente')->map(fn ($x) => trim((string) $x))->filter()->unique()->implode(', '),
            $o->requisicion?->tbEgresoRequisicionDetalles->pluck('partida')->map(fn ($x) => trim((string) $x))->filter()->unique()->implode(', '),
            (float) $o->importe,
            (float) ($o->xml_isr ?? 0),
            $o->netoAPagar(),
            $o->pago_fecha?->format('d/m/Y'),
            $o->pago_forma ? (OrdenCompra::FORMAS_PAGO[$o->pago_forma] ?? $o->pago_forma) : null,
            $o->tesoreriaPago?->numero ?? $o->transferencia_numero ?? $o->pago_referencia,
            $o->clc_motivo_cancelacion,
        ]);
        $descripcion = implode(' · ', array_filter([
            ($f['desde'] ?? null) || ($f['hasta'] ?? null) ? 'Del '.(($f['desde'] ?? null) ? date('d/m/Y', strtotime($f['desde'])) : 'inicio').' al '.(($f['hasta'] ?? null) ? date('d/m/Y', strtotime($f['hasta'])) : 'hoy') : 'Todas las fechas',
            ($f['estado'] ?? null) ? 'Estado: '.TramiteClc::descripcion($f['estado']) : (($f['canceladas'] ?? false) ? 'Con canceladas' : 'Sin canceladas'),
            ($f['fuente'] ?? null) ? 'Fuente '.$f['fuente'] : null,
            filled($f['buscar'] ?? null) ? 'Búsqueda: '.$f['buscar'] : null,
            $todas ? null : 'Las CLC que puedes ver',
        ]));
        $formato = '#,##0.00;[Red]-#,##0.00';
        $libro = ExcelTabla::libro('Cuentas por liquidar certificadas', $descripcion,
            ['CLC', 'Fecha', 'Estado', 'Secretaría', 'Requisición', 'Orden', 'Trámite', 'A favor de', 'RFC', 'Concepto', 'Fuente', 'Partida', 'Importe', 'ISR retenido', 'A pagar', 'Fecha de pago', 'Forma de pago', 'Transferencia / cheque', 'Motivo de cancelación'],
            $filas, [13 => $formato, 14 => $formato, 15 => $formato], [13, 14, 15], [15, 11, 24, 34, 13, 14, 14, 36, 14, 44, 8, 10, 15, 13, 15, 12, 13, 16, 30]);

        return response()->streamDownload(
            fn () => (new Xlsx($libro))->save('php://output'),
            'clc_'.now('America/Mexico_City')->format('Ymd_Hi').'.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    /** Documento de la relación: las CLC que se envían de un área a otra (sin O.C., requisición ni firmas). */
    public function relacionPdf(Request $request, ClcRelacion $relacion)
    {
        abort_unless(TramiteClc::veTodo($request->user()) || $request->user()->can('clc-sindicatura'), 403);

        $relacion->load(['ordenes.proveedor:id,nombre', 'ordenes.requisicion:id_tb_egreso_requisicion,id_cat_egreso_unidad_administrativa,beneficiario',
            'ordenes.requisicion.catEgresoUnidadAdministrativa:id_cat_egreso_unidad_administrativa,clave,nombre']);

        $pdf = Pdf::loadView('clc.relacion', ['relacion' => $relacion, 'areas' => TramiteClc::AREAS])->setPaper('letter');
        $pdf->render();
        $canvas = $pdf->getDomPDF()->getCanvas();
        $canvas->page_text($canvas->get_width() - 110, $canvas->get_height() - 28, 'Página {PAGE_NUM} de {PAGE_COUNT}',
            $pdf->getDomPDF()->getFontMetrics()->getFont('Helvetica'), 7);

        return $pdf->stream("{$relacion->folio_completo}.pdf");
    }
}
