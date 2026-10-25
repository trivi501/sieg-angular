<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Proveedor;
use App\Models\ProveedorCedula;
use App\Models\ProveedorDocumento;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Padrón de proveedores y contratistas (Órgano Interno de Control; nuevo, SIEG no lo tenía). El OIC da de alta al
 * proveedor, integra su expediente (constancia fiscal, opinión de cumplimiento del SAT, identificación…), emite su
 * cédula (CED-AÑO-0001) con vigencia de un año y la renueva, o lo rechaza o suspende. La vigencia de la cédula es la
 * del proveedor: vencida o suspendido, no se le asignan órdenes de compra. Los de compra única (eventuales) se
 * identifican aparte y no llevan cédula. Alertas: cédulas vencidas o por vencer y opiniones del SAT vencidas.
 */
class PadronController extends Controller
{
    private const RFC = '/^[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}$/u';

    public function index(Request $request): JsonResponse
    {
        $estado = (string) $request->input('estado');
        $buscar = mb_strtoupper(trim((string) $request->input('buscar')));
        $proveedores = Proveedor::with(['cedulas' => fn ($q) => $q->limit(1)])->orderBy('nombre')->get()
            ->filter(fn (Proveedor $p) => $buscar === '' || str_contains(mb_strtoupper($p->nombre.' '.$p->rfc.' '.$p->giro), $buscar))
            ->map(fn (Proveedor $p) => $this->fila($p))
            ->filter(fn ($f) => $estado === '' || $f['estado'] === $estado || ($estado === 'alerta' && in_array($f['estado'], ['vencido', 'por_vencer'], true)))
            ->values();
        $pagina = max(1, $request->integer('page', 1));
        $paginado = new LengthAwarePaginator($proveedores->forPage($pagina, 25)->values(), $proveedores->count(), 25, $pagina);

        return response()->json([...$paginado->toArray(), 'estados' => Proveedor::ESTADOS_PADRON]);
    }

    /** Conteo por estado y las alertas: cédulas vencidas y por vencer, y opiniones del SAT vencidas de los registrados. */
    public function alertas(): JsonResponse
    {
        $proveedores = Proveedor::where('activo', true)->with('documentos')->get();
        $filas = $proveedores->map(fn ($p) => $this->fila($p));
        $opinionesVencidas = $proveedores->filter(function (Proveedor $p) {
            $opinion = $p->opinionVigente();

            return $p->padron_estado === 'registrado' && ! $p->compra_unica && (! $opinion || ! $opinion->vigente() || $opinion->sentido !== 'positiva');
        });

        return response()->json([
            'conteo' => collect(Proveedor::ESTADOS_PADRON)->map(fn ($texto, $estado) => ['estado' => $estado, 'texto' => $texto, 'cantidad' => $filas->where('estado', $estado)->count()])->values(),
            'vencidas' => $filas->where('estado', 'vencido')->sortBy('vigencia')->values(),
            'por_vencer' => $filas->where('estado', 'por_vencer')->sortBy('vigencia')->values(),
            'opiniones_vencidas' => $opinionesVencidas->map(fn ($p) => $this->fila($p))->values(),
            'dias_alerta' => Proveedor::DIAS_ALERTA,
        ]);
    }

    /** Alta en el padrón (o de un proveedor de compra única). */
    public function store(Request $request): JsonResponse
    {
        $datos = $this->validar($request);
        $proveedor = Proveedor::create([...$datos, 'activo' => true, 'padron_estado' => $datos['compra_unica'] ? null : 'en_revision']);

        return response()->json(['id' => $proveedor->id, 'message' => $proveedor->compra_unica
            ? "{$proveedor->nombre} registrado como proveedor de compra única."
            : "{$proveedor->nombre} registrado en revisión: integra su expediente y emite su cédula."], 201);
    }

    public function update(Request $request, Proveedor $proveedor): JsonResponse
    {
        $proveedor->update($this->validar($request, $proveedor));

        return response()->json(['message' => 'Datos del padrón actualizados.']);
    }

