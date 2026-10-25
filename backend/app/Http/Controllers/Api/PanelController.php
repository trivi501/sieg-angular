<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CombustibleCarga;
use App\Models\EgresoRequisicion;
use App\Models\EgresoUnidadAdministrativa;
use App\Models\ModificacionPresupuestal;
use App\Models\OrdenCompra;
use App\Models\TesoreriaPago;
use App\Models\TramiteServicio;
use App\Models\User;
use App\Models\VehiculoMantenimiento;
use App\Services\PresupuestoService;
use App\Services\TramiteClc;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Panel de inicio (SIEG: DashboardController, respondiendo JSON). Cada bloque se muestra solo a quien tiene el
 * permiso de esa área: presupuesto y requisiciones pendientes, CLC y facturas pendientes, pagos y pasivos,
 * consumo de combustible y gasto en mantenimiento vehicular. Quien no ve todas las secretarías ve las suyas.
 */
class PanelController extends Controller
{
    private const TOP = 5;

    public function index(Request $request, PresupuestoService $presupuesto): JsonResponse
    {
        $user = $request->user();
        $hoy = CarbonImmutable::now('America/Mexico_City');

        return response()->json([
            'resumen' => $user->can('requisiciones-index') ? $this->resumen($user, $presupuesto) : null,
            'clc' => $user->can('clc-index') ? $this->clc($user) : null,
            'pagos' => $user->canAny(['tesoreria-index', 'clc-index']) ? $this->pagos($user, $hoy) : null,
            'combustible' => $user->can('combustible-index') ? $this->combustible($user, $hoy) : null,
            'mantenimiento' => $user->canAny(['vehiculos-mantenimiento', 'catalogos-vehiculos-index']) ? $this->mantenimiento($user, $hoy) : null,
        ]);
    }

    /**
     * Resumen del presupuesto de egresos de las unidades que el usuario puede ver, y lo que tiene pendiente cada
     * etapa (revisión, Recursos Materiales, modificaciones por autorizar).
     */
    private function resumen(User $user, PresupuestoService $presupuesto): array
    {
        $claves = PresupuestoService::clavesUnidadVisibles($user);
        $lineas = $presupuesto->lineas($claves);

        $totales = collect(['asignado', 'modificado', 'vigente', 'en_tramite', 'comprometido', 'devengado', 'ejercido', 'pagado', 'disponible'])
            ->mapWithKeys(fn ($campo) => [$campo => round($lineas->sum($campo), 2)]);

        $requisiciones = EgresoRequisicion::query()
            ->when($claves !== null, fn ($q) => $q->whereIn(
                'id_cat_egreso_unidad_administrativa',
                EgresoUnidadAdministrativa::whereIn('clave', $claves ?: ['__ninguna__'])->pluck('id_cat_egreso_unidad_administrativa')
            ));

        return [
            'verPresupuesto' => $user->can('gasto-index'),
            'totales' => $totales,
            'lineas' => $lineas->count(),
            'lineasSinDisponible' => $lineas->where('disponible', '<=', 0)->count(),
            'pendientes' => [
                'pendientes' => (clone $requisiciones)->whereIn('id_cat_egreso_requisicion_estado', [1, 4])->count(),
                'en_revision' => (clone $requisiciones)->where('id_cat_egreso_requisicion_estado', 2)->count(),
                'recursos_materiales' => (clone $requisiciones)->whereIn('id_cat_egreso_requisicion_estado', [5, 6])->where(fn ($q) => $q->whereNull('suficiencia')->orWhere('suficiencia', false))->count(),
                // En SIEG también contaba las canceladas que tenían suficiencia; aquí solo las comprometidas vigentes.
                'sin_orden_compra' => (clone $requisiciones)->where('suficiencia', true)
                    ->where('id_cat_egreso_requisicion_estado', '!=', EgresoRequisicion::CANCELADA)->whereDoesntHave('ordenCompra')->count(),
                'modificaciones' => ModificacionPresupuestal::where('estado', 'pendiente')
                    ->when($claves !== null, fn ($q) => $q->where(fn ($q2) => $q2->whereIn('origen_clave', $claves ?: ['__ninguna__'])->orWhereIn('destino_clave', $claves ?: ['__ninguna__'])))
                    ->count(),
            ],
        ];
    }

