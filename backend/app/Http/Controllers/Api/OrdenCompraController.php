<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EgresoRequisicion;
use App\Models\EgresoRequisicionBitacora;
use App\Models\OrdenCompra;
use App\Models\OrdenCompraRefactura;
use App\Models\Proveedor;
use App\Services\EstadoCfdiSat;
use App\Services\TramiteClc;
use Barryvdh\DomPDF\Facade\Pdf;
use DOMDocument;
use DOMXPath;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Orden de compra de una requisición comprometida: proveedor, fecha, PDF, factura (XML CFDI) con su
 * verificación en el SAT, refactura, cancelación y su CLC (generar, PDF, cancelar, pago) (SIEG:
 * OrdenCompraController, respondiendo JSON). El recorrido de la CLC entre áreas está en ClcController.
 */
class OrdenCompraController extends Controller
{
    /** Diferencia máxima aceptada entre lo cotizado y el total del CFDI (redondeo de centavos). */
    private const TOLERANCIA = 0.01;

    /**
     * Listado de órdenes de compra (nuevo; SIEG solo las mostraba dentro de cada requisición): proveedor,
     * importe y en qué va (factura, almacén, CLC, pago o cancelada). Las acciones siguen en la requisición.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $filtros = $request->only('buscar', 'situacion', 'desde', 'hasta');

        // Mismo alcance que Facturas: quien participa en el trámite ve todas; el área, las de su secretaría.
        $todas = TramiteClc::veTodo($user)
            || $user->canAny(['clc-recursos-materiales', 'clc-control-presupuestal', 'clc-sindicatura', 'clc-egresos', 'ordenes-compra-create']);
        $propias = \App\Models\EgresoUnidadAdministrativa::idsMismaClave($user->id_cat_egreso_unidad_administrativa);

        $query = OrdenCompra::query()
            ->when(! $todas, fn ($q) => $q->whereHas('requisicion', fn ($r) => $r->whereIn('id_cat_egreso_unidad_administrativa', $propias ?: [0])))
            ->when(filled($filtros['buscar'] ?? null), function ($q) use ($filtros) {
                foreach (preg_split('/\s+/', trim($filtros['buscar'])) as $termino) {
                    $like = "%{$termino}%";
                    $q->where(fn ($w) => $w->where('folio_completo', 'like', $like)->orWhere('clc_folio_completo', 'like', $like)
                        ->orWhere('xml_nombre_emisor', 'like', $like)->orWhere('xml_folio', 'like', $like)
                        ->orWhereHas('proveedor', fn ($p) => $p->where('nombre', 'like', $like)->orWhere('rfc', 'like', $like))
                        ->orWhereHas('requisicion', fn ($r) => $r->where('folio_completo', 'like', $like)->orWhere('observaciones', 'like', $like)));
                }
            })
            ->when(($filtros['situacion'] ?? null) === 'vigentes', fn ($q) => $q->whereNull('cancelada_at'))
            ->when(($filtros['situacion'] ?? null) === 'sin_factura', fn ($q) => $q->whereNull('cancelada_at')->whereNull('xml_uuid'))
            ->when(($filtros['situacion'] ?? null) === 'con_factura', fn ($q) => $q->whereNull('cancelada_at')->whereNotNull('xml_uuid')->whereNull('clc_folio_completo'))
            ->when(($filtros['situacion'] ?? null) === 'en_clc', fn ($q) => $q->whereNull('cancelada_at')->whereNotNull('clc_folio_completo')->whereNull('pago_registrado_at'))
            ->when(($filtros['situacion'] ?? null) === 'pagadas', fn ($q) => $q->whereNotNull('pago_registrado_at'))
            ->when(($filtros['situacion'] ?? null) === 'canceladas', fn ($q) => $q->whereNotNull('cancelada_at'))
            ->when(filled($filtros['desde'] ?? null), fn ($q) => $q->whereDate('fecha', '>=', $filtros['desde']))
            ->when(filled($filtros['hasta'] ?? null), fn ($q) => $q->whereDate('fecha', '<=', $filtros['hasta']));

        $totales = (clone $query)->selectRaw('COUNT(*) as ordenes, COALESCE(SUM(CASE WHEN cancelada_at IS NULL THEN importe ELSE 0 END), 0) as importe')->first();

        $ordenes = $query
            ->with(['proveedor:id,nombre,rfc', 'requisicion:id_tb_egreso_requisicion,folio_completo,observaciones,id_cat_egreso_unidad_administrativa',
                'requisicion.catEgresoUnidadAdministrativa:id_cat_egreso_unidad_administrativa,clave,nombre'])
            ->orderByDesc('fecha')->orderByDesc('id')
            ->paginate(25)
            ->through(fn (OrdenCompra $o) => [
                'id' => $o->id,
                'folio' => $o->folio_completo,
                'fecha' => $o->fecha?->toDateString(),
                'importe' => (float) $o->importe,
                'proveedor' => $o->proveedor?->nombre,
                'rfc' => $o->proveedor?->rfc,
                'requisicion_id' => $o->id_tb_egreso_requisicion,
                'requisicion' => $o->requisicion?->folio_completo,
                'concepto' => $o->requisicion?->observaciones,
                'unidad' => $o->requisicion?->catEgresoUnidadAdministrativa
                    ? trim($o->requisicion->catEgresoUnidadAdministrativa->clave.' '.$o->requisicion->catEgresoUnidadAdministrativa->nombre) : null,
                'factura' => $o->xml_uuid ? (trim(($o->xml_serie ?? '').' '.($o->xml_folio ?? '')) ?: 'Cargada') : null,
                'sat_estado' => $o->sat_estado,
                'almacen' => $o->almacen_asignada_at !== null,
                'clc' => $o->clc_folio_completo,
                'clc_estado' => $o->clc_folio_completo ? TramiteClc::descripcion($o->clc_estado) : null,
                'clc_clave' => $o->clc_estado,
                'pagada' => $o->pago_registrado_at !== null,
                'cancelada' => $o->cancelada_at !== null,
                'motivo_cancelacion' => $o->motivo_cancelacion,
            ]);

        return response()->json([
            ...$ordenes->toArray(),
            'totales' => ['ordenes' => (int) $totales->ordenes, 'importe' => round((float) $totales->importe, 2)],
        ]);
    }

    /** Proveedores activos para generar la orden o reasignarla (con su vigencia en el padrón). */
    public function proveedores(): JsonResponse
    {
        return response()->json(
            Proveedor::where('activo', true)->orderBy('nombre')->get(['id', 'nombre', 'rfc', 'vigencia'])
                ->map(fn (Proveedor $p) => [...$p->only('id', 'nombre', 'rfc'), 'vigencia' => $p->vigencia?->format('d/m/Y'), 'vencido' => ! $p->vigente()]),
        );
    }