    public function show(Proveedor $proveedor): JsonResponse
    {
        $proveedor->load('documentos', 'cedulas.usuario:id,name');
        $user = auth()->user();
        $gestiona = $user->can('padron-gestionar');
        $faltantes = $proveedor->compra_unica ? [] : $proveedor->faltantesParaCedula();
        $documentos = $proveedor->documentos->groupBy('tipo');

        return response()->json([
            'proveedor' => [
                ...$this->fila($proveedor),
                'correo' => $proveedor->correo,
                'telefono' => $proveedor->telefono,
                'domicilio' => $proveedor->domicilio,
                'clabe' => $proveedor->clabe,
                'tipo_persona' => $proveedor->tipo_persona,
                'representante_legal' => $proveedor->representante_legal,
                'regimen_fiscal' => $proveedor->regimen_fiscal,
                'observaciones' => $proveedor->padron_observaciones,
                'activo' => $proveedor->activo,
                'impedimento' => $proveedor->impedimentoParaOrden(),
            ],
            // Requisitos del expediente: cuáles se tienen (el más reciente de cada tipo).
            'requisitos' => collect(ProveedorDocumento::TIPOS)->map(fn ($t, $tipo) => [
                'tipo' => $tipo,
                'nombre' => $t[0],
                'obligatorio' => $t[1] && (! $t[2] || $proveedor->tipo_persona === 'moral'),
                'cumplido' => $documentos->has($tipo),
            ])->filter(fn ($r) => $r['obligatorio'] || $r['cumplido'])->values(),
            'documentos' => $proveedor->documentos->map(fn (ProveedorDocumento $d) => [
                'id' => $d->id,
                'tipo' => $d->tipo,
                'tipo_texto' => ProveedorDocumento::TIPOS[$d->tipo][0] ?? $d->tipo,
                'nombre_archivo' => $d->nombre_archivo,
                'fecha_documento' => $d->fecha_documento?->toDateString(),
                'vigente_hasta' => $d->vigente_hasta?->toDateString(),
                'vigente' => $d->vigente(),
                'sentido' => $d->sentido,
                'observaciones' => $d->observaciones,
                'subido' => $d->created_at?->toIso8601String(),
            ])->values(),
            'cedulas' => $proveedor->cedulas->map(fn (ProveedorCedula $c) => [
                'id' => $c->id, 'numero' => $c->numero, 'fecha_emision' => $c->fecha_emision?->toDateString(), 'vigencia' => $c->vigencia?->toDateString(),
                'emitio' => $c->usuario?->name, 'observaciones' => $c->observaciones,
            ])->values(),
            'faltantes' => $faltantes,
            'tipos_documento' => collect(ProveedorDocumento::TIPOS)->map(fn ($t) => $t[0]),
            'puede' => [
                'editar' => $gestiona,
                'documentos' => $gestiona,
                'emitir' => $gestiona && ! $proveedor->compra_unica && $proveedor->padron_estado !== 'suspendido' && ! $faltantes,
                'rechazar' => $gestiona && $proveedor->padron_estado === 'en_revision',
                'suspender' => $gestiona && $proveedor->padron_estado === 'registrado',
                'reactivar' => $gestiona && $proveedor->padron_estado === 'suspendido',
                'cedula_pdf' => $proveedor->cedulas->isNotEmpty(),
            ],
        ]);
    }

