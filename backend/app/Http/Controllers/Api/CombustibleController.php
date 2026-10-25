<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Auditoria;
use App\Models\CombustibleCarga;
use App\Models\CombustibleFactura;
use App\Models\CombustibleLote;
use App\Models\EgresoUnidadAdministrativa;
use App\Models\TramiteServicio;
use App\Models\Vehiculo;
use App\Services\Cfdi;
use App\Services\ExcelCombustible;
use App\Services\ExcelTabla;
use App\Services\FacturasTramite;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Bitácora de combustible (nuevo; SIEG no la tenía). La unidad de combustibles carga el Excel del proveedor (un
 * lote): cada factura del Excel es de una secretaría, así que se crea una bitácora (BC-…) por secretaría con sus
 * tickets. Luego se suben los XML del lote (cada uno va solo a su bitácora), se clasifica cada bitácora y se envía
 * a validar; al validarse se generan requisición, orden de compra y CLC (TramiteServicioController, tipo
 * combustible). Aquí también la bitácora para auditoría (Excel y PDF) y el reporte de consumo.
 */
class CombustibleController extends Controller
{
    public function __construct(private FacturasTramite $facturas) {}

    private const FORMATO_IMPORTE = '#,##0.00;[Red]-#,##0.00';

    private const FORMATO_LITROS = '#,##0.000';

    private const AGRUPACIONES = ['secretaria' => 'Secretaría', 'vehiculo' => 'Vehículo', 'mes' => 'Mes', 'producto' => 'Producto'];

    public function lotes(Request $request): JsonResponse
    {
        $lotes = CombustibleLote::with('usuario:id,name')->withCount('bitacoras')->orderByDesc('id')->paginate(20);
        $ids = $lotes->getCollection()->pluck('id');
        $estados = TramiteServicio::whereIn('combustible_lote_id', $ids)->selectRaw('combustible_lote_id, estado, COUNT(*) AS n')
            ->groupBy('combustible_lote_id', 'estado')->get()->groupBy('combustible_lote_id');
        $facturas = CombustibleCarga::whereIn('combustible_lote_id', $ids)
            ->selectRaw('combustible_lote_id, COUNT(DISTINCT factura_folio) AS esperadas, COUNT(DISTINCT tramite_servicio_factura_id) AS cargadas')
            ->groupBy('combustible_lote_id')->get()->keyBy('combustible_lote_id');

        return response()->json($lotes->through(fn (CombustibleLote $l) => [
            'id' => $l->id,
            'periodo' => $l->periodo,
            'archivo' => $l->archivo_nombre,
            'hoja' => $l->hoja,
            'fecha' => $l->created_at?->toIso8601String(),
            'capturo' => $l->usuario?->name,
            'tickets' => $l->tickets,
            'litros' => $l->litros,
            'importe' => $l->importe,
            'bitacoras' => $l->bitacoras_count,
            'estados' => ($estados[$l->id] ?? collect())->mapWithKeys(fn ($e) => [$e->estado => (int) $e->n]),
            'facturas_esperadas' => (int) ($facturas[$l->id]->esperadas ?? 0),
            'facturas_cargadas' => (int) ($facturas[$l->id]->cargadas ?? 0),
        ]));
    }

    /** Lee el Excel sin guardar nada: hojas, resumen por secretaría y errores que impedirían cargarlo. */
    public function previa(Request $request): JsonResponse
    {
        $request->validate($this->reglasArchivo(), [], ['archivo' => 'Excel del proveedor']);
        $ruta = $request->file('archivo')->getRealPath();
        $hojas = ExcelCombustible::hojas($ruta);
        $hoja = $request->input('hoja') ?: ($hojas[0] ?? null);
        $analisis = $this->analizar($ruta, $hoja);

        return response()->json([
            'hojas' => $hojas,
            'hoja' => $analisis['hoja'],
            'periodo' => $this->periodoSugerido($analisis['hoja'], $analisis['tickets']),
            'tickets' => count($analisis['tickets']),
            'litros' => round(array_sum(array_column($analisis['tickets'], 'litros')), 3),
            'importe' => round(array_sum(array_column($analisis['tickets'], 'importe')), 2),
            'facturas' => count(array_unique(array_column($analisis['tickets'], 'factura_folio'))),
            'proveedores' => array_values(array_unique(array_filter(array_column($analisis['tickets'], 'proveedor')))),
            'vehiculos_padron' => $analisis['vehiculos_padron'],
            // Facturas del Excel que ya se subieron (por asignar) y las subidas que este Excel no incluye.
            'facturas_con_xml' => $analisis['con_xml'],
            'por_asignar_fuera' => CombustibleFactura::count() - $analisis['con_xml'],
            'secretarias' => array_values($analisis['secretarias']),
            'errores' => array_slice($analisis['errores'], 0, 50),
            'total_errores' => count($analisis['errores']),
            // No impiden cargarlo (p. ej. una fecha mal capturada, que se deja vacía).
            'avisos' => array_slice($analisis['avisos'], 0, 50),
            'total_avisos' => count($analisis['avisos']),
        ]);
    }

