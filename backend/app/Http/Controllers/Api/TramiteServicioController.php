<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EgresoUnidadAdministrativa;
use App\Models\OrdenCompra;
use App\Models\PresupuestoEgreso;
use App\Models\TramiteServicio;
use App\Models\TramiteServicioFactura;
use App\Services\Cfdi;
use App\Services\EstadoCfdiSat;
use App\Services\GeneradorTramiteServicio;
use App\Services\PresupuestoService;
use Illuminate\Database\UniqueConstraintViolationException;
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
 *    $2,000 cada una; cada artículo con su partida; al validar se generan requisición y CLC a favor de un
 *    trabajador (sin banco, cuenta, CLABE ni RFC).
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
        $tramite->load('facturas', 'unidadAdministrativa', 'usuario:id,name', 'validador:id,name', 'requisicion:id_tb_egreso_requisicion,folio_completo', 'ordenCompra');
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
                'clasificada' => $f->clasificada($fondo),
            ]),
            // Líneas de presupuesto de la secretaría para clasificar (solo mientras se captura).
            'lineas' => $editable ? app(PresupuestoService::class)->lineas([$clave])
                ->map(fn ($l) => collect($l)->only(['fuente', 'nombre_fuente', 'proyecto', 'nombre_proyecto', 'partida', 'nombre_partida', 'disponible']))
                ->values() : [],
            'puede' => [
                'editar' => $editable,
                'enviar' => $editable,
                'validar' => $tramite->estado === 'por_validar' && $user->can($tramite->permiso('validar')),
                'cancelar' => in_array($tramite->estado, ['captura', 'rechazado', 'por_validar'], true)
                    && ($user->can($tramite->permiso('create')) || $user->can($tramite->permiso('validar'))),
                'documentos' => $tramite->estado === 'validado',
            ],
        ]);
    }

    public function update(Request $request, TramiteServicio $tramite): JsonResponse
    {
        $this->exigirEditable($tramite);
        $validated = $request->validate(['concepto' => 'required|string|max:3000', ...$this->reglasTrabajador()]);
        $tramite->update(['concepto' => mb_strtoupper(trim($validated['concepto'])), ...($tramite->esFondo() ? $this->datosTrabajador($validated) : [])]);

        return response()->json(['message' => 'Datos actualizados.']);
    }

    /**
     * Sube una factura (XML): CFDI de ingreso timbrado, a nombre del Ayuntamiento, vigente en el SAT y no cargado
     * antes. Servicio: del mismo proveedor que las demás (se vincula por RFC). Fondo: de cualquier proveedor y de
     * hasta $2,000.
     */
    public function subirFactura(Request $request, TramiteServicio $tramite, EstadoCfdiSat $sat): JsonResponse
    {
        $this->exigirEditable($tramite);
        $fondo = $tramite->esFondo();
        $request->validate(['xml' => 'required|file|max:2048'], [], ['xml' => 'factura (XML)']);
        $contenido = file_get_contents($request->file('xml')->getRealPath());
        $cfdi = Cfdi::leer($contenido);

        $errores = [];
        if (! $cfdi['uuid']) {
            $errores[] = 'El XML no trae timbre fiscal (UUID); no es un CFDI timbrado.';
        }
        if ($cfdi['tipo'] && $cfdi['tipo'] !== 'I') {
            $errores[] = "El CFDI es de tipo {$cfdi['tipo']}; solo se aceptan facturas de ingreso (tipo I).";
        }
        $receptor = (string) config('services.sat.rfc_receptor');
        if ($receptor && $cfdi['rfc_receptor'] && $cfdi['rfc_receptor'] !== $receptor) {
            $errores[] = "La factura está a nombre de {$cfdi['rfc_receptor']}, no del Ayuntamiento ({$receptor}).";
        }
        if ($fondo && $cfdi['total'] > TramiteServicio::LIMITE_FACTURA_FONDO) {
            $errores[] = sprintf('La factura es por $%s: en un fondo revolvente cada factura puede ser de hasta $%s.',
                number_format($cfdi['total'], 2), number_format(TramiteServicio::LIMITE_FACTURA_FONDO, 2));
        }
        if (! $fondo && $tramite->proveedor_rfc && $cfdi['rfc_emisor'] !== $tramite->proveedor_rfc) {
            $errores[] = "Todas las facturas del trámite deben ser del mismo proveedor ({$tramite->proveedor_rfc}); esta la emite {$cfdi['rfc_emisor']}.";
        }
        if ($cfdi['uuid']) {
            $errores = [...$errores, ...$this->duplicidades($cfdi['uuid'])];
        }
        if ($errores) {
            throw ValidationException::withMessages(['xml' => implode(' ', $errores)]);
        }

        $estado = $sat->consultar($cfdi['rfc_emisor'] ?? '', $cfdi['rfc_receptor'] ?? $receptor, $cfdi['total'], $cfdi['uuid'], $cfdi['sello']);
        if (! $estado) {
            throw ValidationException::withMessages(['xml' => ($sat->motivo ?? 'No se pudo verificar la factura en el SAT.').' La factura no se guardó.']);
        }
        if ($error = EstadoCfdiSat::error($estado, $cfdi['uuid'])) {
            throw ValidationException::withMessages(['xml' => $error]);
        }

        $ruta = "tramites-servicio/{$tramite->año}/{$tramite->folio_completo}_{$cfdi['uuid']}.xml";
        Storage::disk('local')->put($ruta, $contenido);
        try {
            $factura = DB::transaction(function () use ($tramite, $cfdi, $ruta, $estado, $fondo) {
                $factura = $tramite->facturas()->create([
                    'xml_path' => $ruta,
                    'uuid' => $cfdi['uuid'],
                    'rfc_emisor' => $cfdi['rfc_emisor'],
                    'nombre_emisor' => $cfdi['nombre_emisor'],
                    'rfc_receptor' => $cfdi['rfc_receptor'],
                    'serie' => $cfdi['serie'],
                    'folio' => $cfdi['folio'],
                    'fecha' => $cfdi['fecha'],
                    'subtotal' => $cfdi['subtotal'],
                    'descuento' => $cfdi['descuento'],
                    'iva' => $cfdi['iva'],
                    'isr' => $cfdi['isr'],
                    'total' => $cfdi['total'],
                    // Fondo: cada artículo lleva luego su partida / proyecto / fuente.
                    'conceptos' => $fondo
                        ? array_map(fn ($a) => $a + ['partida' => null, 'proyecto' => null, 'fuente' => null], $cfdi['conceptos'])
                        : $cfdi['conceptos'],
                    'sat_estado' => $estado['estado'],
                    'sat_verificado_at' => now(),
                    // Por omisión, el concepto general del trámite (se puede cambiar por factura).
                    'concepto' => $tramite->concepto,
                ]);
                if (! $fondo && ! $tramite->proveedor_rfc) {
                    $tramite->update(['proveedor_rfc' => $cfdi['rfc_emisor'], 'proveedor_nombre' => $cfdi['nombre_emisor']]);
                }

                return $factura;
            });
        } catch (UniqueConstraintViolationException) {
            Storage::disk('local')->delete($ruta);
            throw ValidationException::withMessages(['xml' => "La factura {$cfdi['uuid']} ya se cargó."]);
        }

        $tramite->refresh();
        $aviso = $tramite->impedimentoProveedor();
        if ($estado['efos']) {
            $aviso = trim(($aviso ? $aviso.' ' : '')."Atención: el emisor {$cfdi['rfc_emisor']} aparece en la lista de EFOS del SAT.");
        }

        return response()->json(['id' => $factura->id, 'message' => "Factura {$cfdi['uuid']} agregada (vigente en el SAT).", 'aviso' => $aviso], 201);
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

        if ($tramite->esFondo()) {
            $validated = $request->validate([
                'concepto' => 'required|string|max:3000',
                'articulos' => 'required|array',
                'articulos.*.partida' => 'nullable|string|max:200',
                'articulos.*.proyecto' => 'nullable|string|max:200',
                'articulos.*.fuente' => 'nullable|string|max:200',
            ], ['concepto.required' => 'Captura el concepto de la factura.']);
            $articulos = $factura->conceptos ?? [];
            if (count($validated['articulos']) !== count($articulos)) {
                throw ValidationException::withMessages(['articulos' => 'La clasificación no coincide con los artículos de la factura.']);
            }
            foreach ($validated['articulos'] as $i => $a) {
                if ((filled($a['partida'] ?? null) || filled($a['proyecto'] ?? null) || filled($a['fuente'] ?? null)) && ! $existe($a['fuente'] ?? null, $a['proyecto'] ?? null, $a['partida'] ?? null)) {
                    throw ValidationException::withMessages(["articulos.{$i}.partida" => 'Artículo '.($i + 1).': esa combinación de partida, proyecto y fuente no existe en el presupuesto de la secretaría.']);
                }
                $articulos[$i] = [...$articulos[$i], 'partida' => $a['partida'] ?? null, 'proyecto' => $a['proyecto'] ?? null, 'fuente' => $a['fuente'] ?? null];
            }
            $factura->update(['conceptos' => $articulos, 'concepto' => mb_strtoupper(trim($validated['concepto']))]);

            return response()->json(['message' => 'Artículos clasificados.']);
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
        $factura->update([...$validated, 'concepto' => mb_strtoupper(trim($validated['concepto']))]);

        return response()->json(['message' => 'Factura clasificada.']);
    }

    public function quitarFactura(TramiteServicio $tramite, TramiteServicioFactura $factura): JsonResponse
    {
        $this->exigirEditable($tramite);
        abort_unless($factura->tramite_servicio_id === $tramite->id, 404);
        $ruta = $factura->xml_path;
        DB::transaction(function () use ($tramite, $factura) {
            $factura->delete();
            if (! $tramite->esFondo() && ! $tramite->facturas()->exists()) {
                $tramite->update(['proveedor_rfc' => null, 'proveedor_nombre' => null]);
            }
        });
        Storage::disk('local')->delete($ruta);

        return response()->json(['message' => 'Factura quitada.']);
    }

    public function descargarXml(TramiteServicio $tramite, TramiteServicioFactura $factura)
    {
        $this->autorizar($tramite);
        abort_unless($factura->tramite_servicio_id === $tramite->id && Storage::disk('local')->exists($factura->xml_path), 404);

        return Storage::disk('local')->download($factura->xml_path, "{$factura->uuid}.xml", ['Content-Type' => 'application/xml']);
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
                ? "Falta asignar partida, proyecto y fuente a los artículos (y el concepto) de {$sinClasificar} factura(s)."
                : "Falta asignar partida, proyecto, fuente y concepto a {$sinClasificar} factura(s).");
        }
        if ($fondo && ! $tramite->trabajador_nombre) {
            return $this->error('Captura el nombre del trabajador a cuyo favor va la CLC.');
        }
        if ($impedimento = $tramite->impedimentoProveedor()) {
            return $this->error($impedimento);
        }

        $tramite->update(['estado' => 'por_validar', 'enviado_at' => now()]);

        return response()->json(['message' => "{$tramite->folio_completo} enviado a validar."]);
    }

    /** Lo valida quien tiene el permiso: se generan la requisición y la CLC (y en servicios, la orden de compra). */
    public function validar(Request $request, TramiteServicio $tramite, GeneradorTramiteServicio $generador): JsonResponse
    {
        $this->autorizar($tramite);
        abort_unless($request->user()->can($tramite->permiso('validar')), 403);
        $requisicion = $generador->generar($tramite, $request->user());
        $orden = OrdenCompra::where('tramite_servicio_id', $tramite->id)->first();

        return response()->json([
            'message' => $tramite->esFondo()
                ? "Fondo revolvente validado: requisición {$requisicion->folio_completo} y CLC {$orden?->clc_folio_completo} a favor de {$tramite->trabajador_nombre}. Ya se pueden imprimir."
                : "Trámite validado: requisición {$requisicion->folio_completo}, orden de compra {$orden?->folio_completo} y CLC {$orden?->clc_folio_completo} generadas. Ya se pueden imprimir.",
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
        $validated = $request->validate(['observaciones' => 'required|string|min:5|max:3000'],
            ['observaciones.required' => 'Escribe las observaciones para el área.', 'observaciones.min' => 'Explica qué hay que corregir.']);
        $tramite->update(['estado' => 'rechazado', 'observaciones' => trim($validated['observaciones'])]);

        return response()->json(['message' => "{$tramite->folio_completo} regresado al área con observaciones."]);
    }

    public function cancelar(Request $request, TramiteServicio $tramite): JsonResponse
    {
        $this->autorizar($tramite);
        if (! in_array($tramite->estado, ['captura', 'rechazado', 'por_validar'], true)) {
            return $this->error('Ya validado no se cancela aquí: se cancela su CLC y su requisición.');
        }
        $validated = $request->validate(['motivo' => 'required|string|min:5|max:1000']);
        $tramite->update(['estado' => 'cancelado', 'observaciones' => 'Cancelado: '.trim($validated['motivo'])]);

        return response()->json(['message' => "{$tramite->folio_completo} cancelado."]);
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

    /** Ya cargada en otro trámite o fondo, o en una orden de compra. */
    private function duplicidades(string $uuid): array
    {
        $errores = [];
        $otro = TramiteServicioFactura::with('tramite:id,folio_completo,estado')->where('uuid', $uuid)->first();
        if ($otro) {
            $errores[] = "La factura {$uuid} ya está en {$otro->tramite?->folio_completo}.";
        }
        $orden = OrdenCompra::where('xml_uuid', $uuid)->first(['folio_completo']);
        if ($orden) {
            $errores[] = "La factura {$uuid} ya está cargada en la orden de compra {$orden->folio_completo}.";
        }

        return $errores;
    }

    private function veTodos($user): bool
    {
        return $user->can($this->permiso('validar')) || $user->hasRole(['Super Admin', 'Admin']);
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