    public function store(Request $request, EgresoRequisicion $requisicion): JsonResponse
    {
        if ($requisicion->suficiencia !== true || (int) $requisicion->id_cat_egreso_requisicion_estado !== EgresoRequisicion::COMPROMETIDA) {
            abort(403, 'Solo se genera orden de compra para requisiciones comprometidas (con suficiencia presupuestal).');
        }
        // Una por requisición: si su orden se canceló, lo que sigue es cancelar la requisición.
        if ($requisicion->ordenCompra()->exists()) {
            return $this->error('Esta requisición ya tiene orden de compra.');
        }

        $validated = $request->validate([
            'proveedor_id' => ['required', 'integer', 'exists:proveedores,id'],
            'fecha' => 'required|date',
            'observaciones' => 'nullable|string|max:3000',
        ], [], ['proveedor_id' => 'proveedor']);

        $proveedor = Proveedor::findOrFail($validated['proveedor_id']);
        if ($impedimento = $proveedor->impedimentoParaOrden()) {
            throw ValidationException::withMessages(['proveedor_id' => $impedimento]);
        }

        $orden = DB::transaction(function () use ($requisicion, $validated, $proveedor) {
            $año = (int) date('Y', strtotime($validated['fecha']));
            $folio = (OrdenCompra::where('año', $año)->lockForUpdate()->max('folio') ?? 0) + 1;

            $orden = OrdenCompra::create([
                'id_tb_egreso_requisicion' => $requisicion->id_tb_egreso_requisicion,
                'proveedor_id' => $proveedor->id,
                'folio' => $folio,
                'año' => $año,
                'folio_completo' => sprintf('OC-%d-%04d', $año, $folio),
                'fecha' => $validated['fecha'],
                'importe' => round((float) $requisicion->total, 2),
                'observaciones' => $validated['observaciones'] ?? null,
                'user_id' => auth()->id(),
            ]);

            $this->bitacora($requisicion, EgresoRequisicion::CON_ORDEN_COMPRA, "Orden de compra {$orden->folio_completo} generada para {$proveedor->nombre} por $".number_format($orden->importe, 2).'.');

            return $orden;
        });

        return response()->json(['message' => "Orden de compra {$orden->folio_completo} generada.", 'id' => $orden->id], 201);
    }

    /**
     * Reasigna el proveedor de la orden de compra mientras no tenga factura cargada (la factura la emite el
     * proveedor de la orden). Queda en la bitácora de la requisición.
     */
    public function cambiarProveedor(Request $request, OrdenCompra $ordenCompra): JsonResponse
    {
        if ($ordenCompra->cancelada_at) {
            return $this->error('La orden de compra está cancelada.');
        }
        if ($ordenCompra->xml_uuid) {
            return $this->error('El proveedor ya no se cambia: la orden de compra tiene su factura cargada.');
        }

        $validated = $request->validate(['proveedor_id' => ['required', 'integer', 'exists:proveedores,id']], [], ['proveedor_id' => 'proveedor']);
        $nuevo = Proveedor::findOrFail($validated['proveedor_id']);
        if ($nuevo->id === $ordenCompra->proveedor_id) {
            return response()->json(['message' => 'El proveedor no cambió.']);
        }
        if ($impedimento = $nuevo->impedimentoParaOrden()) {
            throw ValidationException::withMessages(['proveedor_id' => $impedimento]);
        }

        $anterior = $ordenCompra->proveedor;
        DB::transaction(function () use ($ordenCompra, $nuevo, $anterior) {
            $ordenCompra->update(['proveedor_id' => $nuevo->id]);
            $requisicion = $ordenCompra->requisicion;
            $this->bitacora($requisicion, (int) $requisicion->id_cat_egreso_requisicion_estado,
                "Proveedor de la orden {$ordenCompra->folio_completo} cambiado de ".($anterior?->nombre ?? '—')." a {$nuevo->nombre} por ".auth()->user()->name.'.');
        });

        return response()->json(['message' => "Proveedor de la orden {$ordenCompra->folio_completo} cambiado a {$nuevo->nombre}."]);
    }