    /** Carga el Excel: crea el lote y una bitácora por secretaría con sus tickets. Con cualquier error no carga nada. */
    public function importar(Request $request): JsonResponse
    {
        $validated = $request->validate([...$this->reglasArchivo(), 'hoja' => 'nullable|string|max:100', 'periodo' => 'required|string|max:100'],
            [], ['archivo' => 'Excel del proveedor', 'periodo' => 'periodo']);
        $archivo = $request->file('archivo');
        $analisis = $this->analizar($archivo->getRealPath(), $validated['hoja'] ?? null);
        if ($analisis['errores']) {
            throw ValidationException::withMessages(['archivo' => 'El Excel tiene '.count($analisis['errores']).' error(es); corrígelos y vuelve a cargarlo. '.implode(' ', array_slice($analisis['errores'], 0, 3))]);
        }
        if (! $analisis['tickets']) {
            throw ValidationException::withMessages(['archivo' => 'La hoja no tiene tickets.']);
        }

        $año = (int) now('America/Mexico_City')->format('Y');
        $periodo = mb_strtoupper(trim($validated['periodo']));
        $ruta = $archivo->storeAs("combustible/{$año}", now()->format('Ymd_His').'_'.preg_replace('/[^A-Za-z0-9._-]/', '_', $archivo->getClientOriginalName()), 'local');
        $user = $request->user();

        try {
            $asignadas = 0;
            $lote = $this->crearLote($analisis, $periodo, $archivo, $ruta, $user, $año, $asignadas);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($ruta);
            throw $e;
        }

        return response()->json([
            'id' => $lote->id,
            'message' => "Excel cargado: {$lote->tickets} tickets en ".count($analisis['secretarias']).' bitácora(s), una por secretaría.'
                .($asignadas ? " {$asignadas} factura(s) que ya estaban subidas pasaron a su bitácora." : '')
                .($asignadas < $analisis['facturas'] ? ' Faltan '.($analisis['facturas'] - $asignadas).' XML por subir.' : ' Ya están todos los XML: cada secretaría puede llenar su bitácora.')
                .($analisis['avisos'] ? ' '.count($analisis['avisos']).' ticket(s) con fecha no válida quedaron sin esa fecha.' : ''),
        ], 201);
    }

