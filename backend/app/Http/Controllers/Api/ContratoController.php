<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contrato;
use App\Models\ContratoMensualidad;
use App\Models\EgresoUnidadAdministrativa;
use App\Models\OrdenCompra;
use App\Models\Proveedor;
use App\Models\TramiteServicio;
use App\Services\Cfdi;
use App\Services\FacturasTramite;
use App\Services\GeneradorTramiteServicio;
use App\Services\PresupuestoService;
use App\Services\TramiteClc;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Arrendamientos y servicios por contrato (nuevo; SIEG no lo tenía). El área registra el contrato y lo envía a
 * autorizar; Recursos Materiales lo autoriza una vez. Cada mes el área carga el XML de la mensualidad: se verifica
 * (proveedor del contrato, mes de la vigencia no facturado, saldo, SAT) y se generan en ese momento la requisición
 * (con los datos del XML), la orden de compra y la CLC, con la suficiencia del presupuesto al día. Lleva el control
 * de mensualidades generadas, pagadas y pendientes, y del saldo por ejercer.
 */
class ContratoController extends Controller
{
    private const MESES = ['', 'ENERO', 'FEBRERO', 'MARZO', 'ABRIL', 'MAYO', 'JUNIO', 'JULIO', 'AGOSTO', 'SEPTIEMBRE', 'OCTUBRE', 'NOVIEMBRE', 'DICIEMBRE'];

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Contrato::with('proveedor:id,nombre,rfc', 'unidadAdministrativa:id_cat_egreso_unidad_administrativa,clave,nombre')->withCount('mensualidades');
        if (! $this->veTodos($user)) {
            $query->whereIn('id_cat_egreso_unidad_administrativa', EgresoUnidadAdministrativa::idsMismaClave($user->id_cat_egreso_unidad_administrativa) ?: [0]);
        }
        if ($estado = $request->input('estado')) {
            $query->where('estado', $estado);
        }
        if ($buscar = trim((string) $request->input('buscar'))) {
            $like = "%{$buscar}%";
            $query->where(fn ($q) => $q->where('folio_completo', 'like', $like)->orWhere('numero_contrato', 'like', $like)->orWhere('concepto', 'like', $like)
                ->orWhereHas('proveedor', fn ($p) => $p->where('nombre', 'like', $like)->orWhere('rfc', 'like', $like)));
        }