    /**
     * Recursos Materiales y Control Presupuestal corrigen la fecha de la orden de compra: debe ser del
     * mismo año del folio, no futura y no posterior al pago registrado.
     */
    public function cambiarFecha(Request $request, OrdenCompra $ordenCompra): JsonResponse
    {
        if ($ordenCompra->cancelada_at) {
            return $this->error('La orden de compra está cancelada.');
        }

        $validated = $request->validate([
            'fecha' => ['required', 'date', 'before_or_equal:today', function ($attr, $valor, $fallo) use ($ordenCompra) {
                if ((int) date('Y', strtotime($valor)) !== (int) $ordenCompra->año) {
                    $fallo("La fecha debe ser del {$ordenCompra->año}, como el folio {$ordenCompra->folio_completo}.");
                }
                if ($ordenCompra->pago_fecha && $valor > $ordenCompra->pago_fecha->toDateString()) {
                    $fallo('La fecha no puede ser posterior al pago registrado ('.$ordenCompra->pago_fecha->toDateString().').');
                }
            }],
        ], ['fecha.before_or_equal' => 'La fecha no puede ser futura.']);

        $anterior = $ordenCompra->fecha?->toDateString();
        if ($anterior === $validated['fecha']) {
            return response()->json(['message' => 'La fecha no cambió.']);
        }

        DB::transaction(function () use ($ordenCompra, $validated, $anterior) {
            $ordenCompra->update(['fecha' => $validated['fecha']]);
            $requisicion = $ordenCompra->requisicion;
            $this->bitacora($requisicion, (int) $requisicion->id_cat_egreso_requisicion_estado,
                "Fecha de la orden {$ordenCompra->folio_completo} cambiada del {$anterior} al {$validated['fecha']} por ".auth()->user()->name.'.');
        });

        return response()->json(['message' => 'Fecha de la orden de compra actualizada.']);
    }

    public function pdf(OrdenCompra $ordenCompra)
    {
        abort_if($ordenCompra->cancelada_at, 403, "La orden de compra {$ordenCompra->folio_completo} está cancelada: ya no se imprime.");
        $this->autorizarArea($ordenCompra->requisicion);

        $ordenCompra->load('proveedor', 'requisicion.tbEgresoRequisicionDetalles', 'requisicion.catEgresoUnidadAdministrativa');

        $pdf = Pdf::loadView('ordenes-compra.pdf', ['orden' => $ordenCompra])->setPaper('letter');

        // Pie: "Página N de M" y el folio (dompdf no conoce el total de páginas desde el CSS).
        $pdf->render();
        $canvas = $pdf->getDomPDF()->getCanvas();
        $canvas->page_text($canvas->get_width() - 200, $canvas->get_height() - 28, "Página {PAGE_NUM} de {PAGE_COUNT}      {$ordenCompra->folio_completo}",
            $pdf->getDomPDF()->getFontMetrics()->getFont('Helvetica'), 7);

        return $pdf->stream("{$ordenCompra->folio_completo}.pdf");
    }

