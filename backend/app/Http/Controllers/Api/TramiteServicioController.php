<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EgresoUnidadAdministrativa;
use App\Models\OrdenCompra;
use App\Models\PresupuestoEgreso;
use App\Models\TramiteServicio;
use App\Models\TramiteServicioFactura;
use App\Services\FacturasTramite;
use App\Services\GeneradorTramiteServicio;
use App\Services\PresupuestoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Trámites con las facturas primero (nuevo; SIEG no los tenía). Dos tipos, según la ruta (tipo):
 *  - servicio (TS-…): facturas de un mismo proveedor registrado; cada factura con su partida; al validar se
 *    generan requisición, orden de compra y CLC.
 *  - fondo (FR-…, fondo revolvente): muchas facturas de distintos proveedores (registrados o no), de hasta
 *    $2,000 cada una; cada artículo con su partida. Va directo a Control Presupuestal, que valida o no cada factura
 *    por separado; las no validadas regresan al área (las corrige, sustituye su XML o las quita). Al validar el
 *    fondo se generan requisición y CLC a favor de un trabajador (sin banco, cuenta, CLABE ni RFC), que queda ya
 *    recibida en Control Presupuestal.
 *  - combustible (BC-…, bitácora de combustible): como un servicio, pero se crea al cargar el Excel del proveedor
 *    (CombustibleController) y solo acepta las facturas de sus tickets.
 * El área captura y lo envía a validar; quien tenga el permiso de validar lo valida o lo regresa con observaciones.
 */