    /** CLC en trámite por área, facturas recibidas sin CLC y trámites (servicios, fondos, combustible) por validar. */
    private function clc(User $user): array
    {
        $ordenes = $this->ordenesVisibles($user);
        $enTramite = (clone $ordenes)->whereNotNull('clc_folio_completo')->whereNotIn('clc_estado', ['pagada', 'cancelada'])
            ->get(['id', 'clc_estado', 'importe', 'xml_isr']);
        $porArea = $enTramite->groupBy(fn ($o) => TramiteClc::ESTADOS[$o->clc_estado][1] ?? 'area');
        $sinClc = $this->facturasSinClc(clone $ordenes)->get(['id', 'importe', 'xml_isr']);
        $veTodo = $this->veTodasLasSecretarias($user);
        $tramites = TramiteServicio::where('estado', 'por_validar')
            ->when(! $veTodo, fn ($q) => $q->whereIn('id_cat_egreso_unidad_administrativa', $this->unidadesPropias($user) ?: [0]))
            ->selectRaw('tipo, COUNT(*) AS n')->groupBy('tipo')->pluck('n', 'tipo');

        return [
            'en_tramite' => ['cantidad' => $enTramite->count(), 'importe' => round($enTramite->sum(fn ($o) => $o->netoAPagar()), 2)],
            'por_area' => collect(TramiteClc::AREAS)->map(fn ($nombre, $area) => [
                'area' => $area,
                'nombre' => $nombre,
                'cantidad' => ($porArea[$area] ?? collect())->count(),
                'importe' => round(($porArea[$area] ?? collect())->sum(fn ($o) => $o->netoAPagar()), 2),
            ])->values(),
            'facturas_sin_clc' => ['cantidad' => $sinClc->count(), 'importe' => round($sinClc->sum(fn ($o) => $o->netoAPagar()), 2)],
            'tramites_por_validar' => collect(TramiteServicio::TIPOS)->map(fn ($t, $tipo) => ['tipo' => $tipo, 'nombre' => $t['nombre'], 'cantidad' => (int) ($tramites[$tipo] ?? 0)])
                ->filter(fn ($t) => $t['cantidad'] > 0)->values(),
        ];
    }

    /**
     * Pagado en el mes y en el año; pasivo acumulado (lo devengado sin pagar: CLC emitidas por pagar y facturas
     * recibidas sin CLC) y pagos en tránsito (emitidos y sin conciliar con el banco).
     */
    private function pagos(User $user, CarbonImmutable $hoy): array
    {
        $ordenes = $this->ordenesVisibles($user);
        $pagadas = (clone $ordenes)->where('clc_estado', 'pagada')->whereNotNull('pago_fecha');
        $suma = fn ($q) => round($q->get(['id', 'importe', 'xml_isr'])->sum(fn ($o) => $o->netoAPagar()), 2);
        $porPagar = (clone $ordenes)->whereNotNull('clc_folio_completo')->whereNotIn('clc_estado', ['pagada', 'cancelada']);
        $sinClc = $this->facturasSinClc(clone $ordenes);
        $clcPorPagar = $suma(clone $porPagar);
        $facturasSinClc = $suma(clone $sinClc);
        $transito = $this->veTodasLasSecretarias($user) && $user->can('tesoreria-index')
            ? TesoreriaPago::whereIn('estado', ['pagado', 'entregado'])->selectRaw('COUNT(*) AS n, COALESCE(SUM(importe), 0) AS importe')->first() : null;

        return [
            'mes' => ['nombre' => mb_strtolower($hoy->locale('es')->monthName), 'cantidad' => (clone $pagadas)->whereBetween('pago_fecha', [$hoy->startOfMonth()->toDateString(), $hoy->toDateString()])->count(),
                'importe' => $suma((clone $pagadas)->whereBetween('pago_fecha', [$hoy->startOfMonth()->toDateString(), $hoy->toDateString()]))],
            'año' => ['año' => $hoy->year, 'cantidad' => (clone $pagadas)->whereYear('pago_fecha', $hoy->year)->count(), 'importe' => $suma((clone $pagadas)->whereYear('pago_fecha', $hoy->year))],
            'pasivo' => ['clc_por_pagar' => $clcPorPagar, 'facturas_sin_clc' => $facturasSinClc, 'total' => round($clcPorPagar + $facturasSinClc, 2),
                'cantidad' => (clone $porPagar)->count() + (clone $sinClc)->count()],
            'en_transito' => $transito ? ['cantidad' => (int) $transito->n, 'importe' => round((float) $transito->importe, 2)] : null,
        ];
    }