    /** El lote y una bitácora por secretaría con sus tickets, en una transacción. */
    private function crearLote(array $analisis, string $periodo, $archivo, string $ruta, $user, int $año, int &$asignadas): CombustibleLote
    {
        // Closure (no fn): $asignadas debe llegar por referencia hasta adentro de la transacción.
        return Auditoria::enLote("Carga del Excel de combustible {$periodo} ({$archivo->getClientOriginalName()})", function () use ($analisis, $periodo, $archivo, $ruta, $user, $año, &$asignadas) {
            return DB::transaction(function () use ($analisis, $periodo, $archivo, $ruta, $user, $año, &$asignadas) {
                $tickets = $analisis['tickets'];
                $lote = CombustibleLote::create([
                    'periodo' => $periodo,
                    'archivo_nombre' => mb_substr($archivo->getClientOriginalName(), 0, 255),
                    'archivo_path' => $ruta,
                    'hoja' => $analisis['hoja'],
                    'tickets' => count($tickets),
                    'litros' => round(array_sum(array_column($tickets, 'litros')), 3),
                    'importe' => round(array_sum(array_column($tickets, 'importe')), 2),
                    'user_id' => $user->id,
                ]);

                $folio = TramiteServicio::where('tipo', 'combustible')->where('año', $año)->lockForUpdate()->max('folio') ?? 0;
                foreach ($analisis['secretarias'] as $s) {
                    $folio++;
                    $bitacora = TramiteServicio::create([
                        'tipo' => 'combustible',
                        'combustible_lote_id' => $lote->id,
                        'folio' => $folio,
                        'año' => $año,
                        'folio_completo' => sprintf('BC-%d-%04d', $año, $folio),
                        'id_cat_egreso_unidad_administrativa' => $s['unidad_id'],
                        'concepto' => "CONSUMO DE COMBUSTIBLE {$periodo}",
                        'estado' => 'captura',
                        'user_id' => $user->id,
                    ]);
                    $ahora = now();
                    $filas = collect($tickets)->where('clave', $s['clave'])->map(fn ($t) => [
                        'combustible_lote_id' => $lote->id,
                        'tramite_servicio_id' => $bitacora->id,
                        ...collect($t)->except(['clave', 'secretaria', 'proveedor'])->all(),
                        'vehiculo_id' => $analisis['vehiculos'][$t['numero_economico'] ?? ''] ?? null,
                        'created_at' => $ahora,
                        'updated_at' => $ahora,
                    ]);
                    foreach ($filas->chunk(500) as $bloque) {
                        CombustibleCarga::insert($bloque->values()->all());
                    }
                    // Las facturas que se subieron antes del Excel pasan a su bitácora.
                    $asignadas += $this->facturas->asignarPendientes($bitacora);
                }

                return $lote;
            });
        });
    }

    public function lote(CombustibleLote $lote): JsonResponse
    {
        $user = auth()->user();
        $bitacoras = $lote->bitacoras()->with('unidadAdministrativa:id_cat_egreso_unidad_administrativa,clave,nombre', 'requisicion:id_tb_egreso_requisicion,folio_completo')->get();
        $porBitacora = CombustibleCarga::where('combustible_lote_id', $lote->id)
            ->selectRaw('tramite_servicio_id, COUNT(*) AS tickets, SUM(litros) AS litros, SUM(importe) AS importe, COUNT(DISTINCT factura_folio) AS esperadas, COUNT(DISTINCT tramite_servicio_factura_id) AS cargadas')
            ->groupBy('tramite_servicio_id')->get()->keyBy('tramite_servicio_id');
        $visibles = $this->veTodos($user) ? null : EgresoUnidadAdministrativa::idsMismaClave($user->id_cat_egreso_unidad_administrativa);

        return response()->json([
            'lote' => [
                'id' => $lote->id,
                'periodo' => $lote->periodo,
                'archivo' => $lote->archivo_nombre,
                'hoja' => $lote->hoja,
                'fecha' => $lote->created_at?->toIso8601String(),
                'capturo' => $lote->usuario?->name,
                'tickets' => $lote->tickets,
                'litros' => $lote->litros,
                'importe' => $lote->importe,
            ],
            'bitacoras' => $bitacoras->filter(fn ($b) => $visibles === null || in_array((int) $b->id_cat_egreso_unidad_administrativa, $visibles, true))
                ->map(fn (TramiteServicio $b) => [
                    'id' => $b->id,
                    'folio' => $b->folio_completo,
                    'unidad' => $b->unidadAdministrativa ? trim($b->unidadAdministrativa->clave.' — '.$b->unidadAdministrativa->nombre) : null,
                    'estado' => $b->estado,
                    'estado_texto' => TramiteServicio::ESTADOS[$b->estado] ?? $b->estado,
                    'tickets' => (int) ($porBitacora[$b->id]->tickets ?? 0),
                    'litros' => round((float) ($porBitacora[$b->id]->litros ?? 0), 3),
                    'importe' => round((float) ($porBitacora[$b->id]->importe ?? 0), 2),
                    'facturas_esperadas' => (int) ($porBitacora[$b->id]->esperadas ?? 0),
                    'facturas_cargadas' => (int) ($porBitacora[$b->id]->cargadas ?? 0),
                    'requisicion' => $b->requisicion?->folio_completo,
                ])->values(),
            'puede' => [
                'subir' => $user->can('combustible-cargar') && $bitacoras->contains(fn ($b) => $b->editable()),
                'eliminar' => $user->can('combustible-cargar') && $bitacoras->every(fn ($b) => in_array($b->estado, ['captura', 'cancelado'], true)),
            ],
        ]);
    }

