<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Auditoria;
use App\Models\EgresoUnidadAdministrativa;
use App\Models\Nomina;
use App\Models\NominaRenglon;
use App\Models\OrdenCompra;
use App\Services\ExcelNomina;
use App\Services\ExcelTabla;
use App\Services\GeneradorNomina;
use App\Services\PresupuestoService;
use App\Services\TramiteClc;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Trámite de pago de nómina (nuevo; SIEG no lo tenía). Recursos Humanos importa el Excel de la nómina (lo que genera
 * Nomipac, acomodado en la plantilla: unidad, fuente, proyecto, partida, concepto e importe) o captura un finiquito o
 * laudo, y lo envía a validar. Control Presupuestal revisa la suficiencia y al validarlo se generan una requisición,
 * un registro sin factura y una CLC por unidad administrativa (GeneradorNomina). El cálculo y el timbrado siguen en
 * Nomipac.
 */
class NominaController extends Controller
{
    private const FORMATO_IMPORTE = '#,##0.00;[Red]-#,##0.00';

    public function index(Request $request): JsonResponse
    {
        $query = Nomina::query()->withCount('renglones')->withSum('renglones', 'importe');
        if ($tipo = $request->input('tipo')) {
            $query->where('tipo', $tipo);
        }
        if ($estado = $request->input('estado')) {
            $query->where('estado', $estado);
        }
        if ($buscar = trim((string) $request->input('buscar'))) {
            $like = "%{$buscar}%";
            $query->where(fn ($q) => $q->where('folio_completo', 'like', $like)->orWhere('periodo', 'like', $like)->orWhere('concepto', 'like', $like)
                ->orWhere('beneficiario', 'like', $like)->orWhere('referencia', 'like', $like));
        }

        return response()->json([
            ...$query->orderByDesc('id')->paginate(20)->through(fn (Nomina $n) => [
                'id' => $n->id,
                'folio' => $n->folio_completo,
                'tipo' => $n->tipo,
                'tipo_texto' => $n->nombreTipo(),
                'periodo' => $n->periodo,
                'fecha_pago' => $n->fecha_pago?->toDateString(),
                'concepto' => $n->concepto,
                'beneficiario' => $n->aFavorDe(),
                'referencia' => $n->referencia,
                'renglones' => $n->renglones_count,
                'total' => round((float) $n->renglones_sum_importe, 2),
                'estado' => $n->estado,
                'estado_texto' => Nomina::ESTADOS[$n->estado] ?? $n->estado,
            ])->toArray(),
            'estados' => Nomina::ESTADOS,
            'tipos' => collect(Nomina::TIPOS)->map(fn ($t) => $t['nombre']),
        ]);
    }