    /** Consumo de combustible del año y del mes (por fecha del ticket) y las secretarías que más gastan. */
    private function combustible(User $user, CarbonImmutable $hoy): array
    {
        $todas = $user->canAny(['combustible-cargar', 'combustible-validar']) || $user->hasRole(['Super Admin', 'Admin']);
        $base = fn () => CombustibleCarga::query()->toBase()
            ->join('tramites_servicio as t', 't.id', '=', 'combustible_cargas.tramite_servicio_id')
            ->where('t.estado', '!=', 'cancelado')
            ->when(! $todas, fn ($q) => $q->whereIn('t.id_cat_egreso_unidad_administrativa', $this->unidadesPropias($user) ?: [0]));
        $totales = fn ($q) => $q->selectRaw('COUNT(*) AS tickets, COALESCE(SUM(combustible_cargas.litros), 0) AS litros, COALESCE(SUM(combustible_cargas.importe), 0) AS importe')->first();
        $año = $totales($base()->whereYear('combustible_cargas.fecha_ticket', $hoy->year));
        $mes = $totales($base()->whereBetween('combustible_cargas.fecha_ticket', [$hoy->startOfMonth()->toDateString(), $hoy->toDateString()]));
        $top = $base()->whereYear('combustible_cargas.fecha_ticket', $hoy->year)
            ->join('cat_egreso_unidad_administrativa as u', 'u.id_cat_egreso_unidad_administrativa', '=', 't.id_cat_egreso_unidad_administrativa')
            ->selectRaw("CONCAT(u.clave, ' ', u.nombre) AS secretaria, SUM(combustible_cargas.litros) AS litros, SUM(combustible_cargas.importe) AS importe")
            ->groupBy('u.clave', 'u.nombre')->orderByDesc('importe')->limit(self::TOP)->get();
        $bitacoras = TramiteServicio::where('tipo', 'combustible')->whereIn('estado', ['captura', 'rechazado', 'por_validar'])
            ->when(! $todas, fn ($q) => $q->whereIn('id_cat_egreso_unidad_administrativa', $this->unidadesPropias($user) ?: [0]))->count();

        return [
            'año' => ['año' => $hoy->year, 'tickets' => (int) $año->tickets, 'litros' => round((float) $año->litros, 3), 'importe' => round((float) $año->importe, 2)],
            'mes' => ['nombre' => mb_strtolower($hoy->locale('es')->monthName), 'tickets' => (int) $mes->tickets, 'litros' => round((float) $mes->litros, 3), 'importe' => round((float) $mes->importe, 2)],
            'secretarias' => $top->map(fn ($s) => ['secretaria' => $s->secretaria, 'litros' => round((float) $s->litros, 3), 'importe' => round((float) $s->importe, 2)]),
            'bitacoras_pendientes' => $bitacoras,
        ];
    }

