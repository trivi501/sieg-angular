<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EgresoRequisicion;
use App\Models\EgresoUnidadAdministrativa;
use App\Models\Firmante;
use App\Models\ModificacionPresupuestal;
use App\Models\PresupuestoEgreso;
use App\Services\PresupuestoService;
use App\Services\SaldosMensuales;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Modificaciones presupuestales: el área solicita (traspaso / reducción / ampliación), se imprime el
 * formato y el Jefe de Control Presupuestal la autoriza o rechaza. Al autorizarse, el vigente de las
 * líneas cambia (PresupuestoService suma las modificaciones autorizadas). SIEG:
 * ModificacionPresupuestalController, respondiendo JSON.
 */
class ModificacionPresupuestalController extends Controller
{
    public function __construct(private PresupuestoService $presupuesto) {}

    /** Restringe la consulta a las modificaciones cuyas líneas son de las unidades del usuario. */
    private function aplicarAlcance($query, ?array $claves)
    {
        if ($claves === null) {
            return $query;
        }

        return $query->where(fn ($q) => $q->whereIn('origen_clave', $claves ?: ['__ninguna__'])
            ->orWhereIn('destino_clave', $claves ?: ['__ninguna__']));
    }

    public function index(Request $request): JsonResponse
    {
        $claves = PresupuestoService::clavesUnidadVisibles($request->user());
        $query = $this->aplicarAlcance(ModificacionPresupuestal::with('solicitante:id,name', 'autorizador:id,name'), $claves);

        foreach (['tipo', 'estado'] as $campo) {
            if ($filtro = $request->input($campo)) {
                $query->where($campo, $filtro);
            }
        }
        if ($filtro = $request->input('folio')) {
            $query->where('folio_completo', 'like', "%{$filtro}%");
        }
        if ($filtro = $request->input('partida')) {
            $query->where(fn ($q) => $q->where('origen_partida', 'like', "%{$filtro}%")->orWhere('destino_partida', 'like', "%{$filtro}%")
                ->orWhere('origen_descripcion', 'like', "%{$filtro}%")->orWhere('destino_descripcion', 'like', "%{$filtro}%"));
        }

        return response()->json([
            ...$this->conSaldos($query->orderByDesc('id')->paginate(20))->toArray(),
            'tipos' => ModificacionPresupuestal::TIPOS,
            'meses' => SaldosMensuales::MESES,
            'captura' => $this->captura(),
        ]);
    }

    /** Estado de la captura para el usuario: abierta, hasta qué día y si para él nunca se cierra. */
    private function captura(): array
    {
        $user = auth()->user();
        $periodo = ModificacionPresupuestal::periodoCaptura();

        return [
            'abierta' => ModificacionPresupuestal::puedeCapturar($user),
            'siempre' => ModificacionPresupuestal::siempreAbierta($user),
            'inicio' => $periodo['inicio']->format('Y-m-d'),
            'ultimoDia' => $periodo['fin']->format('Y-m-d'),
            'calendario' => $periodo['calendario'],
            'proximaApertura' => ModificacionPresupuestal::proximaApertura()->format('Y-m-d'),
            'diasCierre' => ModificacionPresupuestal::DIAS_CIERRE,
        ];
    }

    private function exigirCapturaAbierta(): void
    {
        if (! ModificacionPresupuestal::puedeCapturar(auth()->user())) {
            $periodo = ModificacionPresupuestal::periodoCaptura();

            throw ValidationException::withMessages(['tipo' => 'La captura de modificaciones presupuestales está cerrada (periodo del '
                .$periodo['inicio']->format('d/m/Y').' al '.$periodo['fin']->format('d/m/Y').'); se abre el '
                .ModificacionPresupuestal::proximaApertura()->format('d/m/Y').'. Mientras tanto, solo Control Presupuestal puede registrar modificaciones.']);
        }
    }