    /**
     * Carga la factura (CFDI) de la orden de compra. El total del XML debe coincidir con lo cotizado,
     * el emisor debe ser el proveedor de la orden y la factura debe estar vigente en el SAT.
     */
    public function cargarXml(Request $request, OrdenCompra $ordenCompra, EstadoCfdiSat $estadoSat): JsonResponse
    {
        if ($ordenCompra->cancelada_at) {
            return $this->error('La orden de compra está cancelada.');
        }
        if ($ordenCompra->xml_uuid) {
            return $this->error('Esta orden de compra ya tiene su factura cargada.');
        }

        $request->validate(['xml' => 'required|file|max:2048']);
        $contenido = file_get_contents($request->file('xml')->getRealPath());
        $cfdi = $this->leerCfdi($contenido);
        $xml = EstadoCfdiSat::datosDelXml($contenido);
        $cfdi['isr'] = $xml['isr'];

        $ordenCompra->load('proveedor');
        $errores = [];

        // El ISR retenido no cambia el importe del gasto: el Total del XML ya viene sin el ISR, así que lo
        // cotizado se compara con Total + ISR (al proveedor se le paga el Total).
        $importeFactura = round($cfdi['total'] + $cfdi['isr'], 2);
        if (abs($importeFactura - (float) $ordenCompra->importe) > self::TOLERANCIA) {
            $errores[] = $cfdi['isr'] > 0
                ? sprintf('El importe de la factura ($%s = total $%s + ISR retenido $%s) no coincide con lo cotizado en la orden de compra ($%s).',
                    number_format($importeFactura, 2), number_format($cfdi['total'], 2), number_format($cfdi['isr'], 2), number_format($ordenCompra->importe, 2))
                : sprintf('El total de la factura ($%s) no coincide con lo cotizado en la orden de compra ($%s).',
                    number_format($cfdi['total'], 2), number_format($ordenCompra->importe, 2));
        }
        if ($ordenCompra->proveedor->rfc && $cfdi['rfc_emisor'] && mb_strtoupper($cfdi['rfc_emisor']) !== $ordenCompra->proveedor->rfc) {
            $errores[] = "La factura la emite {$cfdi['rfc_emisor']}, pero el proveedor de la orden es {$ordenCompra->proveedor->rfc}.";
        }
        if (! $cfdi['uuid']) {
            $errores[] = 'El XML no trae timbre fiscal (UUID); no es un CFDI timbrado.';
        } else {
            $errores = [...$errores, ...$this->duplicidades($ordenCompra, $cfdi)];
        }

        if ($errores) {
            throw ValidationException::withMessages(['xml' => implode(' ', $errores)]);
        }

        // Estado en el SAT: solo se acepta (y se guarda) una factura vigente. Cancelada, no encontrada o sin
        // respuesta del SAT no se guarda.
        $sat = $estadoSat->consultar($cfdi['rfc_emisor'] ?? '', $xml['rfc_receptor'] ?? '', $cfdi['total'], $cfdi['uuid'], $xml['sello']);
        if (! $sat) {
            throw ValidationException::withMessages(['xml' => ($estadoSat->motivo ?? 'No se pudo verificar la factura en el SAT.').' La factura no se guardó.']);
        }
        if ($error = EstadoCfdiSat::error($sat, $cfdi['uuid'])) {
            throw ValidationException::withMessages(['xml' => $error]);
        }

        $ruta = "ordenes-compra/{$ordenCompra->año}/{$ordenCompra->folio_completo}_{$cfdi['uuid']}.xml";
        Storage::disk('local')->put($ruta, $contenido);

        try {
            $this->guardarFactura($ordenCompra, $cfdi, $ruta);
        } catch (UniqueConstraintViolationException) {
            // Otra persona cargó el mismo UUID al mismo tiempo: el índice único lo detiene.
            Storage::disk('local')->delete($ruta);
            throw ValidationException::withMessages(['xml' => implode(' ', $this->duplicidades($ordenCompra, $cfdi))
                ?: "La factura {$cfdi['uuid']} ya se cargó en otra orden de compra."]);
        }
        EstadoCfdiSat::guardar($ordenCompra, $sat);

        $aviso = $sat['efos'] ? self::avisoEfos($cfdi['rfc_emisor']) : null;

        // Refactura con CLC anterior: se genera la CLC nueva al cargar el nuevo XML.
        $refactura = OrdenCompraRefactura::where('orden_compra_id', $ordenCompra->id)->whereNotNull('clc_folio_anterior')->whereNull('clc_nueva')->latest('id')->first();
        if ($refactura) {
            DB::transaction(fn () => $this->crearClc($ordenCompra->fresh(), " (refactura; sustituye a la CLC {$refactura->clc_folio_anterior})"));
            $refactura->update(['clc_nueva' => $ordenCompra->fresh()->clc_folio_completo]);

            return response()->json(['message' => "Factura aceptada y CLC {$refactura->clc_nueva} generada (sustituye a la {$refactura->clc_folio_anterior}).", 'aviso' => $aviso]);
        }

        return response()->json(['message' => 'Factura aceptada (vigente en el SAT): el gasto pasa de comprometido a devengado.', 'aviso' => $aviso]);
    }

    /** Vuelve a consultar en el SAT la factura de la orden (el proveedor pudo cancelarla después de entregarla). */
    public function verificarSat(OrdenCompra $ordenCompra, EstadoCfdiSat $estadoSat): JsonResponse
    {
        if (! $ordenCompra->xml_uuid) {
            return $this->error('La orden de compra no tiene factura cargada.');
        }

        $sat = $estadoSat->consultarOrden($ordenCompra);
        if (! $sat) {
            return $this->error($estadoSat->motivo ?? 'El SAT no respondió; intenta más tarde.');
        }

        $anterior = $ordenCompra->sat_estado;
        EstadoCfdiSat::guardar($ordenCompra, $sat);

        if ($anterior !== $sat['estado']) {
            $requisicion = $ordenCompra->requisicion;
            $this->bitacora($requisicion, (int) $requisicion->id_cat_egreso_requisicion_estado,
                "Factura {$ordenCompra->xml_uuid} verificada en el SAT por ".auth()->user()->name.": {$sat['estado']}"
                .($sat['estatus_cancelacion'] ? " ({$sat['estatus_cancelacion']})" : '').'.');
        }

        $vigente = $sat['estado'] === EstadoCfdiSat::VIGENTE;

        return response()->json([
            'vigente' => $vigente,
            'message' => $vigente
                ? 'Factura vigente en el SAT.'.($sat['estatus_cancelacion'] ? " Estatus de cancelación: {$sat['estatus_cancelacion']}." : '')
                : EstadoCfdiSat::error($sat, $ordenCompra->xml_uuid),
            'aviso' => $sat['efos'] ? self::avisoEfos($ordenCompra->xml_rfc_emisor) : null,
        ]);
    }

    private static function avisoEfos(?string $rfc): string
    {
        return "Atención: el emisor {$rfc} aparece en la lista de EFOS del SAT (empresas que facturan operaciones simuladas). Revisa antes de pagar.";
    }