class TramiteServicioController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = TramiteServicio::where('tipo', $this->tipo())
            ->with('unidadAdministrativa:id_cat_egreso_unidad_administrativa,clave,nombre', 'requisicion:id_tb_egreso_requisicion,folio_completo')
            ->withCount('facturas')->withSum('facturas', 'total')->withSum('facturas', 'isr');

        if (! $this->veTodos($user)) {
            $query->whereIn('id_cat_egreso_unidad_administrativa', EgresoUnidadAdministrativa::idsMismaClave($user->id_cat_egreso_unidad_administrativa) ?: [0]);
        }
        if ($estado = $request->input('estado')) {
            $query->where('estado', $estado);
        }
        if ($buscar = trim((string) $request->input('buscar'))) {
            $like = "%{$buscar}%";
            $query->where(fn ($q) => $q->where('folio_completo', 'like', $like)->orWhere('concepto', 'like', $like)
                ->orWhere('proveedor_rfc', 'like', $like)->orWhere('proveedor_nombre', 'like', $like)->orWhere('trabajador_nombre', 'like', $like));
        }

        return response()->json([
            ...$query->orderByDesc('id')->paginate(20)->through(fn (TramiteServicio $t) => [
                'id' => $t->id,
                'folio' => $t->folio_completo,
                'fecha' => $t->created_at?->toIso8601String(),
                'unidad' => $t->unidadAdministrativa ? trim($t->unidadAdministrativa->clave.' '.$t->unidadAdministrativa->nombre) : null,
                'concepto' => $t->concepto,
                // Servicio: el proveedor; fondo: el trabajador.
                'proveedor' => $t->esFondo() ? $t->trabajador_nombre : $t->proveedor_nombre,
                'rfc' => $t->esFondo() ? ($t->trabajador_numero ? "No. empleado {$t->trabajador_numero}" : null) : $t->proveedor_rfc,
                'facturas' => $t->facturas_count,
                'importe' => round((float) $t->facturas_sum_total + (float) $t->facturas_sum_isr, 2),
                'estado' => $t->estado,
                'estado_texto' => TramiteServicio::ESTADOS[$t->estado] ?? $t->estado,
                'requisicion_id' => $t->id_tb_egreso_requisicion,
                'requisicion' => $t->requisicion?->folio_completo,
            ])->toArray(),
            'estados' => TramiteServicio::ESTADOS,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if ($this->tipo() === 'combustible') {
            return $this->error('Las bitácoras de combustible se crean al cargar el Excel del proveedor.');
        }
        $validated = $request->validate([
            'id_cat_egreso_unidad_administrativa' => 'required|integer',
            'concepto' => 'required|string|max:3000',
            ...$this->reglasTrabajador(),
        ], [], ['id_cat_egreso_unidad_administrativa' => 'secretaría', 'concepto' => 'concepto']);
        $this->autorizarUnidad((int) $validated['id_cat_egreso_unidad_administrativa']);
        $tipo = $this->tipo();

        $tramite = DB::transaction(function () use ($validated, $request, $tipo) {
            $año = (int) now('America/Mexico_City')->format('Y');
            $folio = (TramiteServicio::where('tipo', $tipo)->where('año', $año)->lockForUpdate()->max('folio') ?? 0) + 1;

            return TramiteServicio::create([
                'tipo' => $tipo,
                'folio' => $folio,
                'año' => $año,
                'folio_completo' => sprintf('%s-%d-%04d', TramiteServicio::TIPOS[$tipo]['prefijo'], $año, $folio),
                'id_cat_egreso_unidad_administrativa' => $validated['id_cat_egreso_unidad_administrativa'],
                'concepto' => mb_strtoupper(trim($validated['concepto'])),
                ...$this->datosTrabajador($validated),
                'estado' => 'captura',
                'user_id' => $request->user()->id,
            ]);
        });

        return response()->json(['id' => $tramite->id, 'message' => "{$tramite->folio_completo} creado. Sube las facturas."], 201);
    }

    public function show(Request $request, TramiteServicio $tramite): JsonResponse
    {
        $this->autorizar($tramite);
        $user = $request->user();
        $fondo = $tramite->esFondo();
        $tramite->load('facturas.revisor:id,name', 'unidadAdministrativa', 'usuario:id,name', 'validador:id,name', 'requisicion:id_tb_egreso_requisicion,folio_completo', 'ordenCompra');
        $clave = trim((string) $tramite->unidadAdministrativa?->clave);
        $nombres = PresupuestoEgreso::where('CLAVE', $clave)->get(['PARTIDA', 'NOMBRE_PARTIDA'])
            ->mapWithKeys(fn ($p) => [trim((string) $p->PARTIDA) => trim((string) $p->NOMBRE_PARTIDA)]);
        $proveedor = $fondo ? null : $tramite->proveedor();
        $editable = $tramite->editable() && $user->can($tramite->permiso('create'));
        $oc = $tramite->ordenCompra;

        return response()->json([
            'tramite' => [
                'id' => $tramite->id,
                'tipo' => $tramite->tipo,
                'folio' => $tramite->folio_completo,
                'fecha' => $tramite->created_at?->toIso8601String(),
                'id_unidad' => $tramite->id_cat_egreso_unidad_administrativa,
                'unidad' => $tramite->unidadAdministrativa ? trim($tramite->unidadAdministrativa->clave.' — '.$tramite->unidadAdministrativa->nombre) : null,
                'unidad_clave' => $clave,
                'concepto' => $tramite->concepto,
                'estado' => $tramite->estado,
                'estado_texto' => TramiteServicio::ESTADOS[$tramite->estado] ?? $tramite->estado,
                'observaciones' => $tramite->observaciones,
                'capturo' => $tramite->usuario?->name,
                'enviado_at' => $tramite->enviado_at?->toIso8601String(),
                'validado_at' => $tramite->validado_at?->toIso8601String(),
                'validado_por' => $tramite->validador?->name,
                'proveedor_rfc' => $tramite->proveedor_rfc,
                'proveedor_nombre' => $tramite->proveedor_nombre,
                // Proveedor en el padrón (servicio): si no está registrado o vigente, se puede capturar pero no avanzar.
                'proveedor' => $proveedor ? ['id' => $proveedor->id, 'nombre' => $proveedor->nombre, 'activo' => (bool) $proveedor->activo] : null,
                'impedimento_proveedor' => $tramite->impedimentoProveedor(),
                // Fondo revolvente: el trabajador a cuyo favor va la CLC.
                'trabajador_nombre' => $tramite->trabajador_nombre,
                'trabajador_numero' => $tramite->trabajador_numero,
                'trabajador_puesto' => $tramite->trabajador_puesto,
                'limite_factura' => $fondo ? TramiteServicio::LIMITE_FACTURA_FONDO : null,
                'importe' => round($tramite->facturas->sum(fn ($f) => $f->importe()), 2),
                'neto' => round($tramite->facturas->sum('total'), 2),
                'isr' => round($tramite->facturas->sum('isr'), 2),
                'requisicion_id' => $tramite->id_tb_egreso_requisicion,
                'requisicion' => $tramite->requisicion?->folio_completo,
                'orden' => $oc ? ['id' => $oc->id, 'folio' => $oc->folio_completo, 'clc' => $oc->clc_folio_completo, 'clc_estado' => $oc->clc_folio_completo ? \App\Services\TramiteClc::descripcion($oc->clc_estado) : null] : null,
            ],
            'facturas' => $tramite->facturas->map(fn (TramiteServicioFactura $f) => [
                'id' => $f->id,
                'uuid' => $f->uuid,
                'serie_folio' => $f->serieFolio(),
                'fecha' => $f->fecha?->toIso8601String(),
                'emisor' => $f->nombre_emisor,
                'rfc_emisor' => $f->rfc_emisor,
                'subtotal' => $f->subtotal,
                'descuento' => $f->descuento,
                'iva' => $f->iva,
                'ieps' => $f->ieps,
                'isr' => $f->isr,
                'total' => $f->total,
                'importe' => $f->importe(),
                // En un fondo, cada artículo trae su partida / proyecto / fuente.
                'conceptos' => collect($f->conceptos ?? [])->map(fn ($a) => [...$a, 'nombre_partida' => filled($a['partida'] ?? null) ? ($nombres[trim($a['partida'])] ?? null) : null])->values(),
                'por_partida' => $fondo ? collect($f->porPartida())->map(fn ($p) => [...$p, 'nombre_partida' => $nombres[trim($p['partida'])] ?? null])->values() : [],
                'sat_estado' => $f->sat_estado,
                'fuente' => $f->fuente,
                'proyecto' => $f->proyecto,
                'partida' => $f->partida,
                'nombre_partida' => $f->partida ? ($nombres[trim($f->partida)] ?? null) : null,
                'concepto' => $f->concepto,
                'tipo_gasto' => $f->tipo_gasto,
                'comision' => $f->comision,
                'pdf' => $f->pdf_path !== null,
                'clasificada' => $f->clasificada($fondo),
                // Fondo: la revisión de Control Presupuestal de esta factura.
                'revision' => $f->revision,
                'revision_texto' => $fondo ? (TramiteServicioFactura::REVISIONES[$f->revision] ?? 'Por revisar') : null,
                'revision_observaciones' => $f->revision_observaciones,
                'revisada_por' => $f->revisor?->name,
                'revisada_at' => $f->revisada_at?->toIso8601String(),
            ]),
            // Fondo: catálogo de tipos de gasto y cuánto va de cada uno.
            'tipos_gasto' => $fondo ? TramiteServicio::TIPOS_GASTO_FONDO : null,
            'por_tipo_gasto' => $fondo ? $tramite->facturas->groupBy(fn ($f) => $f->tipo_gasto ?? '')
                ->map(fn ($g, $tipo) => ['tipo' => $tipo ?: null, 'nombre' => TramiteServicio::TIPOS_GASTO_FONDO[$tipo] ?? 'Sin clasificar', 'facturas' => $g->count(),
                    'importe' => round($g->sum(fn ($f) => $f->importe()), 2)])->sortByDesc('importe')->values() : [],
            // Líneas de presupuesto de la secretaría para clasificar (solo mientras se captura).
            'lineas' => $editable ? app(PresupuestoService::class)->lineas([$clave])
                ->when(PresupuestoService::restringeCapitulo1000($user), fn ($ls) => $ls->reject(fn ($l) => PresupuestoService::esCapitulo1000($l['partida'])))
                ->map(fn ($l) => collect($l)->only(['fuente', 'nombre_fuente', 'proyecto', 'nombre_proyecto', 'partida', 'nombre_partida', 'disponible']))
                ->values() : [],
            'puede' => [
                'editar' => $editable,
                'enviar' => $editable,
                'validar' => $tramite->estado === 'por_validar' && $user->can($tramite->permiso('validar')),
                // Fondo: Control Presupuestal valida o no cada factura antes de validar el fondo.
                'revisar' => $fondo && $tramite->estado === 'por_validar' && $user->can($tramite->permiso('validar')),
                'cancelar' => $this->puedeCancelar($tramite, $user),
                // Fondo validado: al cancelarlo se cancelan también su CLC y su requisición.
                'cancelar_todo' => $tramite->estado === 'validado',
                'documentos' => $tramite->estado === 'validado',
            ],
            'combustible' => $tramite->esCombustible() ? $this->resumenCombustible($tramite) : null,
        ]);
    }

    /** Bitácora de combustible: su carga del Excel y, por cada factura del Excel, sus tickets y si ya tiene XML. */
    private function resumenCombustible(TramiteServicio $tramite): array
    {
        $lote = $tramite->lote;
        $totales = $tramite->facturas->keyBy('id');
        $porFactura = $tramite->cargas()->reorder()->toBase()
            ->selectRaw('factura_folio, MAX(tramite_servicio_factura_id) AS factura_id, COUNT(*) AS tickets, SUM(litros) AS litros, SUM(importe) AS importe, MAX(fecha_factura) AS fecha_factura')
            ->groupBy('factura_folio')->orderBy('factura_folio')->get();

        return [
            'lote_id' => $lote?->id,
            'periodo' => $lote?->periodo,
            'archivo' => $lote?->archivo_nombre,
            'tickets' => (int) $porFactura->sum('tickets'),
            'litros' => round((float) $porFactura->sum('litros'), 3),
            'importe' => round((float) $porFactura->sum('importe'), 2),
            'facturas' => $porFactura->map(function ($f) use ($totales) {
                $xml = $f->factura_id ? $totales->get((int) $f->factura_id) : null;

                return [
                    'folio' => $f->factura_folio,
                    'fecha_factura' => $f->fecha_factura,
                    'tickets' => (int) $f->tickets,
                    'litros' => round((float) $f->litros, 3),
                    'importe' => round((float) $f->importe, 2),
                    'factura_id' => $xml?->id,
                    'total_xml' => $xml?->total,
                    'diferencia' => $xml ? round($xml->total - (float) $f->importe, 2) : null,
                ];
            })->values(),
        ];
    }

    public function update(Request $request, TramiteServicio $tramite): JsonResponse
    {
        $this->exigirEditable($tramite);
        $validated = $request->validate(['concepto' => 'required|string|max:3000', ...$this->reglasTrabajador()]);
        $tramite->update(['concepto' => mb_strtoupper(trim($validated['concepto'])), ...($tramite->esFondo() ? $this->datosTrabajador($validated) : [])]);

        return response()->json(['message' => 'Datos actualizados.']);
    }

    /** Sube una factura (XML); las validaciones están en FacturasTramite. */
    public function subirFactura(Request $request, TramiteServicio $tramite, FacturasTramite $facturas): JsonResponse
    {
        $this->exigirEditable($tramite);
        $request->validate(['xml' => 'required|file|max:2048'], [], ['xml' => 'factura (XML)']);
        $r = $facturas->agregar($tramite, file_get_contents($request->file('xml')->getRealPath()));

        return response()->json(['id' => $r['factura']->id, 'message' => $r['message'], 'aviso' => $r['aviso']], 201);
    }

    /**
     * Clasifica una factura. Servicio: partida / proyecto / fuente de toda la factura. Fondo: los de cada artículo
     * (articulos[i]), en el orden de la factura. En ambos, el concepto.
     */
    public function clasificarFactura(Request $request, TramiteServicio $tramite, TramiteServicioFactura $factura): JsonResponse
    {
        $this->exigirEditable($tramite);
        abort_unless($factura->tramite_servicio_id === $tramite->id, 404);
        $unidad = $tramite->unidadAdministrativa?->clave;
        $existe = fn (?string $fuente, ?string $proyecto, ?string $partida) => app(PresupuestoService::class)
            ->linea(PresupuestoService::clave($unidad, $fuente ?? '', $proyecto ?? '', $partida ?? '')) !== null;
        $capitulo1000 = fn (?string $partida) => PresupuestoService::esCapitulo1000($partida) && PresupuestoService::restringeCapitulo1000($request->user());

        if ($tramite->esFondo()) {
            $validated = $request->validate([
                'concepto' => 'required|string|max:3000',
                'tipo_gasto' => 'required|in:'.implode(',', array_keys(TramiteServicio::TIPOS_GASTO_FONDO)),
                'comision' => 'nullable|required_if:tipo_gasto,viaticos|string|max:2000',
                'articulos' => 'required|array',
                'articulos.*.partida' => 'nullable|string|max:200',
                'articulos.*.proyecto' => 'nullable|string|max:200',
                'articulos.*.fuente' => 'nullable|string|max:200',
            ], [
                'concepto.required' => 'Captura el concepto de la factura.',
                'tipo_gasto.required' => 'Elige el tipo de gasto.',
                'tipo_gasto.in' => 'Elige un tipo de gasto de la lista.',
                'comision.required_if' => 'En viáticos captura la comisión: destino, fechas y motivo.',
            ]);
            $articulos = $factura->conceptos ?? [];
            if (count($validated['articulos']) !== count($articulos)) {
                throw ValidationException::withMessages(['articulos' => 'La clasificación no coincide con los artículos de la factura.']);
            }
            foreach ($validated['articulos'] as $i => $a) {
                if ((filled($a['partida'] ?? null) || filled($a['proyecto'] ?? null) || filled($a['fuente'] ?? null)) && ! $existe($a['fuente'] ?? null, $a['proyecto'] ?? null, $a['partida'] ?? null)) {
                    throw ValidationException::withMessages(["articulos.{$i}.partida" => 'Artículo '.($i + 1).': esa combinación de partida, proyecto y fuente no existe en el presupuesto de la secretaría.']);
                }
                if ($capitulo1000($a['partida'] ?? null)) {
                    throw ValidationException::withMessages(["articulos.{$i}.partida" => 'Artículo '.($i + 1).': '.PresupuestoService::MENSAJE_CAPITULO_1000]);
                }
                $articulos[$i] = [...$articulos[$i], 'partida' => $a['partida'] ?? null, 'proyecto' => $a['proyecto'] ?? null, 'fuente' => $a['fuente'] ?? null];
            }
            $factura->update(['conceptos' => $articulos, 'concepto' => mb_strtoupper(trim($validated['concepto'])), 'tipo_gasto' => $validated['tipo_gasto'],
                'comision' => $validated['tipo_gasto'] === 'viaticos' ? mb_strtoupper(trim($validated['comision'])) : null]);
            $factura->reiniciarRevision();

            return response()->json(['message' => 'Factura '.($factura->serieFolio() ?? $factura->uuid).' clasificada.']);
        }

        $validated = $request->validate([
            'fuente' => 'nullable|string|max:200',
            'proyecto' => 'nullable|string|max:200',
            'partida' => 'nullable|string|max:200',
            'concepto' => 'required|string|max:3000',
        ], ['concepto.required' => 'Captura el concepto de la factura.']);
        if ((filled($validated['partida']) || filled($validated['proyecto']) || filled($validated['fuente'])) && ! $existe($validated['fuente'], $validated['proyecto'], $validated['partida'])) {
            throw ValidationException::withMessages(['partida' => 'Esa combinación de partida, proyecto y fuente no existe en el presupuesto de la secretaría.']);
        }
        if ($capitulo1000($validated['partida'])) {
            throw ValidationException::withMessages(['partida' => PresupuestoService::MENSAJE_CAPITULO_1000]);
        }
        $factura->update([...$validated, 'concepto' => mb_strtoupper(trim($validated['concepto']))]);

        return response()->json(['message' => 'Factura clasificada.']);
    }

    public function quitarFactura(TramiteServicio $tramite, TramiteServicioFactura $factura): JsonResponse
    {
        $this->exigirEditable($tramite);
        abort_unless($factura->tramite_servicio_id === $tramite->id, 404);
        $rutas = array_filter([$factura->xml_path, $factura->pdf_path]);
        DB::transaction(function () use ($tramite, $factura) {
            $factura->delete();
            if (! $tramite->esFondo() && ! $tramite->facturas()->exists()) {
                $tramite->update(['proveedor_rfc' => null, 'proveedor_nombre' => null]);
            }
        });
        Storage::disk('local')->delete($rutas);

        return response()->json(['message' => 'Factura quitada.']);
    }

    /**
     * Sustituye el XML de una factura (p. ej. una no validada): se agrega la nueva con las mismas validaciones y se
     * quita la anterior. Si la nueva no pasa, la anterior se queda.
     */
    public function sustituirXml(Request $request, TramiteServicio $tramite, TramiteServicioFactura $factura, FacturasTramite $facturas): JsonResponse
    {
        $this->exigirEditable($tramite);
        abort_unless($factura->tramite_servicio_id === $tramite->id, 404);
        $request->validate(['xml' => 'required|file|max:2048'], [], ['xml' => 'factura (XML)']);
        $anterior = $factura->serieFolio() ?? $factura->uuid;
        $rutas = array_filter([$factura->xml_path, $factura->pdf_path]);
        $r = $facturas->agregar($tramite, file_get_contents($request->file('xml')->getRealPath()));
        $factura->delete();
        Storage::disk('local')->delete(array_diff($rutas, array_filter([$r['factura']->xml_path])));

        return response()->json(['id' => $r['factura']->id, 'message' => "Factura {$anterior} sustituida: {$r['message']} Clasifícala.", 'aviso' => $r['aviso']], 201);
    }

    /**
     * Fondo revolvente: Control Presupuestal revisa una factura del fondo enviado a validar: la valida o no (con la
     * observación para el área). El fondo se valida cuando todas están validadas.
     */
    public function revisarFactura(Request $request, TramiteServicio $tramite, TramiteServicioFactura $factura): JsonResponse
    {
        $this->autorizar($tramite);
        abort_unless($tramite->esFondo() && $request->user()->can($tramite->permiso('validar')), 403);
        abort_unless($factura->tramite_servicio_id === $tramite->id, 404);
        if ($tramite->estado !== 'por_validar') {
            return $this->error('Las facturas se revisan cuando el fondo está enviado a validar.');
        }
        $validated = $request->validate([
            'revision' => 'required|in:validada,rechazada,pendiente',
            'observaciones' => 'nullable|required_if:revision,rechazada|string|min:5|max:2000',
        ], [
            'observaciones.required_if' => 'Escribe por qué no se valida la factura.',
            'observaciones.min' => 'Explica qué hay que corregir en la factura.',
        ]);
        $pendiente = $validated['revision'] === 'pendiente';
        $factura->update([
            'revision' => $pendiente ? null : $validated['revision'],
            'revision_observaciones' => $validated['revision'] === 'rechazada' ? trim($validated['observaciones']) : null,
            'revisada_at' => $pendiente ? null : now(),
            'revisada_user_id' => $pendiente ? null : $request->user()->id,
        ]);
        $nombre = $factura->serieFolio() ?? $factura->uuid;

        return response()->json(['message' => match ($validated['revision']) {
            'validada' => "Factura {$nombre} validada.",
            'rechazada' => "Factura {$nombre} no validada; regresa el fondo al área para que la corrija o la quite.",
            default => "Factura {$nombre} otra vez por revisar.",
        }]);
    }

    public function descargarXml(TramiteServicio $tramite, TramiteServicioFactura $factura)
    {
        $this->autorizar($tramite);
        abort_unless($factura->tramite_servicio_id === $tramite->id && Storage::disk('local')->exists($factura->xml_path), 404);

        return Storage::disk('local')->download($factura->xml_path, "{$factura->uuid}.xml", ['Content-Type' => 'application/xml']);
    }

    /** PDF (representación impresa) de una factura del trámite; si ya tenía uno, lo sustituye. */
    public function subirPdf(Request $request, TramiteServicio $tramite, TramiteServicioFactura $factura): JsonResponse
    {
        $this->exigirEditable($tramite);
        abort_unless($factura->tramite_servicio_id === $tramite->id, 404);
        $request->validate(['pdf' => 'required|file|mimes:pdf|max:5120'], ['pdf.mimes' => 'El archivo debe ser un PDF.', 'pdf.max' => 'El PDF puede ser de hasta 5 MB.']);
        $ruta = "tramites-servicio/{$tramite->año}/{$tramite->folio_completo}_{$factura->uuid}.pdf";
        Storage::disk('local')->put($ruta, file_get_contents($request->file('pdf')->getRealPath()));
        $factura->update(['pdf_path' => $ruta]);
        $factura->reiniciarRevision();

        return response()->json(['message' => 'PDF de la factura guardado.']);
    }

    public function descargarPdf(TramiteServicio $tramite, TramiteServicioFactura $factura)
    {
        $this->autorizar($tramite);
        abort_unless($factura->tramite_servicio_id === $tramite->id && $factura->pdf_path && Storage::disk('local')->exists($factura->pdf_path), 404);

        return Storage::disk('local')->download($factura->pdf_path, "{$factura->uuid}.pdf", ['Content-Type' => 'application/pdf']);
    }

    /** El área lo envía a validar: con facturas clasificadas, y el proveedor registrado (servicio) o el trabajador (fondo). */
    public function enviar(TramiteServicio $tramite): JsonResponse
    {
        $this->exigirEditable($tramite);
        $tramite->load('facturas');
        $fondo = $tramite->esFondo();
        if ($tramite->facturas->isEmpty()) {
            return $this->error('Sube al menos una factura.');
        }
        if ($sinClasificar = $tramite->facturas->reject(fn ($f) => $f->clasificada($fondo))->count()) {
            return $this->error($fondo
                ? "Falta el tipo de gasto (en viáticos, la comisión), el concepto o la partida, proyecto y fuente de los artículos de {$sinClasificar} factura(s)."
                : "Falta asignar partida, proyecto, fuente y concepto a {$sinClasificar} factura(s).");
        }
        if ($fondo && ! $tramite->trabajador_nombre) {
            return $this->error('Captura el nombre del trabajador a cuyo favor va la CLC.');
        }
        if ($tramite->esCombustible()) {
            $faltan = $tramite->cargas()->whereNull('tramite_servicio_factura_id')->reorder('factura_folio')->distinct()->pluck('factura_folio');
            if ($faltan->isNotEmpty()) {
                return $this->error("Faltan los XML de {$faltan->count()} factura(s) del Excel del proveedor: {$faltan->take(10)->implode(', ')}".($faltan->count() > 10 ? '…' : '.'));
            }
        }
        if ($impedimento = $tramite->impedimentoProveedor()) {
            return $this->error($impedimento);
        }

        $tramite->update(['estado' => 'por_validar', 'enviado_at' => now(), 'observaciones' => $fondo ? null : $tramite->observaciones]);

        return response()->json(['message' => $fondo
            ? "{$tramite->folio_completo} enviado a Control Presupuestal: revisa cada factura y lo valida."
            : "{$tramite->folio_completo} enviado a validar."]);
    }

    /** Lo valida quien tiene el permiso: se generan la requisición y la CLC (y en servicios, la orden de compra). */
    public function validar(Request $request, TramiteServicio $tramite, GeneradorTramiteServicio $generador): JsonResponse
    {
        $this->autorizar($tramite);
        abort_unless($request->user()->can($tramite->permiso('validar')), 403);
        if ($tramite->esFondo() && $tramite->estado === 'por_validar') {
            $facturas = $tramite->facturas()->get();
            if ($rechazadas = $facturas->where('revision', 'rechazada')->count()) {
                return $this->error("Hay {$rechazadas} factura(s) no validada(s): regresa el fondo al área para que las corrija o las quite.");
            }
            if ($pendientes = $facturas->where('revision', '!==', 'validada')->count()) {
                return $this->error("Falta revisar {$pendientes} factura(s): valida cada factura antes de validar el fondo.");
            }
        }
        $requisicion = $generador->generar($tramite, $request->user());
        $orden = OrdenCompra::where('tramite_servicio_id', $tramite->id)->first();

        return response()->json([
            'message' => $tramite->esFondo()
                ? "Fondo revolvente validado: requisición {$requisicion->folio_completo} y CLC {$orden?->clc_folio_completo} a favor de {$tramite->trabajador_nombre}; la CLC ya está recibida en Control Presupuestal. Ya se pueden imprimir."
                : ($tramite->esCombustible() ? 'Bitácora de combustible validada' : 'Trámite validado').": requisición {$requisicion->folio_completo}, orden de compra {$orden?->folio_completo} y CLC {$orden?->clc_folio_completo} generadas. Ya se pueden imprimir.",
        ]);
    }

    /** No lo valida: regresa al área con las observaciones para que lo corrija. */
    public function rechazar(Request $request, TramiteServicio $tramite): JsonResponse
    {
        $this->autorizar($tramite);
        abort_unless($request->user()->can($tramite->permiso('validar')), 403);
        if ($tramite->estado !== 'por_validar') {
            return $this->error('Solo se regresa uno enviado a validar.');
        }
        // Fondo: si ya hay facturas no validadas, sus observaciones bastan (las generales son opcionales).
        $rechazadas = $tramite->esFondo() ? $tramite->facturas()->where('revision', 'rechazada')->get() : collect();
        $validated = $request->validate(['observaciones' => ($rechazadas->isEmpty() ? 'required' : 'nullable').'|string|min:5|max:3000'],
            ['observaciones.required' => 'Escribe las observaciones para el área.', 'observaciones.min' => 'Explica qué hay que corregir.']);
        $observaciones = trim((string) ($validated['observaciones'] ?? ''))
            ?: "{$rechazadas->count()} factura(s) no validada(s): corrígelas, sustitúyelas o quítalas y vuelve a enviarlo.";
        $tramite->update(['estado' => 'rechazado', 'observaciones' => $observaciones]);

        return response()->json(['message' => "{$tramite->folio_completo} regresado al área con observaciones."]);
    }

    public function cancelar(Request $request, TramiteServicio $tramite, GeneradorTramiteServicio $generador): JsonResponse
    {
        $this->autorizar($tramite);
        if ($tramite->estado === 'validado' && ! $tramite->esFondo()) {
            return $this->error('Ya validado no se cancela aquí: se cancela su CLC y su requisición.');
        }
        if (! $this->puedeCancelar($tramite, $request->user())) {
            return $this->error($tramite->estado === 'cancelado' ? 'Ya está cancelado.' : 'No puedes cancelarlo en el estado en que está.');
        }
        $validated = $request->validate(['motivo' => 'required|string|min:5|max:1000'],
            ['motivo.required' => 'Indica el motivo de la cancelación.', 'motivo.min' => 'El motivo debe tener al menos 5 caracteres.']);
        $cancelado = $generador->cancelar($tramite, $request->user(), trim($validated['motivo']));

        return response()->json(['message' => "Cancelado: {$cancelado}. Sus facturas quedan libres para usarse en otro trámite."]);
    }

    /**
     * Antes de validarlo lo cancela quien captura o quien valida. Un fondo validado lo cancela quien valida (o un
     * administrador), y con él su CLC y su requisición.
     */
    private function puedeCancelar(TramiteServicio $tramite, $user): bool
    {
        if (in_array($tramite->estado, ['captura', 'rechazado', 'por_validar'], true)) {
            return $user->can($tramite->permiso('create')) || $user->can($tramite->permiso('validar'));
        }

        return $tramite->estado === 'validado' && $tramite->esFondo()
            && ($user->can($tramite->permiso('validar')) || $user->hasRole(['Super Admin', 'Admin']));
    }

    // ----------------------------------------------------------------

    /** servicio (rutas /servicios) o fondo (rutas /fondos). */
    private function tipo(): string
    {
        $tipo = request()->route('tipo') ?? 'servicio';

        return array_key_exists($tipo, TramiteServicio::TIPOS) ? $tipo : 'servicio';
    }

    private function permiso(string $accion): string
    {
        return TramiteServicio::TIPOS[$this->tipo()]['permiso'].'-'.$accion;
    }

    private function reglasTrabajador(): array
    {
        return $this->tipo() === 'fondo' ? [
            'trabajador_nombre' => 'nullable|string|max:300',
            'trabajador_numero' => 'nullable|string|max:30',
            'trabajador_puesto' => 'nullable|string|max:200',
        ] : [];
    }

    private function datosTrabajador(array $validated): array
    {
        return $this->tipo() === 'fondo' ? [
            'trabajador_nombre' => filled($validated['trabajador_nombre'] ?? null) ? mb_strtoupper(trim($validated['trabajador_nombre'])) : null,
            'trabajador_numero' => filled($validated['trabajador_numero'] ?? null) ? trim($validated['trabajador_numero']) : null,
            'trabajador_puesto' => filled($validated['trabajador_puesto'] ?? null) ? mb_strtoupper(trim($validated['trabajador_puesto'])) : null,
        ] : [];
    }

    /**
     * Ve todas las secretarías quien valida y los administradores; en combustible, también la unidad de combustibles
     * (combustible-cargar). Quien solo captura (cada secretaría) ve las suyas.
     */
    private function veTodos($user): bool
    {
        return $user->can($this->permiso('validar')) || $user->hasRole(['Super Admin', 'Admin'])
            || ($this->tipo() === 'combustible' && $user->can('combustible-cargar'));
    }

    private function autorizar(TramiteServicio $tramite): void
    {
        // La ruta (servicios / fondos) debe coincidir con el tipo del registro.
        abort_unless($tramite->tipo === $this->tipo(), 404);
        if ($this->veTodos(auth()->user())) {
            return;
        }
        $this->autorizarUnidad((int) $tramite->id_cat_egreso_unidad_administrativa);
    }

    private function autorizarUnidad(int $unidad): void
    {
        $user = auth()->user();
        if ($this->veTodos($user)) {
            return;
        }
        abort_unless(in_array($unidad, EgresoUnidadAdministrativa::idsMismaClave($user->id_cat_egreso_unidad_administrativa), true), 403,
            'No pertenece a tu secretaría.');
    }

    private function exigirEditable(TramiteServicio $tramite): void
    {
        $this->autorizar($tramite);
        abort_unless(auth()->user()->can($tramite->permiso('create')), 403);
        if (! $tramite->editable()) {
            throw ValidationException::withMessages(['tramite' => 'Ya no se puede modificar (está '.mb_strtolower(TramiteServicio::ESTADOS[$tramite->estado] ?? $tramite->estado).').']);
        }
    }

    private function error(string $mensaje): JsonResponse
    {
        return response()->json(['message' => $mensaje], 422);
    }
}