    /** Datos del formulario: líneas visibles, disponible de cada una por mes y la sugerencia si viene de una requisición. */
    public function opciones(Request $request): JsonResponse
    {
        $claves = PresupuestoService::clavesUnidadVisibles($request->user());
        $requisicion = $request->integer('requisicion') ? EgresoRequisicion::with('catEgresoUnidadAdministrativa')->find($request->integer('requisicion')) : null;

        // Si viene de una requisición sin suficiencia, se propone como destino la primera línea que no alcanza.
        $destinoSugerido = null;
        $faltanteSugerido = null;
        if ($requisicion && $requisicion->suficiencia === false) {
            $faltante = collect($this->presupuesto->validarRequisicion($requisicion)['partidas'])->firstWhere('suficiente', false);
            $destinoSugerido = $faltante['clave'] ?? null;
            $faltanteSugerido = $faltante['faltante'] ?? null;
        }

        $lineas = $this->presupuesto->lineas($claves);
        $mesActual = $this->mesActual();

        return response()->json([
            'lineas' => $lineas->map(fn ($l) => collect($l)->only([
                'clave', 'unidad_clave', 'unidad', 'fuente', 'nombre_fuente', 'proyecto', 'nombre_proyecto', 'partida', 'nombre_partida', 'vigente', 'disponible',
            ]))->values(),
            // Periodos que se pueden elegir (del mes actual a diciembre) y el disponible de cada línea en cada uno.
            'meses' => array_slice(SaldosMensuales::MESES, $mesActual - 1, null, true),
            'mesActual' => $mesActual,
            'disponiblesMes' => (new SaldosMensuales)->disponiblesPorMes((int) date('Y'), $lineas->pluck('clave')->all(), $mesActual),
            'tipos' => ModificacionPresupuestal::TIPOS,
            'requisicion' => $requisicion ? ['id' => $requisicion->id_tb_egreso_requisicion, 'folio' => $requisicion->folio_completo] : null,
            'destinoSugerido' => $destinoSugerido,
            'importeSugerido' => $faltanteSugerido,
            'captura' => $this->captura(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->exigirCapturaAbierta();

        $validated = $request->validate([
            'tipo' => ['required', Rule::in(array_keys(ModificacionPresupuestal::TIPOS))],
            'origen' => 'nullable|required_if:tipo,traspaso,reduccion|string|max:400',
            'destino' => 'nullable|required_if:tipo,traspaso,ampliacion|string|max:400|different:origen',
            'origen_mes' => 'nullable|required_if:tipo,traspaso,reduccion|integer|between:'.$this->mesActual().',12',
            'destino_mes' => 'nullable|required_if:tipo,traspaso,ampliacion|integer|between:'.$this->mesActual().',12',
            'importe' => 'required|numeric|min:0.01|max:9999999999',
            'justificacion' => 'required|string|max:5000',
            'id_tb_egreso_requisicion' => 'nullable|integer|exists:tb_egreso_requisicion,id_tb_egreso_requisicion',
        ], [
            'origen.required_if' => 'Selecciona la línea emisora (de la que sale el recurso).',
            'destino.required_if' => 'Selecciona la línea receptora (la que recibe el recurso).',
            'destino.different' => 'El emisor y el receptor deben ser líneas distintas.',
            'origen_mes.required_if' => 'Selecciona el periodo (mes) del que sale el recurso.',
            'destino_mes.required_if' => 'Selecciona el periodo (mes) al que entra el recurso.',
            'origen_mes.between' => 'El periodo del emisor debe ser del mes actual a diciembre.',
            'destino_mes.between' => 'El periodo del receptor debe ser del mes actual a diciembre.',
            'importe.required' => 'Captura el importe.',
            'importe.min' => 'El importe debe ser mayor a cero.',
            'justificacion.required' => 'Escribe la justificación.',
        ]);

        $tipo = $validated['tipo'];
        $usaOrigen = in_array($tipo, ['traspaso', 'reduccion'], true);
        $usaDestino = in_array($tipo, ['traspaso', 'ampliacion'], true);

        $claves = PresupuestoService::clavesUnidadVisibles($request->user());
        $origen = $usaOrigen ? $this->lineaPermitida($validated['origen'], $claves, 'origen') : null;
        $destino = $usaDestino ? $this->lineaPermitida($validated['destino'], $claves, 'destino') : null;
        $importe = round((float) $validated['importe'], 2);

        if ($origen && $destino && $origen['fuente'] !== $destino['fuente']) {
            throw ValidationException::withMessages(['destino' => 'El traspaso solo puede hacerse entre partidas de la misma fuente de financiamiento ('.$origen['fuente'].').']);
        }

        if ($origen && $importe > $origen['disponible']) {
            throw ValidationException::withMessages(['importe' => 'El importe excede el disponible de la línea de origen ($'.number_format($origen['disponible'], 2).').']);
        }
        if ($origen) {
            $this->validarDisponibleMes($origen['clave'], (int) $validated['origen_mes'], $importe, (int) date('Y'), 'importe');
        }

        $modificacion = DB::transaction(function () use ($validated, $tipo, $origen, $destino, $importe) {
            $año = (int) date('Y');
            $folio = (ModificacionPresupuestal::where('año', $año)->lockForUpdate()->max('folio') ?? 0) + 1;

            return ModificacionPresupuestal::create([
                'folio' => $folio,
                'año' => $año,
                'folio_completo' => sprintf('MP-%d-%04d', $año, $folio),
                'tipo' => $tipo,
                'estado' => 'pendiente',
                ...$this->camposLinea('origen', $origen),
                'origen_mes' => $origen ? (int) $validated['origen_mes'] : null,
                ...$this->camposLinea('destino', $destino),
                'destino_mes' => $destino ? (int) $validated['destino_mes'] : null,
                'importe' => $importe,
                'justificacion' => $validated['justificacion'],
                'id_tb_egreso_requisicion' => $validated['id_tb_egreso_requisicion'] ?? null,
                'solicitante_id' => auth()->id(),
            ]);
        });

        return response()->json([
            'id' => $modificacion->id,
            'message' => "Modificación {$modificacion->folio_completo} registrada. Imprime el formato para su autorización.",
        ], 201);
    }

    public function show(Request $request, ModificacionPresupuestal $modificacion): JsonResponse
    {
        $this->autorizarVer($modificacion);
        $modificacion->load('solicitante:id,name', 'autorizador:id,name', 'requisicion:id_tb_egreso_requisicion,folio_completo');

        return response()->json([
            'modificacion' => [
                ...$modificacion->toArray(),
                'solicitante' => $modificacion->solicitante?->name,
                'autorizador' => $modificacion->autorizador?->name,
                'requisicion' => $modificacion->requisicion ? ['id' => $modificacion->requisicion->id_tb_egreso_requisicion, 'folio' => $modificacion->requisicion->folio_completo] : null,
            ],
            'tipos' => ModificacionPresupuestal::TIPOS,
            'meses' => SaldosMensuales::MESES,
            'origen' => ($c = $modificacion->claveLinea('origen')) ? $this->presupuesto->linea($c) : null,
            'destino' => ($c = $modificacion->claveLinea('destino')) ? $this->presupuesto->linea($c) : null,
            'puedeResolver' => $modificacion->estado === 'pendiente' && $request->user()->can('modificaciones-autorizar'),
        ]);
    }

    public function pdf(ModificacionPresupuestal $modificacion)
    {
        $this->autorizarVer($modificacion);
        $modificacion->load('solicitante:id,name', 'autorizador:id,name', 'requisicion:id_tb_egreso_requisicion,folio_completo');

        // Unidad que solicita: la del emisor (o la del receptor en una ampliación). Firma su titular:
        // el firmante configurado para el formato (Catálogos → Firmantes) o el responsable de la unidad.
        $unidad = EgresoUnidadAdministrativa::where('clave', $modificacion->origen_clave ?? $modificacion->destino_clave)->first();
        $firmante = Firmante::titularDe($modificacion->solicitante()->with('regidor')->first(), 'modificacion_presupuestal', $unidad?->id_cat_egreso_unidad_administrativa, $modificacion->created_at);

        // Tipo de gasto de cada partida (columna del presupuesto importado).
        $tipoGasto = fn (string $lado) => $modificacion->{"{$lado}_clave"} === null ? null : PresupuestoEgreso::query()
            ->where('CLAVE', $modificacion->{"{$lado}_clave"})->where('CLAVE3', $modificacion->{"{$lado}_fuente"})
            ->where('CLAVE6', $modificacion->{"{$lado}_proyecto"})->where('PARTIDA', $modificacion->{"{$lado}_partida"})
            ->value('TIPO_DE_GASTO');

        $pdf = Pdf::loadView('modificaciones-presupuestales.pdf', [
            'modificacion' => $modificacion,
            'unidad' => $unidad,
            'firmante' => $firmante,
            'tipoGasto' => ['origen' => $tipoGasto('origen'), 'destino' => $tipoGasto('destino')],
        ])->setPaper('letter', 'landscape');

        // "Página N de M" en el pie (dompdf no conoce el total de páginas desde el CSS).
        $pdf->render();
        $canvas = $pdf->getDomPDF()->getCanvas();
        $canvas->page_text($canvas->get_width() - 150, $canvas->get_height() - 30.5, 'Página {PAGE_NUM} de {PAGE_COUNT}',
            $pdf->getDomPDF()->getFontMetrics()->getFont('Helvetica', 'bold'), 7);

        return $pdf->stream("{$modificacion->folio_completo}.pdf");
    }

    public function autorizar(Request $request, ModificacionPresupuestal $modificacion): JsonResponse
    {
        $validated = $request->validate(['observaciones' => 'nullable|string|max:3000']);

        DB::transaction(function () use ($modificacion, $validated) {
            $mod = ModificacionPresupuestal::whereKey($modificacion->id)->lockForUpdate()->firstOrFail();
            if ($mod->estado !== 'pendiente') {
                throw ValidationException::withMessages(['observaciones' => 'Esta modificación ya fue resuelta.']);
            }

            $origen = ($c = $mod->claveLinea('origen')) ? $this->presupuesto->linea($c) : null;
            $destino = ($c = $mod->claveLinea('destino')) ? $this->presupuesto->linea($c) : null;

            if (($mod->claveLinea('origen') && ! $origen) || ($mod->claveLinea('destino') && ! $destino)) {
                throw ValidationException::withMessages(['observaciones' => 'Una de las líneas ya no existe en el presupuesto importado.']);
            }
            if ($origen && $destino && $origen['fuente'] !== $destino['fuente']) {
                throw ValidationException::withMessages(['observaciones' => 'El traspaso solo puede hacerse entre partidas de la misma fuente de financiamiento.']);
            }
            // El disponible pudo cambiar desde la solicitud (otras requisiciones o modificaciones).
            if ($origen && $mod->importe > $origen['disponible']) {
                throw ValidationException::withMessages(['observaciones' => 'El disponible actual de la línea de origen ($'.number_format($origen['disponible'], 2).') ya no cubre el importe.']);
            }
            if ($origen && $mod->origen_mes) {
                $this->validarDisponibleMes($origen['clave'], $mod->origen_mes, $mod->importe, (int) $mod->año, 'observaciones');
            }

            $mod->update([
                'estado' => 'autorizada',
                'autorizador_id' => auth()->id(),
                'resuelto_at' => now(),
                'observaciones_resolucion' => $validated['observaciones'] ?? null,
                'origen_disponible_antes' => $origen['disponible'] ?? null,
                'destino_vigente_antes' => $destino['vigente'] ?? null,
            ]);
        });

        return response()->json(['message' => "Modificación {$modificacion->folio_completo} autorizada: el presupuesto quedó actualizado."]);
    }

    public function rechazar(Request $request, ModificacionPresupuestal $modificacion): JsonResponse
    {
        $validated = $request->validate(['observaciones' => 'required|string|max:3000'], [
            'observaciones.required' => 'Indica el motivo por el que no procede.',
        ]);

        // Con bloqueo, como al autorizar: dos resoluciones simultáneas no pisan una a la otra.
        DB::transaction(function () use ($modificacion, $validated) {
            $mod = ModificacionPresupuestal::whereKey($modificacion->id)->lockForUpdate()->firstOrFail();
            if ($mod->estado !== 'pendiente') {
                throw ValidationException::withMessages(['observaciones' => 'Esta modificación ya fue resuelta.']);
            }

            $mod->update([
                'estado' => 'rechazada',
                'autorizador_id' => auth()->id(),
                'resuelto_at' => now(),
                'observaciones_resolucion' => $validated['observaciones'],
            ]);
        });

        return response()->json(['message' => "Modificación {$modificacion->folio_completo} rechazada."]);
    }

    /**
     * Saldos actuales de las partidas de origen y destino de cada modificación de la página: disponible
     * anual y, si tiene periodo, el disponible de ese mes.
     */
    private function conSaldos($paginado)
    {
        $modificaciones = collect($paginado->items());
        $claves = $modificaciones->flatMap(fn ($m) => [$m->claveLinea('origen'), $m->claveLinea('destino')])->filter()->unique()->values();

        $lineas = $claves->isEmpty() ? collect() : $this->presupuesto->lineas($claves->map(fn ($c) => explode('|', $c)[0])->unique()->values()->all())->keyBy('clave');
        $porAño = $claves->isEmpty() ? collect() : $modificaciones->groupBy('año')->map(fn ($ms) => (new SaldosMensuales)->disponiblesPorMes((int) $ms->first()->año, $claves->all()));

        return $paginado->through(function ($m) use ($lineas, $porAño) {
            foreach (['origen', 'destino'] as $lado) {
                $clave = $m->claveLinea($lado);
                $mes = $m->{"{$lado}_mes"};
                $m->setAttribute("{$lado}_disponible", $clave ? ($lineas[$clave]['disponible'] ?? null) : null);
                $m->setAttribute("{$lado}_disponible_mes", $clave && $mes ? ($porAño[$m->año][$clave][$mes] ?? null) : null);
            }

            return [
                ...$m->toArray(),
                'solicitante' => $m->solicitante?->name,
                'autorizador' => $m->autorizador?->name,
            ];
        });
    }

    private function mesActual(): int
    {
        return (int) now(SaldosMensuales::ZONA)->month;
    }

    /** El emisor no puede ceder más de lo que tiene disponible en el periodo elegido. */
    private function validarDisponibleMes(string $clave, int $mes, float $importe, int $año, string $campo): void
    {
        $disponible = (new SaldosMensuales)->disponiblesPorMes($año, [$clave], $mes)[$clave][$mes] ?? 0.0;

        if (round($importe, 2) > $disponible) {
            throw ValidationException::withMessages([$campo => 'El importe excede el disponible de '.SaldosMensuales::MESES[$mes].' de la línea emisora ($'.number_format($disponible, 2).').']);
        }
    }

    private function lineaPermitida(string $clave, ?array $clavesUnidad, string $campo): array
    {
        $unidad = explode('|', $clave)[0];
        $linea = $this->presupuesto->linea($clave);

        if (! $linea || ($clavesUnidad !== null && ! in_array($unidad, $clavesUnidad, true))) {
            throw ValidationException::withMessages([$campo => 'Esa línea de presupuesto no existe o no pertenece a tu secretaría.']);
        }

        return $linea;
    }

    private function camposLinea(string $lado, ?array $linea): array
    {
        return [
            "{$lado}_clave" => $linea['unidad_clave'] ?? null,
            "{$lado}_fuente" => $linea['fuente'] ?? null,
            "{$lado}_proyecto" => $linea['proyecto'] ?? null,
            "{$lado}_partida" => $linea['partida'] ?? null,
            "{$lado}_descripcion" => $linea
                ? mb_substr("{$linea['unidad']} · {$linea['fuente']} · {$linea['proyecto']} · {$linea['partida']} {$linea['nombre_partida']}", 0, 500)
                : null,
        ];
    }

    private function autorizarVer(ModificacionPresupuestal $modificacion): void
    {
        $claves = PresupuestoService::clavesUnidadVisibles(auth()->user());
        if ($claves !== null && ! in_array($modificacion->origen_clave, $claves, true) && ! in_array($modificacion->destino_clave, $claves, true)) {
            abort(403, 'Esta modificación no pertenece a tu secretaría.');
        }
    }
}