    /**
     * Evita cargar dos veces la misma factura: por folio fiscal (UUID) y por serie + folio del mismo
     * emisor (la misma factura cancelada y vuelta a timbrar trae otro UUID).
     *
     * @return array<int, string>
     */
    private function duplicidades(OrdenCompra $ordenCompra, array $cfdi): array
    {
        $donde = fn (OrdenCompra $o) => "la orden {$o->folio_completo}".($o->requisicion ? " (requisición {$o->requisicion->folio_completo})" : '');
        $errores = [];

        $mismoUuid = OrdenCompra::with('requisicion:id_tb_egreso_requisicion,folio_completo')
            ->where('xml_uuid', $cfdi['uuid'])->first();
        if ($mismoUuid) {
            $errores[] = "La factura {$cfdi['uuid']} ya está cargada en {$donde($mismoUuid)}.";
        }

        // Una factura cancelada (con su orden de compra) se puede cargar una sola vez más.
        $cancelada = OrdenCompra::where('xml_uuid_cancelado', $cfdi['uuid'])->orderBy('id')->get(['folio_completo']);
        if ($cancelada->count() >= 2) {
            $errores[] = "La factura {$cfdi['uuid']} ya se canceló con las órdenes {$cancelada->pluck('folio_completo')->implode(' y ')}: solo se puede volver a cargar una vez.";
        }

        if ($cfdi['folio'] && $cfdi['rfc_emisor']) {
            $mismoFolio = OrdenCompra::with('requisicion:id_tb_egreso_requisicion,folio_completo')
                ->whereKeyNot($ordenCompra->getKey())
                ->whereNull('cancelada_at') // la de una orden cancelada ya no cuenta
                ->when($mismoUuid, fn ($q) => $q->whereKeyNot($mismoUuid->getKey()))
                ->whereRaw('UPPER(xml_rfc_emisor) = ?', [mb_strtoupper($cfdi['rfc_emisor'])])
                ->where('xml_folio', $cfdi['folio'])
                ->where(fn ($q) => $cfdi['serie'] ? $q->where('xml_serie', $cfdi['serie']) : $q->whereNull('xml_serie')->orWhere('xml_serie', ''))
                ->first();
            if ($mismoFolio) {
                $factura = trim(($cfdi['serie'] ? "serie {$cfdi['serie']} " : '')."folio {$cfdi['folio']}");
                $errores[] = "La factura {$factura} de {$cfdi['rfc_emisor']} ya está cargada en {$donde($mismoFolio)} (con el folio fiscal {$mismoFolio->xml_uuid}). Si el proveedor la volvió a timbrar, se debe cancelar la anterior.";
            }
        }

        return $errores;
    }

    private function guardarFactura(OrdenCompra $ordenCompra, array $cfdi, string $ruta): void
    {
        DB::transaction(function () use ($ordenCompra, $cfdi, $ruta) {
            $ordenCompra->update([
                'xml_path' => $ruta,
                'xml_uuid' => $cfdi['uuid'],
                'xml_rfc_emisor' => $cfdi['rfc_emisor'],
                'xml_nombre_emisor' => $cfdi['nombre_emisor'],
                'xml_serie' => $cfdi['serie'],
                'xml_folio' => $cfdi['folio'],
                'xml_fecha' => $cfdi['fecha'],
                'xml_subtotal' => $cfdi['subtotal'],
                'xml_total' => $cfdi['total'],
                'xml_isr' => $cfdi['isr'],
                'xml_cargado_at' => now(),
                'xml_user_id' => auth()->id(),
            ]);

            $this->bitacora($ordenCompra->requisicion, EgresoRequisicion::CON_FACTURA, "Factura {$cfdi['uuid']} por $".number_format($cfdi['total'], 2)." cargada a la orden {$ordenCompra->folio_completo}.");
        });
    }

    // ---------------------------------------------------------------- CLC de la orden

    /**
     * El área genera la CLC (cuenta por liquidar certificada) de una orden de compra con factura:
     * es la solicitud de pago al proveedor. Folio consecutivo por año: CLC-2026-0001.
     */
    public function generarClc(OrdenCompra $ordenCompra): JsonResponse
    {
        $this->autorizarArea($ordenCompra->requisicion, soloArea: true);

        if (! $ordenCompra->xml_uuid) {
            return $this->error('La CLC se genera cuando la orden de compra ya tiene su factura (XML).');
        }
        if ($ordenCompra->cancelada_at) {
            return $this->error('La orden de compra está cancelada.');
        }
        if ($ordenCompra->clc_folio_completo) {
            // También si se canceló: lo que sigue es cancelar la orden de compra y la requisición.
            return $this->error("Esta orden de compra ya tiene la CLC {$ordenCompra->clc_folio_completo}"
                .($ordenCompra->clc_cancelada_at ? ' (cancelada); sigue cancelar la orden de compra.' : '.'));
        }

        DB::transaction(fn () => $this->crearClc($ordenCompra));

        return response()->json(['message' => "CLC {$ordenCompra->clc_folio_completo} generada. Sigue enviarla a Recursos Materiales (Trámite de CLC)."]);
    }

