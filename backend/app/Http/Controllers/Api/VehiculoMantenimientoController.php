<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EgresoRequisicion;
use App\Models\EgresoUnidadAdministrativa;
use App\Models\Vehiculo;
use App\Models\VehiculoMantenimiento;
use App\Services\ExcelTabla;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Mantenimiento vehicular: se recibe el vehículo en el taller (queda "En taller"), desde ahí se hace la
 * requisición a nombre de su secretaría de resguardo y al terminar se entrega (vuelve a "En servicio").
 * SIEG: VehiculoMantenimientoController, respondiendo JSON.
 */
class VehiculoMantenimientoController extends Controller
{
    public const AGRUPACIONES = ['secretaria' => 'Secretaría', 'vehiculo' => 'Vehículo'];

    private const FORMATO_IMPORTE = '#,##0.00;[Red]-#,##0.00';

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
                'requisicion_cancelada' => (int) $m->requisicion?->id_cat_egreso_requisicion_estado === EgresoRequisicion::CANCELADA,
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
                [$costo, $situacion, $orden] = $m->costo();

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
                    'costo' => $costo,
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

    /**
     * Costo del mantenimiento vehicular por secretaría de resguardo (o por vehículo) en un periodo, por la fecha en
     * que se recibió en el taller. El costo de cada servicio es el de VehiculoMantenimiento::costo().
     */
    public function reporte(Request $request): JsonResponse
    {
        [$filas, $filtros, $todas] = $this->datosReporte($request);

        return response()->json([
            'filas' => $filas,
            'totales' => collect(['servicios', 'preventivos', 'correctivos', 'pagado', 'comprometido', 'estimado', 'total'])
                ->mapWithKeys(fn ($c) => [$c => round($filas->sum($c), 2)]),
            'agrupaciones' => self::AGRUPACIONES,
            'filtros' => $filtros,
            'unidades' => $todas ? EgresoUnidadAdministrativa::where('activo', true)->orderBy('clave')->get(['id_cat_egreso_unidad_administrativa as id', 'clave', 'nombre']) : [],
        ]);
    }