    /**
     * Sube un XML del proveedor. Si ya hay un Excel con ese folio de factura, va a la bitácora de su secretaría; si
     * no, queda por asignar hasta que se cargue el Excel.
     */
    public function subirFactura(Request $request, FacturasTramite $facturas): JsonResponse
    {
        $request->validate(['xml' => 'required|file|max:2048'], [], ['xml' => 'factura (XML)']);
        $contenido = file_get_contents($request->file('xml')->getRealPath());
        $cfdi = Cfdi::leer($contenido);
        $folio = FacturasTramite::folio($cfdi);

        $carga = $folio === '' ? null : CombustibleCarga::with('bitacora')->where('factura_folio', $folio)
            ->whereHas('bitacora', fn ($q) => $q->where('estado', '!=', 'cancelado'))->orderByDesc('id')->first();
        if (! $carga) {
            $pendiente = $facturas->porAsignar($contenido, $cfdi);

            return response()->json(['id' => $pendiente->id, 'bitacora' => null,
                'message' => "{$folio}: verificada en el SAT; queda por asignar hasta que se cargue el Excel del proveedor que la incluya.",
                'aviso' => $pendiente->sat_efos ? "Atención: el emisor {$pendiente->rfc_emisor} aparece en la lista de EFOS del SAT." : null], 201);
        }
        $bitacora = $carga->bitacora;
        if (! $bitacora->editable()) {
            throw ValidationException::withMessages(['xml' => "La factura {$folio} es de {$bitacora->folio_completo}, que ya no se puede modificar (está ".mb_strtolower(TramiteServicio::ESTADOS[$bitacora->estado] ?? $bitacora->estado).').']);
        }
        $r = $facturas->agregar($bitacora, $contenido, $cfdi);

        return response()->json(['id' => $r['factura']->id, 'bitacora' => $bitacora->folio_completo,
            'message' => "{$folio} → {$bitacora->folio_completo}: {$r['message']}", 'aviso' => $r['aviso']], 201);
    }

    /** Facturas subidas que todavía no están en ningún Excel cargado. */
    public function porAsignar(): JsonResponse
    {
        return response()->json(CombustibleFactura::orderBy('folio')->get()->map(fn (CombustibleFactura $f) => [
            'id' => $f->id,
            'uuid' => $f->uuid,
            'folio' => $f->folio,
            'emisor' => $f->nombre_emisor,
            'rfc_emisor' => $f->rfc_emisor,
            'fecha' => $f->fecha?->toIso8601String(),
            'total' => $f->total,
            'sat_estado' => $f->sat_estado,
            'subida' => $f->created_at?->toIso8601String(),
        ]));
    }

    public function quitarPorAsignar(CombustibleFactura $factura): JsonResponse
    {
        $factura->delete();
        Storage::disk('local')->delete($factura->xml_path);

        return response()->json(['message' => "Factura {$factura->folio} quitada."]);
    }

    /**
     * Elimina una carga equivocada: solo si ninguna de sus bitácoras se envió a validar. Sus XML no se pierden:
     * regresan a por asignar, para el Excel correcto.
     */
    public function eliminarLote(CombustibleLote $lote): JsonResponse
    {
        $bitacoras = $lote->bitacoras()->get();
        if ($bitacoras->contains(fn ($b) => ! in_array($b->estado, ['captura', 'cancelado'], true))) {
            return response()->json(['message' => 'Ya hay bitácoras de esta carga enviadas o validadas: no se puede eliminar.'], 422);
        }
        $regresan = 0;

        Auditoria::enLote("Eliminación de la carga de combustible {$lote->periodo}", fn () => DB::transaction(function () use ($lote, $bitacoras, &$regresan) {
            foreach ($bitacoras as $b) {
                foreach ($b->facturas()->get() as $f) {
                    $contenido = Storage::disk('local')->get($f->xml_path);
                    if ($contenido !== null) {
                        CombustibleFactura::create([
                            'uuid' => $f->uuid, 'folio' => CombustibleCarga::normalizarFolio(($f->serie ?? '').($f->folio ?? '')),
                            'rfc_emisor' => $f->rfc_emisor, 'nombre_emisor' => $f->nombre_emisor, 'fecha' => $f->fecha, 'total' => $f->total,
                            'xml_path' => $f->xml_path, 'cfdi' => Cfdi::leer($contenido), 'sat_estado' => $f->sat_estado,
                            'sat_verificado_at' => $f->sat_verificado_at, 'user_id' => auth()->id(),
                        ]);
                        $regresan++;
                    }
                    $f->delete();
                }
                $b->delete(); // sus tickets se borran en cascada
            }
            $lote->delete();
        }));
        Storage::disk('local')->delete($lote->archivo_path);

        return response()->json(['message' => "Carga de {$lote->periodo} eliminada.".($regresan ? " {$regresan} factura(s) regresaron a por asignar." : '')]);
    }