        return response()->json([
            ...$query->orderByDesc('id')->paginate(20)->through(fn (Contrato $c) => [
                'id' => $c->id,
                'folio' => $c->folio_completo,
                'tipo' => Contrato::TIPOS[$c->tipo] ?? $c->tipo,
                'numero_contrato' => $c->numero_contrato,
                'unidad' => $c->unidadAdministrativa ? trim($c->unidadAdministrativa->clave.' '.$c->unidadAdministrativa->nombre) : null,
                'proveedor' => $c->proveedor?->nombre,
                'concepto' => $c->concepto,
                'fecha_inicio' => $c->fecha_inicio?->toDateString(),
                'fecha_fin' => $c->fecha_fin?->toDateString(),
                'monto_mensual' => $c->monto_mensual,
                'monto_total' => $c->monto_total,
                'meses' => $c->meses()->count(),
                'facturados' => $c->mensualidades_count,
                'estado' => $c->estado,
                'estado_texto' => Contrato::ESTADOS[$c->estado] ?? $c->estado,
            ])->toArray(),
            'estados' => Contrato::ESTADOS,
        ]);
    }

    /** Para registrar: secretarías visibles, proveedores activos del padrón y líneas de presupuesto de la secretaría. */
    public function opciones(Request $request): JsonResponse
    {
        $user = $request->user();
        $unidades = EgresoUnidadAdministrativa::where('activo', true)
            ->when(! $this->veTodos($user), fn ($q) => $q->whereIn('id_cat_egreso_unidad_administrativa', EgresoUnidadAdministrativa::idsMismaClave($user->id_cat_egreso_unidad_administrativa) ?: [0]))
            ->orderBy('clave')->get(['id_cat_egreso_unidad_administrativa', 'clave', 'nombre']);
        $unidad = $request->integer('unidad') ?: ($unidades->count() === 1 ? $unidades->first()->id_cat_egreso_unidad_administrativa : null);
        $clave = $unidad ? $unidades->firstWhere('id_cat_egreso_unidad_administrativa', $unidad)?->clave : null;

        return response()->json([
            'unidades' => $unidades->map(fn ($u) => ['id' => $u->id_cat_egreso_unidad_administrativa, 'clave' => $u->clave, 'nombre' => $u->nombre]),
            'proveedores' => Proveedor::where('activo', true)->orderBy('nombre')->get(['id', 'nombre', 'rfc']),
            'tipos' => Contrato::TIPOS,
            'unidad' => $unidad,
            'lineas' => $clave ? app(PresupuestoService::class)->lineas([trim($clave)])
                ->when(PresupuestoService::restringeCapitulo1000($user), fn ($ls) => $ls->reject(fn ($l) => PresupuestoService::esCapitulo1000($l['partida'])))
                ->map(fn ($l) => collect($l)->only(['fuente', 'nombre_fuente', 'proyecto', 'nombre_proyecto', 'partida', 'nombre_partida', 'disponible']))->values() : [],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validar($request);
        $contrato = DB::transaction(function () use ($validated, $request) {
            $año = (int) now('America/Mexico_City')->format('Y');
            $folio = (Contrato::where('año', $año)->lockForUpdate()->max('folio') ?? 0) + 1;

            return Contrato::create([...$validated, 'folio' => $folio, 'año' => $año, 'folio_completo' => sprintf('CT-%d-%04d', $año, $folio),
                'estado' => 'captura', 'user_id' => $request->user()->id]);
        });

        return response()->json(['id' => $contrato->id, 'message' => "Contrato {$contrato->folio_completo} registrado. Revísalo y envíalo a autorizar."], 201);
    }

    public function update(Request $request, Contrato $contrato): JsonResponse
    {
        $this->autorizar($contrato);
        abort_unless($request->user()->can('contratos-create'), 403);
        if (! $contrato->editable()) {
            return $this->error('Ya no se puede modificar (está '.mb_strtolower(Contrato::ESTADOS[$contrato->estado]).').');
        }
        $contrato->update($this->validar($request));

        return response()->json(['message' => 'Contrato actualizado.']);
    }

    public function show(Request $request, Contrato $contrato): JsonResponse
    {
        $this->autorizar($contrato);
        $user = $request->user();
        $contrato->load('proveedor', 'unidadAdministrativa', 'usuario:id,name', 'autorizador:id,name',
            'mensualidades.tramite.ordenCompra', 'mensualidades.tramite.requisicion:id_tb_egreso_requisicion,folio_completo', 'mensualidades.tramite.facturas');
        $porMes = $contrato->mensualidades->groupBy(fn ($m) => $m->periodo->format('Y-m'));
        $hoy = CarbonImmutable::now('America/Mexico_City')->startOfMonth();
        $nombres = $this->nombresPresupuesto($contrato);

        // Solo un contrato autorizado tiene mensualidades vencidas; antes, todas están pendientes.
        $puedeVencer = in_array($contrato->estado, ['vigente', 'terminado'], true);
        $meses = $contrato->meses()->map(function (CarbonImmutable $mes) use ($porMes, $hoy, $puedeVencer) {
            $registros = $porMes->get($mes->format('Y-m'), collect());
            $vigente = $registros->first(fn ($m) => ! $m->cancelada());
            $orden = $vigente?->tramite?->ordenCompra;

            return [
                'periodo' => $mes->format('Y-m'),
                'nombre' => self::MESES[$mes->month].' '.$mes->year,
                // pendiente (no ha llegado o se espera) | vencida (ya pasó y no se ha facturado) | generada | pagada
                'estado' => $vigente ? $vigente->estado() : ($puedeVencer && $mes < $hoy ? 'vencida' : 'pendiente'),
                'importe' => $vigente?->importe,
                'factura' => $vigente?->tramite?->facturas->first()?->serieFolio() ?? $vigente?->tramite?->facturas->first()?->uuid,
                'tramite' => $vigente?->tramite?->folio_completo,
                'requisicion' => $vigente?->tramite?->requisicion?->folio_completo,
                'requisicion_id' => $vigente?->tramite?->id_tb_egreso_requisicion,
                'orden_id' => $orden?->id,
                'orden' => $orden?->folio_completo,
                'clc' => $orden?->clc_folio_completo,
                'clc_estado' => $orden ? TramiteClc::descripcion($orden->clc_estado) : null,
                'pago' => $orden?->transferencia_numero ?? $orden?->pago_referencia,
                'pago_fecha' => $orden?->pago_fecha?->toDateString(),
                'canceladas' => $registros->filter(fn ($m) => $m->cancelada())->count(),
            ];
        })->values();
        $ejercido = round($contrato->mensualidades->reject(fn ($m) => $m->cancelada())->sum('importe'), 2);
        $pagado = round($contrato->mensualidades->filter(fn ($m) => $m->estado() === 'pagada')->sum('importe'), 2);
        $vigentes = $contrato->estado === 'vigente';
        $propio = $user->can('contratos-create');

        return response()->json([
            'contrato' => [
                'id' => $contrato->id,
                'folio' => $contrato->folio_completo,
                'tipo' => $contrato->tipo,
                'tipo_texto' => Contrato::TIPOS[$contrato->tipo] ?? $contrato->tipo,
                'numero_contrato' => $contrato->numero_contrato,
                'id_unidad' => $contrato->id_cat_egreso_unidad_administrativa,
                'unidad' => $contrato->unidadAdministrativa ? trim($contrato->unidadAdministrativa->clave.' — '.$contrato->unidadAdministrativa->nombre) : null,
                'proveedor_id' => $contrato->proveedor_id,
                'proveedor' => $contrato->proveedor?->nombre,
                'rfc' => $contrato->proveedor?->rfc,
                'impedimento_proveedor' => $contrato->proveedor?->impedimentoParaOrden(),
                'concepto' => $contrato->concepto,
                'fecha_inicio' => $contrato->fecha_inicio?->toDateString(),
                'fecha_fin' => $contrato->fecha_fin?->toDateString(),
                'monto_mensual' => $contrato->monto_mensual,
                'monto_total' => $contrato->monto_total,
                'fuente' => $contrato->fuente,
                'proyecto' => $contrato->proyecto,
                'partida' => $contrato->partida,
                'nombre_partida' => $nombres['partida'],
                'disponible' => $nombres['disponible'],
                'estado' => $contrato->estado,
                'estado_texto' => Contrato::ESTADOS[$contrato->estado] ?? $contrato->estado,
                'observaciones' => $contrato->observaciones,
                'capturo' => $contrato->usuario?->name,
                'autorizado_por' => $contrato->autorizador?->name,
                'autorizado_at' => $contrato->autorizado_at?->toIso8601String(),
            ],
            'meses' => $meses,
            'resumen' => [
                'meses' => $meses->count(),
                'generadas' => $meses->whereIn('estado', ['generada', 'pagada'])->count(),
                'pagadas' => $meses->where('estado', 'pagada')->count(),
                'pendientes' => $meses->whereIn('estado', ['pendiente', 'vencida'])->count(),
                'vencidas' => $meses->where('estado', 'vencida')->count(),
                'ejercido' => $ejercido,
                'pagado' => $pagado,
                'saldo' => round($contrato->monto_total - $ejercido, 2),
            ],
            'siguiente' => $meses->first(fn ($m) => in_array($m['estado'], ['pendiente', 'vencida'], true))['periodo'] ?? null,
            'puede' => [
                'editar' => $contrato->editable() && $propio,
                'enviar' => $contrato->editable() && $propio,
                'autorizar' => $contrato->estado === 'por_autorizar' && $user->can('contratos-autorizar'),
                'cancelar' => in_array($contrato->estado, ['captura', 'rechazado', 'por_autorizar'], true) && ($propio || $user->can('contratos-autorizar')),
                'terminar' => $vigentes && $user->can('contratos-autorizar'),
                'facturar' => $vigentes && $propio,
            ],
        ]);
    }

    public function enviar(Contrato $contrato): JsonResponse
    {
        $this->autorizar($contrato);
        abort_unless(auth()->user()->can('contratos-create'), 403);
        if (! $contrato->editable()) {
            return $this->error('Solo se envía uno en captura o no autorizado.');
        }
        if ($impedimento = $this->impedimentoProveedor($contrato)) {
            return $this->error($impedimento);
        }
        $contrato->update(['estado' => 'por_autorizar', 'enviado_at' => now()]);

        return response()->json(['message' => "{$contrato->folio_completo} enviado a autorizar."]);
    }

    public function autorizarContrato(Request $request, Contrato $contrato): JsonResponse
    {
        abort_unless($request->user()->can('contratos-autorizar'), 403);
        if ($contrato->estado !== 'por_autorizar') {
            return $this->error('Solo se autoriza uno enviado a autorizar.');
        }
        if ($impedimento = $this->impedimentoProveedor($contrato)) {
            return $this->error($impedimento);
        }
        $contrato->update(['estado' => 'vigente', 'autorizado_at' => now(), 'autorizado_user_id' => $request->user()->id, 'observaciones' => null]);

        return response()->json(['message' => "{$contrato->folio_completo} autorizado: cada mes, al cargar el XML de la mensualidad, se generan su requisición, orden de compra y CLC."]);
    }

    public function rechazar(Request $request, Contrato $contrato): JsonResponse
    {
        abort_unless($request->user()->can('contratos-autorizar'), 403);
        if ($contrato->estado !== 'por_autorizar') {
            return $this->error('Solo se regresa uno enviado a autorizar.');
        }
        $validated = $request->validate(['observaciones' => 'required|string|min:5|max:3000'], ['observaciones.required' => 'Escribe qué hay que corregir.']);
        $contrato->update(['estado' => 'rechazado', 'observaciones' => trim($validated['observaciones'])]);

        return response()->json(['message' => "{$contrato->folio_completo} regresado al área con observaciones."]);
    }

    /** Antes de autorizarse se cancela; ya vigente se da por terminado (no se generan más mensualidades). */
    public function cancelar(Request $request, Contrato $contrato): JsonResponse
    {
        $this->autorizar($contrato);
        $validated = $request->validate(['motivo' => 'required|string|min:5|max:1000']);
        if ($contrato->estado === 'vigente') {
            abort_unless($request->user()->can('contratos-autorizar'), 403);
            $contrato->update(['estado' => 'terminado', 'observaciones' => 'Terminado anticipadamente: '.trim($validated['motivo'])]);

            return response()->json(['message' => "{$contrato->folio_completo} terminado: ya no se generan más mensualidades."]);
        }
        if (! in_array($contrato->estado, ['captura', 'rechazado', 'por_autorizar'], true)) {
            return $this->error('Ya no se puede cancelar.');
        }
        $contrato->update(['estado' => 'cancelado', 'observaciones' => 'Cancelado: '.trim($validated['motivo'])]);

        return response()->json(['message' => "{$contrato->folio_completo} cancelado."]);
    }

    /**
     * XML de la mensualidad: se verifica y se generan en ese momento la requisición, la orden de compra y la CLC. Si
     * algo falla (factura, SAT o suficiencia), no queda nada a medias.
     */
    public function facturar(Request $request, Contrato $contrato, FacturasTramite $facturas, GeneradorTramiteServicio $generador): JsonResponse
    {
        $this->autorizar($contrato);
        $user = $request->user();
        abort_unless($user->can('contratos-create'), 403);
        $validated = $request->validate(['xml' => 'required|file|max:2048', 'periodo' => ['required', 'regex:/^\d{4}-\d{2}$/']],
            ['periodo.regex' => 'Elige el mes de la mensualidad.'], ['xml' => 'factura (XML)', 'periodo' => 'mes']);
        if ($contrato->estado !== 'vigente') {
            return $this->error('El contrato no está vigente ('.mb_strtolower(Contrato::ESTADOS[$contrato->estado]).').');
        }
        $periodo = CarbonImmutable::createFromFormat('!Y-m', $validated['periodo']);
        $nombreMes = self::MESES[$periodo->month].' '.$periodo->year;
        if (! $contrato->meses()->contains(fn ($m) => $m->equalTo($periodo))) {
            return $this->error("{$nombreMes} está fuera de la vigencia del contrato (".$contrato->fecha_inicio->format('d/m/Y').' al '.$contrato->fecha_fin->format('d/m/Y').').');
        }
        $yaFacturada = $contrato->mensualidades()->with('tramite.ordenCompra')->whereDate('periodo', $periodo->toDateString())->get()->first(fn ($m) => ! $m->cancelada());
        if ($yaFacturada) {
            return $this->error("La mensualidad de {$nombreMes} ya se facturó ({$yaFacturada->tramite?->folio_completo}).");
        }
        if ($impedimento = $this->impedimentoProveedor($contrato)) {
            return $this->error($impedimento);
        }

        $contenido = file_get_contents($request->file('xml')->getRealPath());
        $cfdi = Cfdi::leer($contenido);
        $rfc = mb_strtoupper(trim((string) $contrato->proveedor?->rfc));
        if ($cfdi['rfc_emisor'] !== $rfc) {
            throw ValidationException::withMessages(['xml' => "La factura la emite {$cfdi['rfc_emisor']}, no el proveedor del contrato ({$rfc})."]);
        }
        $importe = round($cfdi['total'] + $cfdi['isr'], 2);
        $saldo = $contrato->saldo();
        if ($importe > $saldo + Contrato::TOLERANCIA) {
            throw ValidationException::withMessages(['xml' => sprintf('La factura es por $%s y al contrato solo le quedan $%s por ejercer.', number_format($importe, 2), number_format($saldo, 2))]);
        }

        $tramite = $this->nuevoTramite($contrato, $nombreMes, $user->id);
        try {
            $r = $facturas->agregar($tramite, $contenido, $cfdi);
            $r['factura']->update(['fuente' => $contrato->fuente, 'proyecto' => $contrato->proyecto, 'partida' => $contrato->partida, 'concepto' => $tramite->concepto]);
            $tramite->update(['estado' => 'por_validar', 'enviado_at' => now()]);
            $requisicion = $generador->generar($tramite, $user);
        } catch (Throwable $e) {
            $this->deshacer($tramite);
            throw $e;
        }

        ContratoMensualidad::create(['contrato_id' => $contrato->id, 'periodo' => $periodo->toDateString(), 'tramite_servicio_id' => $tramite->id,
            'importe' => $r['factura']->importe(), 'user_id' => $user->id]);
        $orden = OrdenCompra::where('tramite_servicio_id', $tramite->id)->first();
        $faltan = $contrato->meses()->reject(fn ($m) => $contrato->mensualidades()->with('tramite.ordenCompra')->whereDate('periodo', $m->toDateString())->get()->contains(fn ($x) => ! $x->cancelada()));
        if ($faltan->isEmpty()) {
            $contrato->update(['estado' => 'terminado', 'observaciones' => 'Terminado: se facturaron todas sus mensualidades.']);
        }
        $diferencia = round($importe - $contrato->monto_mensual, 2);
        $avisos = array_filter([
            $r['aviso'],
            abs($diferencia) > Contrato::TOLERANCIA ? sprintf('La factura ($%s) no coincide con el monto mensual del contrato ($%s).', number_format($importe, 2), number_format($contrato->monto_mensual, 2)) : null,
        ]);

        return response()->json([
            'message' => "Mensualidad de {$nombreMes}: requisición {$requisicion->folio_completo}, orden de compra {$orden?->folio_completo} y CLC {$orden?->clc_folio_completo} generadas."
                .($faltan->isEmpty() ? ' Era la última: el contrato queda terminado.' : ''),
            'aviso' => $avisos ? implode(' ', $avisos) : null,
        ], 201);
    }

    // ----------------------------------------------------------------

    private function validar(Request $request): array
    {
        $validated = $request->validate([
            'tipo' => 'required|in:'.implode(',', array_keys(Contrato::TIPOS)),
            'numero_contrato' => 'nullable|string|max:100',
            'id_cat_egreso_unidad_administrativa' => 'required|integer',
            'proveedor_id' => 'required|integer|exists:proveedores,id',
            'concepto' => 'required|string|max:3000',
            'fecha_inicio' => 'required|date',
            'fecha_fin' => 'required|date|after_or_equal:fecha_inicio',
            'monto_mensual' => 'required|numeric|gt:0',
            'monto_total' => 'nullable|numeric|gt:0',
            'fuente' => 'required|string|max:200',
            'proyecto' => 'required|string|max:200',
            'partida' => 'required|string|max:200',
        ], ['fecha_fin.after_or_equal' => 'La fecha de término no puede ser anterior a la de inicio.'],
            ['id_cat_egreso_unidad_administrativa' => 'secretaría', 'proveedor_id' => 'proveedor', 'monto_mensual' => 'monto mensual', 'monto_total' => 'monto total',
                'fecha_inicio' => 'fecha de inicio', 'fecha_fin' => 'fecha de término']);
        $this->autorizarUnidad((int) $validated['id_cat_egreso_unidad_administrativa']);

        $clave = trim((string) EgresoUnidadAdministrativa::whereKey($validated['id_cat_egreso_unidad_administrativa'])->value('clave'));
        if (! app(PresupuestoService::class)->linea(PresupuestoService::clave($clave, $validated['fuente'], $validated['proyecto'], $validated['partida']))) {
            throw ValidationException::withMessages(['partida' => 'Esa combinación de partida, proyecto y fuente no existe en el presupuesto de la secretaría.']);
        }
        if (PresupuestoService::esCapitulo1000($validated['partida']) && PresupuestoService::restringeCapitulo1000($request->user())) {
            throw ValidationException::withMessages(['partida' => PresupuestoService::MENSAJE_CAPITULO_1000]);
        }
        $meses = (new Contrato(['fecha_inicio' => $validated['fecha_inicio'], 'fecha_fin' => $validated['fecha_fin']]))->meses()->count();
        $total = round((float) ($validated['monto_total'] ?? 0) ?: $meses * (float) $validated['monto_mensual'], 2);
        if ($total + Contrato::TOLERANCIA < (float) $validated['monto_mensual']) {
            throw ValidationException::withMessages(['monto_total' => 'El monto total no puede ser menor que una mensualidad.']);
        }

        return [
            ...$validated,
            'numero_contrato' => filled($validated['numero_contrato'] ?? null) ? mb_strtoupper(trim($validated['numero_contrato'])) : null,
            'concepto' => mb_strtoupper(trim($validated['concepto'])),
            'monto_mensual' => round((float) $validated['monto_mensual'], 2),
            'monto_total' => $total,
        ];
    }

    /** El trámite de la mensualidad (TC-AÑO-0001), ya ligado al proveedor del contrato. */
    private function nuevoTramite(Contrato $contrato, string $nombreMes, int $userId): TramiteServicio
    {
        return DB::transaction(function () use ($contrato, $nombreMes, $userId) {
            $año = (int) now('America/Mexico_City')->format('Y');
            $folio = (TramiteServicio::where('tipo', 'contrato')->where('año', $año)->lockForUpdate()->max('folio') ?? 0) + 1;

            return TramiteServicio::create([
                'tipo' => 'contrato',
                'contrato_id' => $contrato->id,
                'folio' => $folio,
                'año' => $año,
                'folio_completo' => sprintf('TC-%d-%04d', $año, $folio),
                'id_cat_egreso_unidad_administrativa' => $contrato->id_cat_egreso_unidad_administrativa,
                'concepto' => mb_substr("{$contrato->concepto} — MENSUALIDAD DE {$nombreMes}".($contrato->numero_contrato ? " (CONTRATO {$contrato->numero_contrato})" : ''), 0, 3000),
                'proveedor_rfc' => mb_strtoupper(trim((string) $contrato->proveedor?->rfc)),
                'proveedor_nombre' => $contrato->proveedor?->nombre,
                'estado' => 'captura',
                'user_id' => $userId,
            ]);
        });
    }

    /** Si la mensualidad no se pudo generar, se quita su trámite con su factura y su XML. */
    private function deshacer(TramiteServicio $tramite): void
    {
        $tramite->refresh();
        $rutas = $tramite->facturas()->pluck('xml_path');
        DB::transaction(function () use ($tramite) {
            $tramite->facturas()->get()->each(fn ($f) => $f->delete());
            $tramite->delete();
        });
        Storage::disk('local')->delete($rutas->filter()->all());
    }

    private function impedimentoProveedor(Contrato $contrato): ?string
    {
        $proveedor = $contrato->proveedor;
        if (! $proveedor) {
            return 'El proveedor del contrato ya no existe en el padrón.';
        }
        if (blank($proveedor->rfc)) {
            return "El proveedor {$proveedor->nombre} no tiene RFC en el padrón: sin él no se pueden verificar sus facturas.";
        }

        return $proveedor->impedimentoParaOrden();
    }

    /** @return array{partida: ?string, disponible: ?float} */
    private function nombresPresupuesto(Contrato $contrato): array
    {
        $clave = trim((string) $contrato->unidadAdministrativa?->clave);
        $linea = app(PresupuestoService::class)->linea(PresupuestoService::clave($clave, $contrato->fuente, $contrato->proyecto, $contrato->partida));

        return ['partida' => $linea['nombre_partida'] ?? null, 'disponible' => $linea['disponible'] ?? null];
    }

    private function veTodos($user): bool
    {
        return $user->can('contratos-autorizar') || $user->hasRole(['Super Admin', 'Admin']);
    }

    private function autorizar(Contrato $contrato): void
    {
        $this->autorizarUnidad((int) $contrato->id_cat_egreso_unidad_administrativa);
    }

    private function autorizarUnidad(int $unidad): void
    {
        $user = auth()->user();
        if ($this->veTodos($user)) {
            return;
        }
        abort_unless(in_array($unidad, EgresoUnidadAdministrativa::idsMismaClave($user->id_cat_egreso_unidad_administrativa), true), 403, 'No pertenece a tu secretaría.');
    }

    private function error(string $mensaje): JsonResponse
    {
        return response()->json(['message' => $mensaje], 422);
    }
}