    public function subirDocumento(Request $request, Proveedor $proveedor): JsonResponse
    {
        $validated = $request->validate([
            'tipo' => ['required', Rule::in(array_keys(ProveedorDocumento::TIPOS))],
            'archivo' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
            'fecha_documento' => 'required|date|before_or_equal:today',
            'vigente_hasta' => 'nullable|date|after_or_equal:fecha_documento',
            'sentido' => 'nullable|in:positiva,negativa',
            'observaciones' => 'nullable|string|max:1000',
        ], [], ['archivo' => 'documento', 'fecha_documento' => 'fecha del documento', 'vigente_hasta' => 'vigente hasta']);
        $opinion = $validated['tipo'] === 'opinion_cumplimiento';
        if ($opinion && blank($validated['sentido'] ?? null)) {
            throw ValidationException::withMessages(['sentido' => 'Indica si la opinión del SAT es positiva o negativa.']);
        }
        $archivo = $request->file('archivo');
        $ruta = $archivo->storeAs("padron/{$proveedor->id}", now()->format('Ymd_His').'_'.$validated['tipo'].'.'.$archivo->getClientOriginalExtension(), 'local');
        $documento = ProveedorDocumento::create([
            'proveedor_id' => $proveedor->id,
            'tipo' => $validated['tipo'],
            'nombre_archivo' => mb_substr($archivo->getClientOriginalName(), 0, 255),
            'archivo_path' => $ruta,
            'fecha_documento' => $validated['fecha_documento'],
            // La opinión del SAT vale 30 días si no se captura otra vigencia.
            'vigente_hasta' => $validated['vigente_hasta'] ?? ($opinion ? CarbonImmutable::parse($validated['fecha_documento'])->addDays(ProveedorDocumento::DIAS_OPINION)->toDateString() : null),
            'sentido' => $opinion ? $validated['sentido'] : null,
            'observaciones' => filled($validated['observaciones'] ?? null) ? trim($validated['observaciones']) : null,
            'user_id' => $request->user()->id,
        ]);

        return response()->json(['id' => $documento->id, 'message' => ProveedorDocumento::TIPOS[$documento->tipo][0].' agregado al expediente.'], 201);
    }

    public function descargarDocumento(Proveedor $proveedor, ProveedorDocumento $documento)
    {
        abort_unless($documento->proveedor_id === $proveedor->id && Storage::disk('local')->exists($documento->archivo_path), 404);

        return Storage::disk('local')->download($documento->archivo_path, $documento->nombre_archivo);
    }

    public function quitarDocumento(Proveedor $proveedor, ProveedorDocumento $documento): JsonResponse
    {
        abort_unless($documento->proveedor_id === $proveedor->id, 404);
        $documento->delete();
        Storage::disk('local')->delete($documento->archivo_path);

        return response()->json(['message' => 'Documento quitado del expediente.']);
    }

    /** Emite (o renueva) la cédula: expediente completo y opinión del SAT positiva y vigente. Vale un año. */
    public function emitirCedula(Request $request, Proveedor $proveedor): JsonResponse
    {
        $validated = $request->validate(['observaciones' => 'nullable|string|max:1000']);
        if ($proveedor->compra_unica) {
            return $this->error('Los proveedores de compra única no llevan cédula. Si ya no es eventual, quítale la marca de compra única.');
        }
        if ($proveedor->padron_estado === 'suspendido') {
            return $this->error('El proveedor está suspendido: reactívalo antes de emitir su cédula.');
        }
        if ($faltan = $proveedor->faltantesParaCedula()) {
            throw ValidationException::withMessages(['cedula' => 'No se puede emitir la cédula. '.implode(' ', $faltan)]);
        }

        $cedula = DB::transaction(function () use ($proveedor, $validated, $request) {
            $hoy = CarbonImmutable::now('America/Mexico_City');
            $folio = (ProveedorCedula::where('año', $hoy->year)->lockForUpdate()->max('folio') ?? 0) + 1;
            $cedula = ProveedorCedula::create([
                'proveedor_id' => $proveedor->id,
                'folio' => $folio,
                'año' => $hoy->year,
                'numero' => sprintf('CED-%d-%04d', $hoy->year, $folio),
                'fecha_emision' => $hoy->toDateString(),
                'vigencia' => $hoy->addYear()->subDay()->toDateString(),
                'observaciones' => filled($validated['observaciones'] ?? null) ? trim($validated['observaciones']) : null,
                'user_id' => $request->user()->id,
            ]);
            $proveedor->update(['padron_estado' => 'registrado', 'vigencia' => $cedula->vigencia, 'padron_observaciones' => null]);

            return $cedula;
        });

        return response()->json(['message' => "Cédula {$cedula->numero} emitida, vigente hasta el {$cedula->vigencia->format('d/m/Y')}."], 201);
    }

    public function cedulaPdf(Proveedor $proveedor)
    {
        $cedula = $proveedor->cedulas()->with('usuario:id,name')->first();
        abort_unless($cedula, 404);
        $pdf = Pdf::loadView('padron.cedula', ['proveedor' => $proveedor, 'cedula' => $cedula, 'giro' => $proveedor->giro])->setPaper('letter');

        return $pdf->download("{$cedula->numero}.pdf");
    }