    /** Tickets de una bitácora. */
    public function tickets(TramiteServicio $tramite): JsonResponse
    {
        $this->autorizar($tramite);

        return response()->json(['tickets' => $this->filasBitacora($tramite)]);
    }

    /** Bitácora de una secretaría para auditoría, en Excel. */
    public function bitacoraExcel(TramiteServicio $tramite)
    {
        $this->autorizar($tramite);
        $tramite->load('unidadAdministrativa', 'lote');
        $filas = collect($this->filasBitacora($tramite))->map(fn ($t) => [
            $t['fecha_ticket'] ? date('d/m/Y', strtotime($t['fecha_ticket'])) : null, $t['ticket'], $t['vale'], $t['numero_economico'], $t['placas'], $t['departamento'], $t['conductor'],
            $t['producto'], $t['litros'], $t['precio'], $t['importe'], $t['factura_folio'], $t['uuid'],
        ]);
        $libro = ExcelTabla::libro(
            "Bitácora de combustible {$tramite->folio_completo}",
            trim(($tramite->unidadAdministrativa?->clave ?? '').' '.($tramite->unidadAdministrativa?->nombre ?? '')).' · '.($tramite->lote?->periodo ?? '').' · '.($tramite->proveedor_nombre ?? 'Proveedor sin factura cargada'),
            ['Fecha', 'Ticket', 'Vale', 'No. económico', 'Placas', 'Departamento', 'Nombre', 'Producto', 'Litros', 'Precio por litro', 'Importe', 'Factura', 'UUID'],
            $filas,
            [9 => self::FORMATO_LITROS, 10 => '#,##0.0000', 11 => self::FORMATO_IMPORTE],
            [9, 11],
            [11, 10, 10, 13, 10, 28, 26, 11, 10, 11, 13, 11, 38],
        );

        return $this->descargarExcel($libro, "bitacora_combustible_{$tramite->folio_completo}");
    }

    /** Bitácora de una secretaría para auditoría, en PDF. */
    public function bitacoraPdf(TramiteServicio $tramite)
    {
        $this->autorizar($tramite);
        $tramite->load('unidadAdministrativa', 'lote', 'requisicion:id_tb_egreso_requisicion,folio_completo', 'ordenCompra');
        $tickets = collect($this->filasBitacora($tramite));
        $pdf = Pdf::loadView('combustible.bitacora', [
            'tramite' => $tramite,
            'tickets' => $tickets,
            'porFactura' => $tickets->groupBy('factura_folio')->map(fn ($g, $folio) => ['folio' => $folio, 'tickets' => $g->count(), 'litros' => $g->sum('litros'), 'importe' => $g->sum('importe')])->values(),
            'porProducto' => $tickets->groupBy('producto')->map(fn ($g, $p) => ['producto' => $p ?: 'SIN PRODUCTO', 'litros' => $g->sum('litros'), 'importe' => $g->sum('importe')])->values(),
        ])->setPaper('letter', 'landscape');

        return $pdf->download("bitacora_combustible_{$tramite->folio_completo}.pdf");
    }

    /** Reporte de consumo y gasto: por secretaría, vehículo, mes o producto, en un periodo. */
    public function reporte(Request $request): JsonResponse
    {
        [$filas, $filtros] = $this->datosReporte($request);

        return response()->json([
            'filas' => $filas,
            'totales' => ['tickets' => $filas->sum('tickets'), 'litros' => round($filas->sum('litros'), 3), 'importe' => round($filas->sum('importe'), 2)],
            'agrupaciones' => self::AGRUPACIONES,
            'filtros' => $filtros,
            'unidades' => $this->veTodos($request->user())
                ? EgresoUnidadAdministrativa::where('activo', true)->orderBy('clave')->get(['id_cat_egreso_unidad_administrativa as id', 'clave', 'nombre'])
                : [],
        ]);
    }