    public function clcPdf(OrdenCompra $ordenCompra)
    {
        abort_unless($ordenCompra->clc_folio_completo, 404);
        abort_if($ordenCompra->clc_cancelada_at, 403, "La CLC {$ordenCompra->clc_folio_completo} está cancelada: ya no se imprime.");
        $this->autorizarArea($ordenCompra->requisicion);

        $ordenCompra->load('proveedor', 'requisicion.tbEgresoRequisicionDetalles', 'requisicion.catEgresoUnidadAdministrativa');

        $pdf = Pdf::loadView('ordenes-compra.clc', ['orden' => $ordenCompra])->setPaper('letter', 'landscape');

        // "Página N de M" en el pie (dompdf no conoce el total de páginas desde el CSS).
        $pdf->render();
        $canvas = $pdf->getDomPDF()->getCanvas();
        $canvas->page_text($canvas->get_width() - 100, $canvas->get_height() - 28, 'Página {PAGE_NUM} de {PAGE_COUNT}',
            $pdf->getDomPDF()->getFontMetrics()->getFont('Helvetica'), 7);

        return $pdf->stream("{$ordenCompra->clc_folio_completo}.pdf");
    }

    /**
     * Cancelación escalonada, de lo último a lo primero: CLC → orden de compra → requisición. Cancelar la CLC
     * (según el paso del trámite, aun pagada para Admin) la saca del trámite; después sigue cancelar la orden.
     */
    public function cancelarClc(Request $request, OrdenCompra $ordenCompra, TramiteClc $tramite): JsonResponse
    {
        if ($impedimento = TramiteClc::impedimentoParaCancelar($ordenCompra, $request->user())) {
            return $this->error($impedimento);
        }
        $motivo = $this->motivo($request);
        $user = $request->user();
        $pagada = $ordenCompra->pago_fecha !== null;

        DB::transaction(function () use ($ordenCompra, $tramite, $user, $motivo, $pagada) {
            $tramite->cancelar($ordenCompra, $user, $motivo);

            $this->bitacora($ordenCompra->requisicion, EgresoRequisicion::CON_FACTURA,
                "CLC {$ordenCompra->clc_folio_completo} cancelada por {$user->name}"
                .($pagada ? ' (el pago'.($ordenCompra->transferencia_numero ? " {$ordenCompra->transferencia_numero}" : '').' queda anulado)' : '')
                .". Motivo: {$motivo}. Sigue cancelar la orden de compra {$ordenCompra->folio_completo}.");
        });

        return response()->json(['message' => "CLC {$ordenCompra->clc_folio_completo} cancelada. Sigue cancelar la orden de compra."]);
    }

    /**
     * Registra el pago de una orden (momento contable "pagado"). Es el último paso del trámite de la
     * CLC: solo cuando Egresos ya la recibió (TramiteClc, acción "pagar").
     */
    public function registrarPago(Request $request, OrdenCompra $ordenCompra, TramiteClc $tramite): JsonResponse
    {
        if ($ordenCompra->pago_fecha) {
            return $this->error('Esta orden de compra ya tiene su pago registrado.');
        }
        if ($ordenCompra->clc_estado !== 'recibida_egresos') {
            return $this->error('El pago se registra cuando Egresos ya recibió la CLC (Trámite de CLC). Hoy está: '.TramiteClc::descripcion($ordenCompra->clc_estado).'.');
        }

        $tramite->ejecutar('pagar', [$ordenCompra->id], $request->user(), $request->only('pago_fecha', 'pago_forma', 'pago_referencia'));

        return response()->json(['message' => 'Pago registrado.']);
    }

    /** Folio consecutivo por año (CLC-2026-0001), bitácora y arranque del trámite. */
    private function crearClc(OrdenCompra $ordenCompra, string $nota = ''): void
    {
        $año = (int) now('America/Mexico_City')->format('Y');
        // Los folios de CLC sustituidas por una refactura ya no están en la orden: también cuentan, para no repetirlos.
        $sustituidas = OrdenCompraRefactura::where('clc_folio_anterior', 'like', "CLC-{$año}-%")->pluck('clc_folio_anterior')
            ->map(fn (string $f) => (int) substr($f, strrpos($f, '-') + 1))->max() ?? 0;
        $folio = max(OrdenCompra::where('clc_año', $año)->lockForUpdate()->max('clc_folio') ?? 0, $sustituidas) + 1;

        $ordenCompra->update([
            'clc_folio' => $folio,
            'clc_año' => $año,
            'clc_folio_completo' => sprintf('CLC-%d-%04d', $año, $folio),
            'clc_fecha' => now('America/Mexico_City')->toDateString(),
            'clc_user_id' => auth()->id(),
            'clc_generada_at' => now(),
        ]);

        $this->bitacora($ordenCompra->requisicion, EgresoRequisicion::CLC_GENERADA, "CLC {$ordenCompra->clc_folio_completo} generada por $".number_format($ordenCompra->importe, 2)." a favor de {$ordenCompra->proveedor?->nombre}{$nota}.");
        app(TramiteClc::class)->generada($ordenCompra, auth()->user());
    }