    /**
     * Mantenimiento vehicular del año: servicios, vehículos en el taller y costo por secretaría. Costo de cada uno: su
     * orden de compra si la tiene (no cancelada) o el total de su requisición (estimado); cero si se canceló.
     */
    private function mantenimiento(User $user, CarbonImmutable $hoy): array
    {
        $todas = $user->can('vehiculos-mantenimiento') || $user->hasRole(['Super Admin', 'Admin']);
        $filas = DB::table('vehiculo_mantenimientos as m')
            ->leftJoin('tb_egreso_requisicion as r', 'r.id_tb_egreso_requisicion', '=', 'm.id_tb_egreso_requisicion')
            ->leftJoin('ordenes_compra as oc', fn ($j) => $j->on('oc.id_tb_egreso_requisicion', '=', 'r.id_tb_egreso_requisicion')->whereNull('oc.cancelada_at'))
            ->leftJoin('cat_egreso_unidad_administrativa as u', 'u.id_cat_egreso_unidad_administrativa', '=', 'm.id_cat_egreso_unidad_administrativa')
            ->whereYear('m.fecha_recepcion', $hoy->year)
            ->when(! $todas, fn ($q) => $q->whereIn('m.id_cat_egreso_unidad_administrativa', $this->unidadesPropias($user) ?: [0]))
            ->selectRaw("m.id, m.estado, m.tipo, CONCAT(u.clave, ' ', u.nombre) AS secretaria,
                CASE WHEN r.id_tb_egreso_requisicion IS NULL OR r.cancelada_at IS NOT NULL THEN 0 ELSE COALESCE(oc.importe, r.total, 0) END AS costo,
                CASE WHEN oc.pago_fecha IS NOT NULL THEN 1 ELSE 0 END AS pagado")
            ->get();

        return [
            'año' => $hoy->year,
            'servicios' => $filas->count(),
            'en_taller' => $filas->where('estado', 'en_taller')->count(),
            'preventivos' => $filas->where('tipo', 'preventivo')->count(),
            'correctivos' => $filas->where('tipo', 'correctivo')->count(),
            'costo' => round($filas->sum('costo'), 2),
            'pagado' => round($filas->where('pagado', 1)->sum('costo'), 2),
            'secretarias' => $filas->groupBy('secretaria')->map(fn ($g, $s) => ['secretaria' => $s ?: 'SIN SECRETARÍA', 'servicios' => $g->count(), 'costo' => round($g->sum('costo'), 2)])
                ->sortByDesc('costo')->take(self::TOP)->values(),
            'tipos' => VehiculoMantenimiento::TIPOS,
        ];
    }

    // ----------------------------------------------------------------

    /** Órdenes de compra de las secretarías que el usuario ve (todas si participa en el trámite de CLC o es Admin). */
    private function ordenesVisibles(User $user)
    {
        return OrdenCompra::query()->when(! $this->veTodasLasSecretarias($user), fn ($q) => $q->whereHas('requisicion',
            fn ($r) => $r->whereIn('id_cat_egreso_unidad_administrativa', $this->unidadesPropias($user) ?: [0])));
    }

    /**
     * Órdenes con factura (su XML o las de su trámite) y sin CLC vigente: no tienen CLC o se les canceló. Son
     * devengado: obligación reconocida que todavía no se paga.
     */
    private function facturasSinClc($ordenes)
    {
        return $ordenes->whereNull('cancelada_at')
            ->where(fn ($q) => $q->whereNotNull('xml_uuid')->orWhereNotNull('xml_cargado_at')->orWhereNotNull('tramite_servicio_id'))
            ->where(fn ($q) => $q->whereNull('clc_folio_completo')->orWhere('clc_estado', 'cancelada'));
    }

    private function veTodasLasSecretarias(User $user): bool
    {
        return TramiteClc::veTodo($user) || $user->canAny(['clc-recursos-materiales', 'clc-sindicatura', 'clc-egresos', 'tesoreria-index']);
    }

    private function unidadesPropias(User $user): array
    {
        return EgresoUnidadAdministrativa::idsMismaClave($user->id_cat_egreso_unidad_administrativa);
    }
}