    public function reporteExcel(Request $request)
    {
        [$filas, $filtros] = $this->datosReporte($request);
        $agrupar = $filtros['agrupar'];
        $vehiculo = $agrupar === 'vehiculo';
        $libro = ExcelTabla::libro(
            'Consumo de combustible por '.mb_strtolower(self::AGRUPACIONES[$agrupar]),
            $filtros['descripcion'],
            [self::AGRUPACIONES[$agrupar], ...($vehiculo ? ['Secretaría'] : []), 'Tickets', 'Litros', 'Importe', 'Precio promedio'],
            $filas->map(fn ($f) => [$f['grupo'], ...($vehiculo ? [$f['unidad']] : []), $f['tickets'], $f['litros'], $f['importe'], $f['precio_promedio']]),
            $vehiculo ? [3 => '#,##0', 4 => self::FORMATO_LITROS, 5 => self::FORMATO_IMPORTE, 6 => '#,##0.0000'] : [2 => '#,##0', 3 => self::FORMATO_LITROS, 4 => self::FORMATO_IMPORTE, 5 => '#,##0.0000'],
            $vehiculo ? [3, 4, 5] : [2, 3, 4],
            $vehiculo ? [16, 40, 10, 14, 16, 14] : [44, 10, 14, 16, 14],
        );

        return $this->descargarExcel($libro, "consumo_combustible_por_{$agrupar}");
    }

    // ----------------------------------------------------------------

    private function reglasArchivo(): array
    {
        return ['archivo' => 'required|file|mimes:xlsx,xls|max:20480'];
    }

    /**
     * Tickets del Excel agrupados por secretaría, con lo que impide cargarlo: claves sin secretaría en el catálogo,
     * facturas repartidas en dos secretarías o ya cargadas en otra bitácora (no cancelada).
     *
     * @return array{hoja: string, tickets: array, secretarias: array, vehiculos: array<string, int>, vehiculos_padron: int, facturas: int, con_xml: int, errores: string[], avisos: string[]}
     */
    private function analizar(string $ruta, ?string $hoja): array
    {
        $leido = ExcelCombustible::leer($ruta, $hoja);
        $tickets = $leido['tickets'];
        $errores = $leido['errores'];

        $claves = array_values(array_unique(array_column($tickets, 'clave')));
        $unidades = EgresoUnidadAdministrativa::whereIn('clave', $claves)->orderByDesc('activo')->orderBy('id_cat_egreso_unidad_administrativa')
            ->get(['id_cat_egreso_unidad_administrativa', 'clave', 'nombre'])->unique('clave')->keyBy('clave');

        $secretarias = [];
        $clavePorFolio = [];
        foreach ($tickets as $t) {
            $u = $unidades[$t['clave']] ?? null;
            $secretarias[$t['clave']] ??= ['clave' => $t['clave'], 'nombre' => $u?->nombre, 'nombre_excel' => $t['secretaria'], 'unidad_id' => $u?->id_cat_egreso_unidad_administrativa,
                'tickets' => 0, 'litros' => 0.0, 'importe' => 0.0, 'facturas' => []];
            $s = &$secretarias[$t['clave']];
            $s['tickets']++;
            $s['litros'] += $t['litros'];
            $s['importe'] += $t['importe'];
            $s['facturas'][$t['factura_folio']] = true;
            unset($s);
            $clavePorFolio[$t['factura_folio']][$t['clave']] = true;
        }
        foreach ($secretarias as $clave => $s) {
            if (! $s['unidad_id']) {
                $errores[] = "La clave {$clave} ({$s['nombre_excel']}) no existe en el catálogo de secretarías.";
            }
        }
        foreach ($clavePorFolio as $folio => $deClaves) {
            if (count($deClaves) > 1) {
                $errores[] = "La factura {$folio} tiene tickets de varias secretarías (".implode(', ', array_keys($deClaves)).'): cada factura debe ser de una sola.';
            }
        }
        if ($clavePorFolio) {
            $yaCargadas = CombustibleCarga::query()->join('tramites_servicio', 'tramites_servicio.id', '=', 'combustible_cargas.tramite_servicio_id')
                ->whereIn('combustible_cargas.factura_folio', array_keys($clavePorFolio))->where('tramites_servicio.estado', '!=', 'cancelado')
                ->distinct()->get(['combustible_cargas.factura_folio', 'tramites_servicio.folio_completo']);
            foreach ($yaCargadas->groupBy('folio_completo') as $bitacora => $folios) {
                $errores[] = "Ya se cargaron antes en {$bitacora} las facturas ".$folios->pluck('factura_folio')->take(8)->implode(', ').($folios->count() > 8 ? '…' : '.');
            }
        }

        // Vehículos del padrón por número económico (los particulares no tienen).
        $economicos = array_values(array_filter(array_unique(array_column($tickets, 'numero_economico')), fn ($e) => $e && $e !== 'PARTICULAR'));
        $vehiculos = Vehiculo::whereIn(DB::raw('UPPER(TRIM(numero_economico))'), $economicos)->pluck('id', 'numero_economico')
            ->mapWithKeys(fn ($id, $eco) => [mb_strtoupper(trim($eco)) => $id])->all();

        $secretarias = array_map(fn ($s) => [...$s, 'litros' => round($s['litros'], 3), 'importe' => round($s['importe'], 2), 'facturas' => count($s['facturas'])], $secretarias);
        uasort($secretarias, fn ($a, $b) => strcmp($a['clave'], $b['clave']));

        return [
            'hoja' => $leido['hoja'],
            'tickets' => $tickets,
            'secretarias' => $secretarias,
            'vehiculos' => $vehiculos,
            'vehiculos_padron' => count(array_filter($tickets, fn ($t) => isset($vehiculos[$t['numero_economico'] ?? '']))),
            'facturas' => count($clavePorFolio),
            'con_xml' => $clavePorFolio ? CombustibleFactura::whereIn('folio', array_map('strval', array_keys($clavePorFolio)))->count() : 0,
            'errores' => $errores,
            'avisos' => $leido['avisos'],
        ];
    }