    /**
     * Refactura (Admin): el proveedor volvió a facturar. Se quita la factura vigente de la orden (queda en el
     * historial), se cancela su CLC si la tenía y la orden queda lista para cargar el nuevo XML; al cargarlo
     * se genera una CLC nueva. Una CLC ya pagada no se refactura.
     */
    public function refacturar(Request $request, OrdenCompra $ordenCompra, TramiteClc $tramite): JsonResponse
    {
        if ($ordenCompra->cancelada_at) {
            return $this->error('La orden de compra está cancelada.');
        }
        if (! $ordenCompra->xml_uuid) {
            return $this->error('La orden de compra no tiene factura que refacturar.');
        }
        if ($ordenCompra->pago_fecha && ! $ordenCompra->clc_cancelada_at) {
            return $this->error("La CLC {$ordenCompra->clc_folio_completo} ya está pagada: no se puede refacturar.");
        }
        $motivo = $this->motivo($request);
        $user = $request->user();

        DB::transaction(function () use ($ordenCompra, $tramite, $user, $motivo) {
            $clcAnterior = $ordenCompra->clc_folio_completo;
            $uuidAnterior = $ordenCompra->xml_uuid;
            $pagoAnulado = $ordenCompra->pago_fecha ? trim('pago anulado '.($ordenCompra->transferencia_numero ?? '')) : null;
            $facturaAnterior = trim(($ordenCompra->xml_serie ?? '').' '.($ordenCompra->xml_folio ?? '')) ?: null;

            OrdenCompraRefactura::create([
                'orden_compra_id' => $ordenCompra->id,
                'xml_uuid_anterior' => $ordenCompra->xml_uuid, 'xml_folio_anterior' => $facturaAnterior,
                'xml_total_anterior' => $ordenCompra->xml_total, 'xml_path_anterior' => $ordenCompra->xml_path,
                'clc_folio_anterior' => $clcAnterior, 'clc_estado_anterior' => $ordenCompra->clc_estado,
                'motivo' => $motivo, 'user_id' => $user->id,
            ]);

            // La CLC vigente se cancela en el trámite (queda su movimiento) y la orden queda sin CLC.
            if ($ordenCompra->clcActiva()) {
                $tramite->cancelar($ordenCompra, $user, "Refactura: {$motivo}");
            }

            $ordenCompra->update([
                'xml_path' => null, 'xml_uuid' => null, 'xml_rfc_emisor' => null, 'xml_nombre_emisor' => null,
                'xml_serie' => null, 'xml_folio' => null, 'xml_fecha' => null, 'xml_subtotal' => null, 'xml_total' => null,
                'xml_isr' => null, 'xml_cargado_at' => null, 'xml_user_id' => null,
                'sat_estado' => null, 'sat_estatus_cancelacion' => null, 'sat_efos' => null, 'sat_verificado_at' => null,
                'clc_folio' => null, 'clc_año' => null, 'clc_folio_completo' => null, 'clc_fecha' => null, 'clc_user_id' => null,
                'clc_generada_at' => null, 'clc_estado' => null, 'clc_relacion_id' => null,
                'clc_cancelada_at' => null, 'clc_cancelada_user_id' => null, 'clc_motivo_cancelacion' => null,
                // Un pago anulado (CLC cancelada) no pasa a la CLC nueva.
                'pago_fecha' => null, 'pago_forma' => null, 'pago_referencia' => null, 'pago_user_id' => null, 'pago_registrado_at' => null,
            ]);

            $this->bitacora($ordenCompra->requisicion, EgresoRequisicion::CON_ORDEN_COMPRA,
                "Refactura de la orden {$ordenCompra->folio_completo} por {$user->name}: se quita la factura ".trim(($facturaAnterior ?? '')." ({$uuidAnterior})")
                .($clcAnterior ? " y se cancela la CLC {$clcAnterior}" : '').($pagoAnulado ? " ({$pagoAnulado})" : '').". Motivo: {$motivo}. Al cargar el nuevo XML se genera una CLC nueva.");
        });

        return response()->json(['message' => "Orden {$ordenCompra->folio_completo} lista para la nueva factura. Al cargar el XML se genera una CLC nueva."]);
    }

    /** Cancela la orden de compra (con su factura); antes debe estar cancelada su CLC. Sigue la requisición. */
    public function cancelar(Request $request, OrdenCompra $ordenCompra): JsonResponse
    {
        if ($ordenCompra->cancelada_at) {
            return $this->error('Esta orden de compra ya está cancelada.');
        }
        if ($ordenCompra->clcActiva()) {
            return $this->error("Primero cancela la CLC {$ordenCompra->clc_folio_completo}: se cancela de lo último a lo primero.");
        }
        $motivo = $this->motivo($request);
        $user = $request->user();

        DB::transaction(function () use ($ordenCompra, $user, $motivo) {
            // Con la orden se cancela su factura: el UUID pasa al historial y queda libre para cargarse
            // una vez más en otra orden (ver duplicidades()).
            $factura = $ordenCompra->xml_uuid;
            $ordenCompra->update([
                'cancelada_at' => now(), 'cancelada_user_id' => $user->id, 'motivo_cancelacion' => $motivo,
                'xml_uuid' => null, 'xml_uuid_cancelado' => $factura,
            ]);

            $requisicion = $ordenCompra->requisicion;
            $this->bitacora($requisicion, EgresoRequisicion::COMPROMETIDA,
                "Orden de compra {$ordenCompra->folio_completo} cancelada por {$user->name}"
                .($factura ? " con su factura {$factura} (se puede cargar una vez más en otra orden)" : '')
                .". Motivo: {$motivo}. Sigue cancelar la requisición {$requisicion->folio_completo}.");
        });

        return response()->json(['message' => "Orden de compra {$ordenCompra->folio_completo} cancelada. Sigue cancelar la requisición."]);
    }