    /** rechazar (en revisión) | suspender (registrado) | reactivar (suspendido). */
    public function cambiarEstado(Request $request, Proveedor $proveedor, string $accion): JsonResponse
    {
        $validated = $request->validate(['motivo' => $accion === 'reactivar' ? 'nullable|string|max:1000' : 'required|string|min:5|max:1000'],
            ['motivo.required' => 'Escribe el motivo.']);
        $motivo = trim((string) ($validated['motivo'] ?? ''));
        [$de, $a, $mensaje] = match ($accion) {
            'rechazar' => ['en_revision', 'rechazado', "{$proveedor->nombre} rechazado en el padrón."],
            'suspender' => ['registrado', 'suspendido', "{$proveedor->nombre} suspendido: no se le pueden asignar órdenes de compra."],
            'reactivar' => ['suspendido', 'registrado', "{$proveedor->nombre} reactivado en el padrón."],
        };
        if ($proveedor->padron_estado !== $de) {
            return $this->error('El proveedor no está '.mb_strtolower(Proveedor::ESTADOS_PADRON[$de] ?? $de).'.');
        }
        $proveedor->update(['padron_estado' => $a, 'padron_observaciones' => $motivo !== '' ? $motivo : null]);

        return response()->json(['message' => $mensaje]);
    }

    // ----------------------------------------------------------------

    private function fila(Proveedor $p): array
    {
        $cedula = $p->relationLoaded('cedulas') ? $p->cedulas->first() : null;
        $estado = $p->estadoPadron();

        return [
            'id' => $p->id,
            'nombre' => $p->nombre,
            'rfc' => $p->rfc,
            'giro' => $p->giro,
            'compra_unica' => (bool) $p->compra_unica,
            'activo' => (bool) $p->activo,
            'estado' => $estado,
            'estado_texto' => Proveedor::ESTADOS_PADRON[$estado],
            'vigencia' => $p->vigencia?->toDateString(),
            'dias' => $p->vigencia ? (int) now('America/Mexico_City')->startOfDay()->diffInDays($p->vigencia, false) : null,
            'cedula' => $cedula?->numero,
        ];
    }

    private function validar(Request $request, ?Proveedor $proveedor = null): array
    {
        $request->merge(['rfc' => mb_strtoupper(trim((string) $request->input('rfc')))]);
        $validated = $request->validate([
            'nombre' => 'required|string|max:300',
            'rfc' => ['required', 'string', 'max:13', 'regex:'.self::RFC, Rule::unique('proveedores', 'rfc')->ignore($proveedor?->id)],
            'tipo_persona' => 'nullable|in:fisica,moral',
            'representante_legal' => 'nullable|string|max:300',
            'giro' => 'nullable|string|max:500',
            'regimen_fiscal' => 'nullable|string|max:200',
            'correo' => 'nullable|email|max:150',
            'telefono' => 'nullable|string|max:30',
            'domicilio' => 'nullable|string|max:500',
            'clabe' => ['nullable', 'regex:/^\d{18}$/'],
            'compra_unica' => 'boolean',
        ], ['rfc.regex' => 'El RFC no tiene un formato válido (12 caracteres persona moral, 13 persona física).', 'rfc.unique' => 'Ya hay un proveedor con ese RFC.',
            'clabe.regex' => 'La CLABE debe tener 18 dígitos.']);
        // El tipo de persona se deduce del RFC si no se indica.
        $validated['tipo_persona'] ??= strlen($validated['rfc']) === 12 ? 'moral' : 'fisica';
        foreach (['nombre', 'representante_legal', 'giro', 'regimen_fiscal', 'domicilio'] as $campo) {
            if (filled($validated[$campo] ?? null)) {
                $validated[$campo] = mb_strtoupper(trim($validated[$campo]));
            }
        }
        $validated['compra_unica'] = (bool) ($validated['compra_unica'] ?? false);

        return $validated;
    }

    private function error(string $mensaje): JsonResponse
    {
        return response()->json(['message' => $mensaje], 422);
    }
}