    /** "ENERO 2026": la hoja y el año de los tickets. */
    private function periodoSugerido(string $hoja, array $tickets): string
    {
        $años = array_count_values(array_map(fn ($t) => substr((string) ($t['fecha_factura'] ?? $t['fecha_ticket'] ?? ''), 0, 4), $tickets));
        arsort($años);
        $año = array_key_first(array_filter($años, fn ($n, $a) => $a !== '', ARRAY_FILTER_USE_BOTH));

        return trim(mb_strtoupper($hoja).($año && ! str_contains($hoja, (string) $año) ? " {$año}" : ''));
    }

    private function filasBitacora(TramiteServicio $tramite): array
    {
        return $tramite->cargas()->with('vehiculo:id,placas,marca,linea', 'factura:id,uuid')->get()->map(fn (CombustibleCarga $c) => [
            'id' => $c->id,
            'fila' => $c->fila,
            'fecha_ticket' => $c->fecha_ticket?->toDateString(),
            'ticket' => $c->ticket,
            'vale' => $c->vale,
            'numero_economico' => $c->numero_economico,
            'vehiculo_id' => $c->vehiculo_id,
            'placas' => $c->vehiculo?->placas,
            'departamento' => $c->departamento,
            'conductor' => $c->conductor,
            'producto' => $c->producto,
            'litros' => $c->litros,
            'precio' => $c->precio,
            'importe' => $c->importe,
            'factura_folio' => $c->factura_folio,
            'uuid' => $c->factura?->uuid,
        ])->all();
    }