    public function descargarXml(OrdenCompra $ordenCompra)
    {
        $this->autorizarArea($ordenCompra->requisicion);
        abort_unless($ordenCompra->xml_path && Storage::disk('local')->exists($ordenCompra->xml_path), 404);

        return Storage::disk('local')->download($ordenCompra->xml_path, "{$ordenCompra->folio_completo}.xml");
    }

    private function motivo(Request $request): string
    {
        return $request->validate(
            ['motivo' => 'required|string|min:5|max:1000'],
            ['motivo.required' => 'Indica el motivo.', 'motivo.min' => 'El motivo debe tener al menos 5 caracteres.'],
        )['motivo'];
    }

    /**
     * Los PDF y el XML son del área que hizo la requisición; Admin, Control Presupuestal, Recursos
     * Materiales y quien participa en el trámite de CLC ven todos (en SIEG el PDF de la orden y el XML no
     * revisaban el área). Generar la CLC ($soloArea) es del área, Admin o Recursos Materiales, como en SIEG.
     */
    private function autorizarArea(EgresoRequisicion $requisicion, bool $soloArea = false): void
    {
        $user = auth()->user();
        if ($user->hasRole(['Super Admin', 'Admin']) || $user->can('ordenes-compra-create')) {
            return;
        }
        // Quien participa en el trámite de CLC (no basta clc-index: también lo tiene el área solicitante).
        if (! $soloArea && $user->canAny(['modificaciones-autorizar', 'clc-recursos-materiales', 'clc-control-presupuestal', 'clc-sindicatura', 'clc-egresos'])) {
            return;
        }

        $unidad = $user->id_cat_egreso_unidad_administrativa;
        abort_unless($unidad && in_array((int) $requisicion->id_cat_egreso_unidad_administrativa, \App\Models\EgresoUnidadAdministrativa::idsMismaClave($unidad), true), 403,
            'Esta requisición no pertenece a tu área.');
    }

    /** Extrae los datos relevantes de un CFDI 3.3 / 4.0 sin depender de la versión del namespace. */
    private function leerCfdi(string $contenido): array
    {
        $dom = new DOMDocument;
        $previo = libxml_use_internal_errors(true);
        $ok = $dom->loadXML($contenido, LIBXML_NONET);
        libxml_use_internal_errors($previo);

        $xpath = $ok ? new DOMXPath($dom) : null;
        $comprobante = $xpath?->query('/*[local-name()="Comprobante"]')->item(0);
        if (! $comprobante) {
            throw ValidationException::withMessages(['xml' => 'El archivo no es un CFDI válido (no se encontró el nodo Comprobante).']);
        }

        $emisor = $xpath->query('/*[local-name()="Comprobante"]/*[local-name()="Emisor"]')->item(0);
        $timbre = $xpath->query('//*[local-name()="TimbreFiscalDigital"]')->item(0);
        $attr = fn ($nodo, $nombre) => $nodo && $nodo->hasAttribute($nombre) ? trim($nodo->getAttribute($nombre)) : null;

        $total = $attr($comprobante, 'Total');
        if ($total === null || ! is_numeric($total)) {
            throw ValidationException::withMessages(['xml' => 'El CFDI no trae el atributo Total.']);
        }

        return [
            'total' => round((float) $total, 2),
            'subtotal' => is_numeric($attr($comprobante, 'SubTotal')) ? round((float) $attr($comprobante, 'SubTotal'), 2) : null,
            'serie' => $attr($comprobante, 'Serie'),
            'folio' => $attr($comprobante, 'Folio'),
            'fecha' => $attr($comprobante, 'Fecha') ? date('Y-m-d H:i:s', strtotime($attr($comprobante, 'Fecha'))) : null,
            'rfc_emisor' => $attr($emisor, 'Rfc'),
            'nombre_emisor' => $attr($emisor, 'Nombre'),
            'uuid' => $attr($timbre, 'UUID') ? mb_strtoupper($attr($timbre, 'UUID')) : null,
        ];
    }

    /** Pasa la requisición al estado $nuevo y lo deja en su bitácora. */
    private function bitacora(EgresoRequisicion $requisicion, int $nuevo, string $observaciones): void
    {
        $anterior = (int) $requisicion->id_cat_egreso_requisicion_estado;
        if ($anterior !== $nuevo) {
            $requisicion->update(['id_cat_egreso_requisicion_estado' => $nuevo]);
        }

        EgresoRequisicionBitacora::create([
            'id_tb_egreso_requisicion_bitacora' => (EgresoRequisicionBitacora::max('id_tb_egreso_requisicion_bitacora') ?? 0) + 1,
            'id_tb_egreso_requisicion' => $requisicion->id_tb_egreso_requisicion,
            'id_cat_egreso_requisicion_estado_anterior' => $anterior,
            'id_cat_egreso_requisicion_estado_nuevo' => $nuevo,
            'id_tb_usuarios' => 1,
            'observaciones' => $observaciones,
        ]);
    }

    private function error(string $mensaje): JsonResponse
    {
        return response()->json(['message' => $mensaje], 422);
    }
}
