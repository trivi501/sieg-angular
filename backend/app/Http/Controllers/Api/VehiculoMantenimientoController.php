<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Vehiculo;
use App\Models\VehiculoMantenimiento;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Mantenimiento vehicular: se recibe el vehículo en el taller (queda "En taller"), desde ahí se hace la
 * requisición a nombre de su secretaría de resguardo y al terminar se entrega (vuelve a "En servicio").
 * SIEG: VehiculoMantenimientoController, respondiendo JSON.
 */
class VehiculoMantenimientoController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filtros = $request->only('buscar', 'estado');

        $mantenimientos = VehiculoMantenimiento::query()
            ->with(['vehiculo', 'unidadAdministrativa:id_cat_egreso_unidad_administrativa,clave,nombre', 'departamento:id,nombre',
                'requisicion:id_tb_egreso_requisicion,folio_completo,id_cat_egreso_requisicion_estado', 'requisicion.catEgresoRequisicionEstado', 'usuario:id,name'])
            ->when(filled($filtros['estado'] ?? null), fn ($q) => $q->where('estado', $filtros['estado']))
            ->when(filled($filtros['buscar'] ?? null), function ($q) use ($filtros) {
                foreach (preg_split('/\s+/', trim($filtros['buscar'])) as $t) {
                    $like = "%{$t}%";
                    $q->where(fn ($w) => $w->where('folio_completo', 'like', $like)->orWhere('descripcion', 'like', $like)
                        ->orWhereHas('vehiculo', fn ($v) => $v->where('numero_economico', 'like', $like)->orWhere('placas', 'like', $like)
                            ->orWhere('marca', 'like', $like)->orWhere('linea', 'like', $like))
                        ->orWhereHas('unidadAdministrativa', fn ($u) => $u->where('nombre', 'like', $like)->orWhere('clave', 'like', $like)));
                }
            })
            ->orderByDesc('id')->paginate(25)
            ->through(fn (VehiculoMantenimiento $m) => [
                'id' => $m->id,
                'folio' => $m->folio_completo,
                'vehiculo' => $m->textoVehiculo(),
                'secretaria' => $m->unidadAdministrativa ? trim($m->unidadAdministrativa->clave.' '.$m->unidadAdministrativa->nombre) : null,
                'area' => $m->departamento?->nombre,
                'fecha_recepcion' => $m->fecha_recepcion?->toDateString(),
                'kilometraje' => $m->kilometraje,
                'tipo' => VehiculoMantenimiento::TIPOS[$m->tipo] ?? $m->tipo,
                'descripcion' => $m->descripcion,
                'entrega' => $m->entrega,
                'recibio' => $m->usuario?->name,
                'estado' => $m->estado,
                'fecha_entrega' => $m->fecha_entrega?->toDateString(),
                'recibe_entrega' => $m->recibe_entrega,
                'observaciones_entrega' => $m->observaciones_entrega,
                'requisicion_id' => $m->id_tb_egreso_requisicion,
                'requisicion' => $m->requisicion?->folio_completo,
                'requisicion_estado' => $m->requisicion?->catEgresoRequisicionEstado?->descripcion,
                // Cancelada: el taller puede hacer otra que la sustituya.
                'requisicion_cancelada' => (int) $m->requisicion?->id_cat_egreso_requisicion_estado === \App\Models\EgresoRequisicion::CANCELADA,
            ]);

        // Vehículos que se pueden recibir: activos, con secretaría de resguardo y que no estén ya en el taller.
        $vehiculos = Vehiculo::where('activo', true)->whereNotNull('id_cat_egreso_unidad_administrativa')
            ->whereDoesntHave('mantenimientos', fn ($q) => $q->where('estado', 'en_taller'))
            ->with(['unidadAdministrativa:id_cat_egreso_unidad_administrativa,clave,nombre', 'departamento:id,nombre'])
            ->orderBy('numero_economico')->get()
            ->map(fn (Vehiculo $v) => [
                'id' => $v->id,
                'texto' => trim("ECO {$v->numero_economico}".($v->placas ? " · {$v->placas}" : '').' · '.trim("{$v->marca} {$v->linea} {$v->modelo}")),
                'secretaria' => $v->unidadAdministrativa ? trim($v->unidadAdministrativa->clave.' '.$v->unidadAdministrativa->nombre) : null,
                'area' => $v->departamento?->nombre,
            ]);

        // Los que no se pueden recibir y por qué, para que el taller sepa qué corregir en el padrón.
        $noDisponibles = Vehiculo::whereNotIn('id', $vehiculos->pluck('id'))
            ->withExists(['mantenimientos as en_taller' => fn ($q) => $q->where('estado', 'en_taller')])
            ->orderBy('numero_economico')->get()
            ->map(fn (Vehiculo $v) => [
                'texto' => trim("ECO {$v->numero_economico}".($v->placas ? " · {$v->placas}" : '')),
                'motivo' => match (true) {
                    ! $v->activo => 'dado de baja en el padrón',
                    ! $v->id_cat_egreso_unidad_administrativa => 'sin secretaría de resguardo',
                    default => 'ya está en el taller',
                },
            ]);

        return response()->json([
            ...$mantenimientos->toArray(),
            'vehiculos' => $vehiculos,
            'noDisponibles' => $noDisponibles,
            'tipos' => VehiculoMantenimiento::TIPOS,
            'puedeRequisicion' => $request->user()->can('requisiciones-create'),
        ]);
    }

    /**
     * Historial de mantenimientos de un vehículo del padrón con su costo (nuevo; SIEG no lo tenía).
     * Costo de cada uno: el importe de su orden de compra (pagado o comprometido); si aún no hay orden,
     * el total de la requisición (estimado); cero si no tiene requisición o se canceló.
     */
    public function historial(Vehiculo $vehiculo): JsonResponse
    {
        $vehiculo->load('unidadAdministrativa:id_cat_egreso_unidad_administrativa,clave,nombre');
        $mantenimientos = $vehiculo->mantenimientos()
            ->with(['requisicion:id_tb_egreso_requisicion,folio_completo,total,cancelada_at,id_cat_egreso_requisicion_estado',
                'requisicion.catEgresoRequisicionEstado', 'requisicion.ordenCompra'])
            ->orderByDesc('fecha_recepcion')->orderByDesc('id')->get()
            ->map(function (VehiculoMantenimiento $m) {
                $req = $m->requisicion;
                // Una orden cancelada no cuenta: si la requisición sigue viva, vale su total (estimado).
                $orden = $req?->ordenCompra && ! $req->ordenCompra->cancelada_at ? $req->ordenCompra : null;
                [$costo, $situacion] = match (true) {
                    $req === null => [0.0, 'sin_requisicion'],
                    $req->cancelada_at !== null => [0.0, 'cancelado'],
                    $orden && $orden->pago_registrado_at => [(float) $orden->importe, 'pagado'],
                    $orden !== null => [(float) $orden->importe, 'comprometido'],
                    default => [(float) $req->total, 'estimado'],
                };

                return [
                    'id' => $m->id,
                    'folio' => $m->folio_completo,
                    'fecha_recepcion' => $m->fecha_recepcion?->toDateString(),
                    'fecha_entrega' => $m->fecha_entrega?->toDateString(),
                    'tipo' => VehiculoMantenimiento::TIPOS[$m->tipo] ?? $m->tipo,
                    'kilometraje' => $m->kilometraje,
                    'descripcion' => $m->descripcion,
                    'estado' => VehiculoMantenimiento::ESTADOS[$m->estado] ?? $m->estado,
                    'requisicion_id' => $req?->id_tb_egreso_requisicion,
                    'requisicion' => $req?->folio_completo,
                    'requisicion_estado' => $req?->catEgresoRequisicionEstado?->descripcion,
                    'orden' => $orden?->folio_completo,
                    'costo' => round($costo, 2),
                    'situacion' => $situacion,
                ];
            });

        $suma = fn (string ...$situaciones) => round($mantenimientos->whereIn('situacion', $situaciones)->sum('costo'), 2);

        return response()->json([
            'vehiculo' => [
                'id' => $vehiculo->id,
                'texto' => trim("ECO {$vehiculo->numero_economico}".($vehiculo->placas ? " · {$vehiculo->placas}" : '').' · '.trim("{$vehiculo->marca} {$vehiculo->linea} {$vehiculo->modelo}")),
                'secretaria' => $vehiculo->unidadAdministrativa ? trim($vehiculo->unidadAdministrativa->clave.' '.$vehiculo->unidadAdministrativa->nombre) : null,
            ],
            'mantenimientos' => $mantenimientos,
            'totales' => [
                'mantenimientos' => $mantenimientos->count(),
                'pagado' => $suma('pagado'),
                'comprometido' => $suma('comprometido'),
                'estimado' => $suma('estimado'),
                'total' => $suma('pagado', 'comprometido', 'estimado'),
            ],
        ]);
    }

    /** Recibe el vehículo en el taller (folio MV-AÑO-0001); el vehículo queda "En taller". */
    public function store(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'vehiculo_id' => 'required|integer|exists:vehiculos,id',
            'fecha_recepcion' => 'required|date|before_or_equal:today',
            'kilometraje' => 'nullable|integer|min:0|max:9999999',
            'tipo' => 'required|in:'.implode(',', array_keys(VehiculoMantenimiento::TIPOS)),
            'descripcion' => 'required|string|min:5|max:2000',
            'entrega' => 'nullable|string|max:200',
        ], [
            'vehiculo_id.required' => 'Elige el vehículo.',
            'fecha_recepcion.before_or_equal' => 'La fecha no puede ser futura.',
            'descripcion.required' => 'Describe la falla o el servicio solicitado.',
            'descripcion.min' => 'Describe la falla o el servicio solicitado.',
        ]);

        $vehiculo = Vehiculo::findOrFail($datos['vehiculo_id']);
        if (! $vehiculo->id_cat_egreso_unidad_administrativa) {
            throw ValidationException::withMessages(['vehiculo_id' => 'El vehículo no tiene secretaría de resguardo en el padrón.']);
        }

        // Con bloqueo sobre el vehículo: dos recepciones simultáneas no lo meten dos veces al taller.
        $mantenimiento = DB::transaction(function () use ($datos, $vehiculo, $request) {
            Vehiculo::whereKey($vehiculo->id)->lockForUpdate()->first();
            if ($vehiculo->mantenimientos()->where('estado', 'en_taller')->exists()) {
                throw ValidationException::withMessages(['vehiculo_id' => 'Ese vehículo ya está en el taller.']);
            }

            $año = (int) substr($datos['fecha_recepcion'], 0, 4);
            $folio = (VehiculoMantenimiento::where('año', $año)->lockForUpdate()->max('folio') ?? 0) + 1;
            $m = VehiculoMantenimiento::create([
                ...$datos,
                'folio' => $folio, 'año' => $año, 'folio_completo' => sprintf('MV-%d-%04d', $año, $folio),
                'id_cat_egreso_unidad_administrativa' => $vehiculo->id_cat_egreso_unidad_administrativa,
                'departamento_id' => $vehiculo->departamento_id,
                'user_id' => $request->user()->id, 'estado' => 'en_taller',
            ]);
            $vehiculo->update(['estado' => 'taller']);

            return $m;
        });

        return response()->json(['message' => "Vehículo recibido en el taller ({$mantenimiento->folio_completo}).", 'id' => $mantenimiento->id], 201);
    }

    /** Termina el mantenimiento: se entrega el vehículo y vuelve a "En servicio". */
    public function entregar(Request $request, VehiculoMantenimiento $mantenimiento): JsonResponse
    {
        if ($mantenimiento->estado !== 'en_taller') {
            return response()->json(['message' => 'Ese vehículo ya se entregó.'], 422);
        }
        $datos = $request->validate([
            'fecha_entrega' => 'required|date|before_or_equal:today|after_or_equal:'.$mantenimiento->fecha_recepcion->toDateString(),
            'recibe_entrega' => 'required|string|max:200',
            'observaciones_entrega' => 'nullable|string|max:2000',
        ], [
            'fecha_entrega.before_or_equal' => 'La fecha no puede ser futura.',
            'fecha_entrega.after_or_equal' => 'La entrega no puede ser antes de la recepción.',
            'recibe_entrega.required' => 'Indica quién recibe el vehículo.',
        ]);

        DB::transaction(function () use ($mantenimiento, $datos) {
            $mantenimiento->update([...$datos, 'estado' => 'entregado']);
            $mantenimiento->vehiculo->update(['estado' => 'en_servicio']);
        });

        return response()->json(['message' => "Vehículo del {$mantenimiento->folio_completo} entregado."]);
    }
}