    public function reporteExcel(Request $request)
    {
        [$filas, $filtros] = $this->datosReporte($request);
        $vehiculo = $filtros['agrupar'] === 'vehiculo';
        // Por vehículo va su secretaría en la 2.ª columna y no la cuenta de vehículos: los importes quedan en 6 a 9.
        $conteos = $vehiculo ? [3, 4, 5] : [2, 3, 4, 5];
        $libro = ExcelTabla::libro(
            'Costo de mantenimiento vehicular por '.mb_strtolower(self::AGRUPACIONES[$filtros['agrupar']]),
            $filtros['descripcion'],
            [self::AGRUPACIONES[$filtros['agrupar']], ...($vehiculo ? ['Secretaría'] : []), 'Servicios', 'Preventivos', 'Correctivos', ...($vehiculo ? [] : ['Vehículos']),
                'Pagado', 'Comprometido', 'Estimado', 'Total'],
            $filas->map(fn ($f) => [$f['grupo'], ...($vehiculo ? [$f['secretaria']] : []), $f['servicios'], $f['preventivos'], $f['correctivos'],
                ...($vehiculo ? [] : [$f['vehiculos']]), $f['pagado'], $f['comprometido'], $f['estimado'], $f['total']]),
            array_fill_keys($conteos, '#,##0') + array_fill_keys([6, 7, 8, 9], self::FORMATO_IMPORTE),
            [...array_slice($conteos, 0, 3), 6, 7, 8, 9],
            $vehiculo ? [40, 40, 11, 12, 12, 16, 16, 16, 16] : [48, 11, 12, 12, 11, 16, 16, 16, 16],
        );

        return response()->streamDownload(
            fn () => (new Xlsx($libro))->save('php://output'),
            "costo_mantenimiento_por_{$filtros['agrupar']}_".now('America/Mexico_City')->format('Ymd_Hi').'.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
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

    /**
     * Servicios del periodo agrupados. Cuentan los cancelados (con costo cero) para que se vea cuántos se pidieron;
     * el costo solo suma lo vigente.
     *
     * @return array{0: Collection, 1: array, 2: bool}
     */
    private function datosReporte(Request $request): array
    {
        $validated = $request->validate([
            'desde' => 'nullable|date',
            'hasta' => 'nullable|date|after_or_equal:desde',
            'agrupar' => 'nullable|in:'.implode(',', array_keys(self::AGRUPACIONES)),
            'unidad' => 'nullable|integer',
        ]);
        $agrupar = $validated['agrupar'] ?? 'secretaria';
        $desde = $validated['desde'] ?? now('America/Mexico_City')->startOfYear()->toDateString();
        $hasta = $validated['hasta'] ?? now('America/Mexico_City')->toDateString();
        $unidad = filled($validated['unidad'] ?? null) ? EgresoUnidadAdministrativa::find($validated['unidad']) : null;
        // Como en el panel: el taller y los administradores ven todas las secretarías; los demás, la suya.
        $user = $request->user();
        $propias = $user->can('vehiculos-mantenimiento') || $user->hasRole(['Super Admin', 'Admin'])
            ? null : (EgresoUnidadAdministrativa::idsMismaClave($user->id_cat_egreso_unidad_administrativa) ?: [0]);

        $servicios = VehiculoMantenimiento::query()
            ->with(['vehiculo', 'unidadAdministrativa:id_cat_egreso_unidad_administrativa,clave,nombre',
                'requisicion:id_tb_egreso_requisicion,total,cancelada_at', 'requisicion.ordenCompra'])
            ->whereBetween('fecha_recepcion', [$desde, $hasta])
            ->when($unidad, fn ($q) => $q->whereIn('id_cat_egreso_unidad_administrativa', EgresoUnidadAdministrativa::idsMismaClave($unidad->id_cat_egreso_unidad_administrativa)))
            ->when($propias !== null, fn ($q) => $q->whereIn('id_cat_egreso_unidad_administrativa', $propias))
            ->get();

        $secretaria = fn (VehiculoMantenimiento $m) => $m->unidadAdministrativa ? trim($m->unidadAdministrativa->clave.' — '.$m->unidadAdministrativa->nombre) : 'SIN SECRETARÍA';
        $filas = $servicios
            ->groupBy(fn (VehiculoMantenimiento $m) => $agrupar === 'vehiculo' ? $m->vehiculo_id.'|'.$secretaria($m) : $secretaria($m))
            ->map(function ($grupo) use ($agrupar, $secretaria) {
                $primero = $grupo->first();
                $costos = $grupo->map(fn (VehiculoMantenimiento $m) => $m->costo());
                $suma = fn (string $situacion) => round($costos->where(1, $situacion)->sum(0), 2);

                return [
                    'grupo' => $agrupar === 'vehiculo' ? $primero->textoVehiculo() : $secretaria($primero),
                    'vehiculo_id' => $agrupar === 'vehiculo' ? $primero->vehiculo_id : null,
                    'secretaria' => $secretaria($primero),
                    'servicios' => $grupo->count(),
                    'preventivos' => $grupo->where('tipo', 'preventivo')->count(),
                    'correctivos' => $grupo->where('tipo', 'correctivo')->count(),
                    'vehiculos' => $grupo->pluck('vehiculo_id')->unique()->count(),
                    'pagado' => $suma('pagado'),
                    'comprometido' => $suma('comprometido'),
                    'estimado' => $suma('estimado'),
                    'total' => round($costos->sum(0), 2),
                ];
            })
            ->sortByDesc('total')->values();

        return [$filas, [
            'desde' => $desde,
            'hasta' => $hasta,
            'agrupar' => $agrupar,
            'unidad' => $unidad?->id_cat_egreso_unidad_administrativa,
            'descripcion' => 'Del '.date('d/m/Y', strtotime($desde)).' al '.date('d/m/Y', strtotime($hasta)).' · '
                .($unidad ? trim($unidad->clave.' '.$unidad->nombre) : ($propias === null ? 'Todas las secretarías' : 'Tu secretaría')),
        ], $propias === null];
    }
}