    /** Para capturar: tipos (con su beneficiario sugerido), unidades y, si se pide una, sus líneas de presupuesto. */
    public function opciones(Request $request): JsonResponse
    {
        $unidades = EgresoUnidadAdministrativa::where('activo', true)->orderBy('clave')->get(['id_cat_egreso_unidad_administrativa', 'clave', 'nombre']);
        $clave = $request->integer('unidad') ? $unidades->firstWhere('id_cat_egreso_unidad_administrativa', $request->integer('unidad'))?->clave : null;

        return response()->json([
            'tipos' => collect(Nomina::TIPOS)->map(fn ($t, $k) => ['clave' => $k, 'nombre' => $t['nombre'], 'beneficiario' => $t['beneficiario'], 'individual' => in_array($k, Nomina::INDIVIDUALES, true)])->values(),
            'unidades' => $unidades->map(fn ($u) => ['id' => $u->id_cat_egreso_unidad_administrativa, 'clave' => $u->clave, 'nombre' => $u->nombre]),
            'lineas' => $clave ? app(PresupuestoService::class)->lineas([trim($clave)])
                ->map(fn ($l) => collect($l)->only(['fuente', 'nombre_fuente', 'proyecto', 'nombre_proyecto', 'partida', 'nombre_partida', 'disponible']))->values() : [],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validar($request);
        $nomina = DB::transaction(function () use ($validated, $request) {
            $año = (int) now('America/Mexico_City')->format('Y');
            $folio = (Nomina::where('tipo', $validated['tipo'])->where('año', $año)->lockForUpdate()->max('folio') ?? 0) + 1;

            return Nomina::create([...$validated, 'folio' => $folio, 'año' => $año,
                'folio_completo' => sprintf('%s-%d-%04d', Nomina::TIPOS[$validated['tipo']]['prefijo'], $año, $folio),
                'estado' => 'captura', 'user_id' => $request->user()->id]);
        });

        return response()->json(['id' => $nomina->id, 'message' => "Se registró {$nomina->folio_completo} ({$nomina->nombreTipo()}). "
            .($nomina->esIndividual() ? 'Agrega sus conceptos con su partida y envíala a validar.' : 'Importa el Excel con sus conceptos y envíala a validar.')], 201);
    }

    public function update(Request $request, Nomina $nomina): JsonResponse
    {
        $this->editable($nomina);
        $validated = $this->validar($request);
        if ($validated['tipo'] !== $nomina->tipo) {
            return $this->error('No se puede cambiar el tipo (el folio es de su serie).');
        }
        $nomina->update($validated);

        return response()->json(['message' => 'Datos actualizados.']);
    }

    public function show(Request $request, Nomina $nomina): JsonResponse
    {
        $user = $request->user();
        $nomina->load('usuario:id,name', 'validador:id,name', 'renglones', 'ordenes.requisicion:id_tb_egreso_requisicion,folio_completo,id_cat_egreso_unidad_administrativa');
        $unidades = EgresoUnidadAdministrativa::whereIn('id_cat_egreso_unidad_administrativa', $nomina->renglones->pluck('id_cat_egreso_unidad_administrativa')
            ->merge($nomina->ordenes->pluck('requisicion.id_cat_egreso_unidad_administrativa'))->filter()->unique())
            ->pluck('nombre', 'id_cat_egreso_unidad_administrativa');

        return response()->json([
            'nomina' => [
                'id' => $nomina->id,
                'folio' => $nomina->folio_completo,
                'tipo' => $nomina->tipo,
                'tipo_texto' => $nomina->nombreTipo(),
                'individual' => $nomina->esIndividual(),
                'periodo' => $nomina->periodo,
                'fecha_pago' => $nomina->fecha_pago?->toDateString(),
                'concepto' => $nomina->concepto,
                'beneficiario' => $nomina->beneficiario,
                'beneficiario_numero' => $nomina->beneficiario_numero,
                'a_favor_de' => $nomina->aFavorDe(),
                'referencia' => $nomina->referencia,
                'archivo' => $nomina->archivo_nombre,
                'estado' => $nomina->estado,
                'estado_texto' => Nomina::ESTADOS[$nomina->estado] ?? $nomina->estado,
                'observaciones' => $nomina->observaciones,
                'capturo' => $nomina->usuario?->name,
                'validado_por' => $nomina->validador?->name,
                'validado_at' => $nomina->validado_at?->toIso8601String(),
                'total' => round($nomina->renglones->sum('importe'), 2),
            ],
            'renglones' => $nomina->renglones->map(fn (NominaRenglon $r) => [
                'id' => $r->id,
                'fila' => $r->fila,
                'unidad_clave' => $r->unidad_clave,
                'unidad' => $unidades[$r->id_cat_egreso_unidad_administrativa] ?? null,
                'fuente' => $r->fuente,
                'proyecto' => $r->proyecto,
                'partida' => $r->partida,
                'concepto' => $r->concepto,
                'importe' => $r->importe,
            ]),
            // Lo que pide cada partida contra su disponible: Control Presupuestal ve si alcanza antes de validar.
            'suficiencia' => $nomina->estado === 'validado' ? [] : $this->suficiencia($nomina->renglones),
            'clc' => $nomina->ordenes->map(fn (OrdenCompra $o) => [
                'orden_id' => $o->id,
                'registro' => $o->folio_completo,
                'requisicion_id' => $o->id_tb_egreso_requisicion,
                'requisicion' => $o->requisicion?->folio_completo,
                'unidad' => $unidades[$o->requisicion?->id_cat_egreso_unidad_administrativa] ?? null,
                'importe' => $o->importe,
                'clc' => $o->clc_folio_completo,
                'clc_estado' => $o->clc_folio_completo ? ($o->clc_cancelada_at ? 'Cancelada' : TramiteClc::descripcion($o->clc_estado)) : null,
                'pago_fecha' => $o->pago_fecha?->toDateString(),
            ]),
            'puede' => [
                'editar' => $nomina->editable() && $user->can('nomina-create'),
                'enviar' => $nomina->editable() && $user->can('nomina-create') && $nomina->renglones->isNotEmpty(),
                'validar' => $nomina->estado === 'por_validar' && $user->can('nomina-validar'),
                'cancelar' => in_array($nomina->estado, ['captura', 'rechazado', 'por_validar'], true) && $user->canAny(['nomina-create', 'nomina-validar']),
            ],
        ]);
    }

    /** Plantilla de importación: un renglón por concepto de cada unidad, con su clasificación presupuestal. */
    public function plantilla()
    {
        $ejemplo = [
            ['120', '561', '101001', '1131', 'SUELDOS BASE', 150000.00],
            ['120', '561', '101001', '1321', 'PRIMA VACACIONAL', 12500.00],
        ];
        $libro = ExcelTabla::libro('Nómina', 'Plantilla: un renglón por concepto y unidad (clave presupuestal de la unidad, fuente, proyecto y partida); borra los de ejemplo',
            ExcelNomina::PLANTILLA, $ejemplo, [6 => self::FORMATO_IMPORTE], [6], [10, 10, 12, 10, 40, 16]);

        return response()->streamDownload(
            fn () => (new Xlsx($libro))->save('php://output'),
            'plantilla_nomina.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    /**
     * Importa el Excel de la nómina: reemplaza todos sus renglones. Se rechaza completo si algún renglón trae una
     * unidad o una línea de presupuesto (fuente / proyecto / partida) que no existe.
     */
    public function importar(Request $request, Nomina $nomina): JsonResponse
    {
        $this->editable($nomina);
        $request->validate(['archivo' => 'required|file|mimes:xlsx,xls,csv|max:10240'], [], ['archivo' => 'archivo']);
        $archivo = $request->file('archivo');
        $leido = ExcelNomina::leer($archivo->getRealPath());
        if (! $leido['errores'] && ! $leido['renglones']) {
            $leido['errores'][] = 'El archivo no tiene renglones.';
        }

        $unidades = EgresoUnidadAdministrativa::where('activo', true)->orderBy('id_cat_egreso_unidad_administrativa')->get(['id_cat_egreso_unidad_administrativa', 'clave'])
            ->groupBy(fn ($u) => self::normal($u->clave))->map->first();
        $lineas = app(PresupuestoService::class)->lineas()
            ->keyBy(fn ($l) => implode('|', array_map([self::class, 'normal'], [$l['unidad_clave'], $l['fuente'], $l['proyecto'], $l['partida']])));

        $renglones = [];
        foreach ($leido['renglones'] as $r) {
            $unidad = $unidades[self::normal($r['unidad'])] ?? null;
            $linea = $lineas[implode('|', array_map([self::class, 'normal'], [$r['unidad'], $r['fuente'], $r['proyecto'], $r['partida']]))] ?? null;
            if (! $unidad) {
                $leido['errores'][] = "Fila {$r['fila']}: la unidad {$r['unidad']} no existe en el catálogo.";

                continue;
            }
            if (! $linea) {
                $leido['errores'][] = "Fila {$r['fila']}: la partida {$r['partida']} (proy. {$r['proyecto']}, fte. {$r['fuente']}) no existe en el presupuesto de la unidad {$r['unidad']}.";

                continue;
            }
            // Las claves como están en el presupuesto (el Excel pudo perder ceros a la izquierda).
            $renglones[] = ['fila' => $r['fila'], 'id_cat_egreso_unidad_administrativa' => $unidad->id_cat_egreso_unidad_administrativa,
                'unidad_clave' => $linea['unidad_clave'], 'fuente' => $linea['fuente'], 'proyecto' => $linea['proyecto'], 'partida' => $linea['partida'],
                'concepto' => $r['concepto'], 'importe' => $r['importe']];
        }
        if ($leido['errores']) {
            throw ValidationException::withMessages(['archivo' => count($leido['errores']).' renglón(es) con error; corrígelos y vuelve a subirlo: '.implode(' ', array_slice($leido['errores'], 0, 6))]);
        }

        $ruta = $archivo->storeAs("nominas/{$nomina->id}", 'nomina_'.now()->format('Ymd_His').'.'.$archivo->getClientOriginalExtension(), 'local');
        $anterior = $nomina->archivo_path;
        Auditoria::enLote("Importación de {$nomina->nombreTipo()} {$nomina->folio_completo} ({$archivo->getClientOriginalName()})", fn () => DB::transaction(function () use ($nomina, $renglones, $ruta, $archivo) {
            $nomina->renglones()->delete();
            $nomina->renglones()->createMany($renglones);
            $nomina->update(['archivo_path' => $ruta, 'archivo_nombre' => $archivo->getClientOriginalName()]);
        }));
        if ($anterior && $anterior !== $ruta) {
            Storage::disk('local')->delete($anterior);
        }

        $unidadesN = collect($renglones)->pluck('unidad_clave')->unique()->count();

        return response()->json(['message' => count($renglones)." renglón(es) importados de {$unidadesN} unidad(es) por $".number_format(collect($renglones)->sum('importe'), 2).'.']);
    }

    public function archivo(Nomina $nomina)
    {
        abort_unless($nomina->archivo_path && Storage::disk('local')->exists($nomina->archivo_path), 404);

        return Storage::disk('local')->download($nomina->archivo_path, $nomina->archivo_nombre ?? "{$nomina->folio_completo}.xlsx");
    }

    /** Agrega un concepto a mano (finiquitos y laudos, o un ajuste a la nómina importada). */
    public function agregarRenglon(Request $request, Nomina $nomina): JsonResponse
    {
        $this->editable($nomina);
        $validated = $request->validate([
            'id_cat_egreso_unidad_administrativa' => 'required|integer',
            'fuente' => 'required|string|max:200',
            'proyecto' => 'required|string|max:200',
            'partida' => 'required|string|max:200',
            'concepto' => 'required|string|max:300',
            'importe' => 'required|numeric|gt:0',
        ], [], ['id_cat_egreso_unidad_administrativa' => 'unidad administrativa']);
        $unidad = EgresoUnidadAdministrativa::find($validated['id_cat_egreso_unidad_administrativa']) ?? throw ValidationException::withMessages(['id_cat_egreso_unidad_administrativa' => 'La unidad no existe.']);
        $clave = trim((string) $unidad->clave);
        if (! app(PresupuestoService::class)->linea(PresupuestoService::clave($clave, $validated['fuente'], $validated['proyecto'], $validated['partida']))) {
            throw ValidationException::withMessages(['partida' => 'Esa combinación de partida, proyecto y fuente no existe en el presupuesto de la unidad.']);
        }
        $nomina->renglones()->create([...$validated, 'unidad_clave' => $clave, 'concepto' => mb_strtoupper(trim($validated['concepto'])), 'importe' => round((float) $validated['importe'], 2)]);

        return response()->json(['message' => 'Concepto agregado.']);
    }

    public function quitarRenglon(Nomina $nomina, NominaRenglon $renglon): JsonResponse
    {
        $this->editable($nomina);
        abort_unless($renglon->nomina_id === $nomina->id, 404);
        $renglon->delete();

        return response()->json(['message' => 'Concepto quitado.']);
    }

    public function enviar(Nomina $nomina): JsonResponse
    {
        $this->editable($nomina);
        if (! $nomina->renglones()->exists()) {
            return $this->error('Agrega o importa los conceptos antes de enviarla a validar.');
        }
        if ($nomina->esIndividual() && blank($nomina->referencia)) {
            return $this->error('Captura el número de oficio o expediente que respalda el pago.');
        }
        $nomina->update(['estado' => 'por_validar', 'enviado_at' => now()]);

        return response()->json(['message' => "{$nomina->folio_completo} enviada a Control Presupuestal para su validación."]);
    }

    public function validarNomina(Request $request, Nomina $nomina, GeneradorNomina $generador): JsonResponse
    {
        $registros = $generador->generar($nomina, $request->user());

        return response()->json(['message' => "{$nomina->folio_completo} validada: se generaron {$registros->count()} CLC (".$registros->pluck('clc_folio_completo')->implode(', ').'), ya en la bandeja de Control Presupuestal.']);
    }

    public function rechazar(Request $request, Nomina $nomina): JsonResponse
    {
        if ($nomina->estado !== 'por_validar') {
            return $this->error('Solo se regresa una nómina enviada a validar.');
        }
        $validated = $request->validate(['observaciones' => 'required|string|max:2000'], [], ['observaciones' => 'observaciones']);
        $nomina->update(['estado' => 'rechazado', 'observaciones' => $validated['observaciones']]);

        return response()->json(['message' => "{$nomina->folio_completo} regresada a Recursos Humanos con observaciones."]);
    }

    public function cancelar(Request $request, Nomina $nomina): JsonResponse
    {
        if (! in_array($nomina->estado, ['captura', 'rechazado', 'por_validar'], true)) {
            return $this->error('Ya está validada: sus CLC se cancelan desde el trámite de CLC.');
        }
        $validated = $request->validate(['motivo' => 'required|string|max:1000'], [], ['motivo' => 'motivo']);
        $nomina->update(['estado' => 'cancelado', 'observaciones' => "Cancelada: {$validated['motivo']}"]);

        return response()->json(['message' => "{$nomina->folio_completo} cancelada."]);
    }

    /**
     * Por línea de presupuesto (unidad, fuente, proyecto, partida): lo que pide la nómina contra el disponible.
     *
     * @return array<int, array<string, mixed>>
     */
    private function suficiencia(Collection $renglones): array
    {
        if ($renglones->isEmpty()) {
            return [];
        }
        $lineas = app(PresupuestoService::class)->lineas($renglones->pluck('unidad_clave')->unique()->values()->all())->keyBy('clave');

        return $renglones->groupBy(fn ($r) => PresupuestoService::clave($r->unidad_clave, $r->fuente, $r->proyecto, $r->partida))
            ->map(function ($rs, $clave) use ($lineas) {
                $r = $rs->first();
                $linea = $lineas[$clave] ?? null;
                $requerido = round($rs->sum('importe'), 2);

                return [
                    'unidad_clave' => $r->unidad_clave, 'fuente' => $r->fuente, 'proyecto' => $r->proyecto, 'partida' => $r->partida,
                    'nombre_partida' => $linea['nombre_partida'] ?? null,
                    'requerido' => $requerido,
                    'disponible' => $linea['disponible'] ?? 0,
                    'suficiente' => $linea !== null && $requerido <= $linea['disponible'],
                ];
            })->values()->all();
    }

    private function validar(Request $request): array
    {
        $individual = in_array($request->input('tipo'), Nomina::INDIVIDUALES, true);
        $validated = $request->validate([
            'tipo' => 'required|in:'.implode(',', array_keys(Nomina::TIPOS)),
            'periodo' => 'nullable|string|max:150',
            'fecha_pago' => 'nullable|date',
            'concepto' => 'required|string|max:1000',
            'beneficiario' => 'required|string|max:300',
            'beneficiario_numero' => 'nullable|string|max:50',
            'referencia' => ($individual ? 'required' : 'nullable').'|string|max:200',
        ], [], ['fecha_pago' => 'fecha de pago', 'beneficiario_numero' => 'número de empleado', 'referencia' => $individual ? 'oficio o expediente' : 'referencia']);

        return [
            ...$validated,
            'periodo' => filled($validated['periodo'] ?? null) ? mb_strtoupper(trim($validated['periodo'])) : null,
            'concepto' => mb_strtoupper(trim($validated['concepto'])),
            'beneficiario' => mb_strtoupper(trim($validated['beneficiario'])),
            'beneficiario_numero' => filled($validated['beneficiario_numero'] ?? null) ? trim($validated['beneficiario_numero']) : null,
            'referencia' => filled($validated['referencia'] ?? null) ? mb_strtoupper(trim($validated['referencia'])) : null,
        ];
    }

    private function editable(Nomina $nomina): void
    {
        abort_unless(request()->user()->can('nomina-create'), 403);
        if (! $nomina->editable()) {
            throw ValidationException::withMessages(['nomina' => 'Ya no se puede modificar (está '.mb_strtolower(Nomina::ESTADOS[$nomina->estado]).').']);
        }
    }

    /** Clave para comparar: sin espacios, en mayúsculas y sin ceros a la izquierda. */
    private static function normal(?string $valor): string
    {
        $v = mb_strtoupper(trim((string) $valor));

        return ltrim($v, '0') === '' ? $v : ltrim($v, '0');
    }

    private function error(string $mensaje): JsonResponse
    {
        return response()->json(['message' => $mensaje], 422);
    }
}