    /** @return array{0: Collection, 1: array} */
    private function datosReporte(Request $request): array
    {
        $validated = $request->validate([
            'desde' => 'nullable|date',
            'hasta' => 'nullable|date|after_or_equal:desde',
            'agrupar' => 'nullable|in:'.implode(',', array_keys(self::AGRUPACIONES)),
            'unidad' => 'nullable|integer',
        ]);
        $user = $request->user();
        $agrupar = $validated['agrupar'] ?? 'secretaria';
        $desde = $validated['desde'] ?? now('America/Mexico_City')->startOfYear()->toDateString();
        $hasta = $validated['hasta'] ?? now('America/Mexico_City')->toDateString();

        $query = CombustibleCarga::query()->toBase()
            ->join('tramites_servicio as t', 't.id', '=', 'combustible_cargas.tramite_servicio_id')
            ->leftJoin('cat_egreso_unidad_administrativa as u', 'u.id_cat_egreso_unidad_administrativa', '=', 't.id_cat_egreso_unidad_administrativa')
            ->where('t.estado', '!=', 'cancelado')
            ->whereBetween('combustible_cargas.fecha_ticket', [$desde, $hasta]);
        $unidades = $this->veTodos($user)
            ? (filled($validated['unidad'] ?? null) ? EgresoUnidadAdministrativa::idsMismaClave((int) $validated['unidad']) : null)
            : (EgresoUnidadAdministrativa::idsMismaClave($user->id_cat_egreso_unidad_administrativa) ?: [0]);
        if ($unidades !== null) {
            $query->whereIn('t.id_cat_egreso_unidad_administrativa', $unidades);
        }

        $grupo = match ($agrupar) {
            'vehiculo' => "COALESCE(combustible_cargas.numero_economico, 'SIN NÚMERO')",
            'mes' => "DATE_FORMAT(combustible_cargas.fecha_ticket, '%Y-%m')",
            'producto' => "COALESCE(combustible_cargas.producto, 'SIN PRODUCTO')",
            default => "CONCAT(u.clave, ' — ', u.nombre)",
        };
        // Por vehículo, también por secretaría: los particulares (y números repetidos) de cada una van aparte.
        $unidad = "CONCAT(u.clave, ' — ', u.nombre)";
        $filas = $query->selectRaw("{$grupo} AS grupo, ".($agrupar === 'vehiculo' ? $unidad : 'NULL').' AS unidad, COUNT(*) AS tickets, SUM(combustible_cargas.litros) AS litros, SUM(combustible_cargas.importe) AS importe')
            ->groupByRaw($agrupar === 'vehiculo' ? "{$grupo}, {$unidad}" : $grupo)->orderByRaw($agrupar === 'mes' ? 'grupo' : 'importe DESC')->get()
            ->map(fn ($f) => [
                'grupo' => $f->grupo,
                'unidad' => $f->unidad,
                'tickets' => (int) $f->tickets,
                'litros' => round((float) $f->litros, 3),
                'importe' => round((float) $f->importe, 2),
                'precio_promedio' => (float) $f->litros > 0 ? round((float) $f->importe / (float) $f->litros, 4) : null,
            ]);

        $nombreUnidad = $unidades !== null && filled($validated['unidad'] ?? null) ? EgresoUnidadAdministrativa::whereKey((int) $validated['unidad'])->value('nombre') : null;

        return [$filas, [
            'desde' => $desde,
            'hasta' => $hasta,
            'agrupar' => $agrupar,
            'unidad' => $validated['unidad'] ?? null,
            'descripcion' => 'Del '.date('d/m/Y', strtotime($desde)).' al '.date('d/m/Y', strtotime($hasta)).' · '.($nombreUnidad ?? ($this->veTodos($user) ? 'Todas las secretarías' : 'Tu secretaría')),
        ]];
    }

    private function descargarExcel($libro, string $nombre)
    {
        return response()->streamDownload(
            fn () => (new Xlsx($libro))->save('php://output'),
            $nombre.'_'.now('America/Mexico_City')->format('Ymd_Hi').'.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    /** Igual que en TramiteServicioController (tipo combustible): la unidad de combustibles y quien valida ven todas las secretarías; cada área, solo la suya. */
    private function veTodos($user): bool
    {
        return $user->can('combustible-validar') || $user->can('combustible-cargar') || $user->hasRole(['Super Admin', 'Admin']);
    }

    private function autorizar(TramiteServicio $tramite): void
    {
        abort_unless($tramite->esCombustible(), 404);
        $user = auth()->user();
        if ($this->veTodos($user)) {
            return;
        }
        abort_unless(in_array((int) $tramite->id_cat_egreso_unidad_administrativa, EgresoUnidadAdministrativa::idsMismaClave($user->id_cat_egreso_unidad_administrativa), true), 403,
            'No pertenece a tu secretaría.');
    }
}
