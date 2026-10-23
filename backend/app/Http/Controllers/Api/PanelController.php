<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EgresoRequisicion;
use App\Models\EgresoUnidadAdministrativa;
use App\Models\ModificacionPresupuestal;
use App\Services\PresupuestoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Panel de inicio (SIEG: DashboardController, respondiendo JSON). */
class PanelController extends Controller
{
    /**
     * Resumen del presupuesto de egresos de las unidades que el usuario puede ver, y lo que
     * tiene pendiente cada etapa (revisión, Recursos Materiales, modificaciones por autorizar).
     */
    public function index(Request $request, PresupuestoService $presupuesto): JsonResponse
    {
        $user = $request->user();

        if (! $user->can('requisiciones-index')) {
            return response()->json(['resumen' => null]);
        }

        $claves = PresupuestoService::clavesUnidadVisibles($user);
        $lineas = $presupuesto->lineas($claves);

        $totales = collect(['asignado', 'modificado', 'vigente', 'en_tramite', 'comprometido', 'devengado', 'pagado', 'disponible'])
            ->mapWithKeys(fn ($campo) => [$campo => round($lineas->sum($campo), 2)]);

        $requisiciones = EgresoRequisicion::query()
            ->when($claves !== null, fn ($q) => $q->whereIn(
                'id_cat_egreso_unidad_administrativa',
                EgresoUnidadAdministrativa::whereIn('clave', $claves ?: ['__ninguna__'])->pluck('id_cat_egreso_unidad_administrativa')
            ));

        return response()->json([
            'resumen' => [
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
            ],
        ]);
    }
}
