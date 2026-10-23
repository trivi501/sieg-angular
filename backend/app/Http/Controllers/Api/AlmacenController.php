<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AlmacenEntrada;
use App\Models\AlmacenSalida;
use App\Models\EgresoRequisicionBitacora;
use App\Models\OrdenCompra;
use App\Services\Almacen;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

/**
 * Almacén conforme a la orden de compra: bandeja de órdenes asignadas, entradas y salidas por partes,
 * inventario de lo recibido y los formatos (acta de entrega, entrada y salida de almacén).
 * SIEG: AlmacenController, respondiendo JSON. Las reglas están en App\Services\Almacen.
 */
class AlmacenController extends Controller
{
    public function __construct(private Almacen $almacen) {}

    /** Órdenes de compra asignadas al almacén con lo recibido, entregado y en existencia. */
    public function index(Request $request): JsonResponse
    {
        $filtros = $request->only('buscar', 'situacion');
        $ordenes = OrdenCompra::whereNotNull('almacen_asignada_at')->whereNull('cancelada_at')
            ->with(['proveedor:id,nombre', 'requisicion:id_tb_egreso_requisicion,folio_completo,id_cat_egreso_unidad_administrativa',
                'requisicion.tbEgresoRequisicionDetalles', 'requisicion.catEgresoUnidadAdministrativa:id_cat_egreso_unidad_administrativa,clave,nombre'])
            ->orderByDesc('almacen_asignada_at')->get()
            ->map(fn (OrdenCompra $o) => $this->resumen($o))
            ->filter(fn ($o) => match ($filtros['situacion'] ?? '') {
                'por_recibir' => $o['por_recibir'] > 0,
                'en_existencia' => $o['existencia'] > 0,
                'entregadas' => $o['por_recibir'] == 0 && $o['existencia'] == 0,
                default => true,
            })
            ->filter(fn ($o) => $this->coincide($filtros['buscar'] ?? '', [$o['orden'], $o['requisicion'], $o['proveedor'], $o['unidad']]))
            ->values();

        return response()->json($this->paginar($ordenes, $request));
    }

    /** Una orden de compra en el almacén: conceptos, entradas y salidas. */
    public function show(Request $request, OrdenCompra $ordenCompra): JsonResponse
    {
        abort_unless($ordenCompra->almacen_asignada_at, 404, 'Esta orden de compra no está asignada al almacén.');
        $ordenCompra->load(['proveedor', 'requisicion.tbEgresoRequisicionDetalles', 'requisicion.catEgresoUnidadAdministrativa',
            'entradasAlmacen.detalles', 'entradasAlmacen.usuario:id,name', 'salidasAlmacen.detalles', 'salidasAlmacen.usuario:id,name']);
        $user = $request->user();
        $cancelada = $ordenCompra->cancelada_at !== null;

        return response()->json([
            'orden' => [
                ...$this->resumen($ordenCompra),
                'fecha' => $ordenCompra->fecha?->toDateString(),
                'importe' => (float) $ordenCompra->importe,
                'factura' => $ordenCompra->xml_uuid ? (trim(($ordenCompra->xml_serie ?? '').' '.($ordenCompra->xml_folio ?? '')) ?: $ordenCompra->xml_uuid) : null,
                'cancelada' => $cancelada,
            ],
            'conceptos' => $this->almacen->conceptos($ordenCompra),
            'entradas' => $ordenCompra->entradasAlmacen->map(fn (AlmacenEntrada $e) => [
                'id' => $e->id, 'folio' => $e->folio_completo, 'fecha' => $e->fecha?->toDateString(), 'usuario' => $e->usuario?->name,
                'observaciones' => $e->observaciones, 'renglones' => $e->detalles->count(), 'cantidad' => (float) $e->detalles->sum('cantidad'),
            ]),
            'salidas' => $ordenCompra->salidasAlmacen->map(fn (AlmacenSalida $s) => [
                'id' => $s->id, 'folio' => $s->folio_completo, 'fecha' => $s->fecha?->toDateString(), 'usuario' => $s->usuario?->name,
                'recibe' => $s->recibe, 'observaciones' => $s->observaciones, 'renglones' => $s->detalles->count(), 'cantidad' => (float) $s->detalles->sum('cantidad'),
            ]),
            'puede' => ['entrada' => ! $cancelada && $user->can('almacen-entradas'), 'salida' => ! $cancelada && $user->can('almacen-salidas')],
        ]);
    }

    /** Recursos Materiales asigna la orden de compra al almacén. */
    public function asignar(Request $request, OrdenCompra $ordenCompra): JsonResponse
    {
        $this->almacen->asignar($ordenCompra, $request->user());
        $this->bitacora($ordenCompra, "Orden de compra {$ordenCompra->folio_completo} asignada al almacén por {$request->user()->name}.");

        return response()->json(['message' => "Orden de compra {$ordenCompra->folio_completo} asignada al almacén."]);
    }

    public function storeEntrada(Request $request, OrdenCompra $ordenCompra): JsonResponse
    {
        $datos = $this->validarMovimiento($request);
        $entrada = $this->almacen->registrarEntrada($ordenCompra, $request->user(), $datos['fecha'], $datos['observaciones'] ?? null, $datos['cantidades']);
        $this->bitacora($ordenCompra, "Entrada de almacén {$entrada->folio_completo} de la orden {$ordenCompra->folio_completo} registrada por {$request->user()->name}.");

        return response()->json(['message' => "Entrada {$entrada->folio_completo} registrada.", 'id' => $entrada->id, 'folio' => $entrada->folio_completo], 201);
    }

    public function storeSalida(Request $request, OrdenCompra $ordenCompra): JsonResponse
    {
        $datos = $this->validarMovimiento($request, ['recibe' => 'nullable|string|max:200']);
        $salida = $this->almacen->registrarSalida($ordenCompra, $request->user(), $datos['fecha'], $datos['recibe'] ?? null, $datos['observaciones'] ?? null, $datos['cantidades']);
        $this->bitacora($ordenCompra, "Salida de almacén {$salida->folio_completo} de la orden {$ordenCompra->folio_completo} registrada por {$request->user()->name}.");

        return response()->json(['message' => "Salida {$salida->folio_completo} registrada.", 'id' => $salida->id, 'folio' => $salida->folio_completo], 201);
    }

    /** Inventario de entradas de almacén: por concepto, lo recibido, entregado y en existencia. */
    public function inventario(Request $request): JsonResponse
    {
        $filtros = $request->only('buscar', 'todo');
        $filas = OrdenCompra::whereNotNull('almacen_asignada_at')->whereNull('cancelada_at')
            ->whereHas('entradasAlmacen')
            ->with(['proveedor:id,nombre', 'requisicion:id_tb_egreso_requisicion,folio_completo,id_cat_egreso_unidad_administrativa',
                'requisicion.tbEgresoRequisicionDetalles', 'requisicion.catEgresoUnidadAdministrativa:id_cat_egreso_unidad_administrativa,clave,nombre'])
            ->orderByDesc('id')->get()
            ->flatMap(fn (OrdenCompra $o) => $this->almacen->conceptos($o)
                ->filter(fn ($c) => $c['recibido'] > 0)
                ->map(fn ($c) => [
                    ...$c,
                    'orden_id' => $o->id,
                    'orden' => $o->folio_completo,
                    'requisicion' => $o->requisicion?->folio_completo,
                    'unidad' => $this->unidad($o),
                    'proveedor' => $o->proveedor?->nombre,
                    'valor' => round($c['existencia'] * $c['precio_unitario'], 2),
                ]))
            ->filter(fn ($c) => $request->boolean('todo') || $c['existencia'] > 0)
            ->filter(fn ($c) => $this->coincide($filtros['buscar'] ?? '', [$c['descripcion'], $c['orden'], $c['requisicion'], $c['unidad'], $c['proveedor']]))
            ->values();

        return response()->json([
            ...$this->paginar($filas, $request),
            'totales' => ['existencia' => round($filas->sum('existencia'), 2), 'valor' => round($filas->sum('valor'), 2)],
        ]);
    }

    public function actaPdf(AlmacenEntrada $entrada)
    {
        return $this->pdf('almacen.acta', $entrada, 'Acta de entrega');
    }

    public function entradaPdf(AlmacenEntrada $entrada)
    {
        return $this->pdf('almacen.entrada', $entrada, 'Entrada de almacén');
    }

    public function salidaPdf(AlmacenSalida $salida)
    {
        return $this->pdf('almacen.salida', $salida, 'Salida de almacén');
    }

    /** Lo cancelado no se imprime (orden de compra cancelada). */
    private function pdf(string $vista, AlmacenEntrada|AlmacenSalida $documento, string $titulo)
    {
        $documento->load(['detalles', 'usuario', 'ordenCompra.proveedor', 'ordenCompra.requisicion.catEgresoUnidadAdministrativa']);
        abort_if($documento->ordenCompra->cancelada_at, 403, "La orden de compra {$documento->ordenCompra->folio_completo} está cancelada: ya no se imprime.");

        $pdf = Pdf::loadView($vista, ['documento' => $documento, 'orden' => $documento->ordenCompra])->setPaper('letter');
        $pdf->render();
        $canvas = $pdf->getDomPDF()->getCanvas();
        $canvas->page_text($canvas->get_width() - 200, $canvas->get_height() - 28, "Página {PAGE_NUM} de {PAGE_COUNT}      {$documento->folio_completo}",
            $pdf->getDomPDF()->getFontMetrics()->getFont('Helvetica'), 7);

        return $pdf->stream("{$titulo} {$documento->folio_completo}.pdf");
    }

    private function validarMovimiento(Request $request, array $extra = []): array
    {
        return $request->validate([
            'fecha' => 'required|date|before_or_equal:today',
            'observaciones' => 'nullable|string|max:1000',
            'cantidades' => 'required|array',
            ...$extra,
        ], [
            'fecha.required' => 'Indica la fecha.',
            'fecha.before_or_equal' => 'La fecha no puede ser futura.',
            'cantidades.required' => 'Captura al menos una cantidad.',
        ]);
    }

    private function resumen(OrdenCompra $o): array
    {
        $conceptos = $this->almacen->conceptos($o);

        return [
            'id' => $o->id,
            'orden' => $o->folio_completo,
            'requisicion_id' => $o->id_tb_egreso_requisicion,
            'requisicion' => $o->requisicion?->folio_completo,
            'unidad' => $this->unidad($o),
            'proveedor' => $o->proveedor?->nombre,
            'asignada' => $o->almacen_asignada_at?->toIso8601String(),
            'pedido' => round($conceptos->sum('pedido'), 2),
            'recibido' => round($conceptos->sum('recibido'), 2),
            'entregado' => round($conceptos->sum('entregado'), 2),
            'por_recibir' => round($conceptos->sum('por_recibir'), 2),
            'existencia' => round($conceptos->sum('existencia'), 2),
        ];
    }

    private function unidad(OrdenCompra $o): ?string
    {
        $u = $o->requisicion?->catEgresoUnidadAdministrativa;

        return $u ? trim($u->clave.' '.$u->nombre) : null;
    }

    /** Búsqueda sin distinguir mayúsculas ni acentos, por palabras. */
    private function coincide(string $buscar, array $valores): bool
    {
        $normalizar = fn (string $s) => mb_strtolower(Str::ascii($s));
        $texto = $normalizar(implode(' ', array_filter($valores)));

        foreach (preg_split('/\s+/', trim($buscar)) ?: [] as $termino) {
            if ($termino !== '' && ! str_contains($texto, $normalizar($termino))) {
                return false;
            }
        }

        return true;
    }

    private function paginar($filas, Request $request): array
    {
        $pagina = max(1, (int) $request->input('page', 1));

        return (new LengthAwarePaginator($filas->forPage($pagina, 25)->values(), $filas->count(), 25, $pagina))->toArray();
    }

    /** Deja el movimiento en la bitácora de la requisición (sin cambiar su estado). */
    private function bitacora(OrdenCompra $orden, string $observaciones): void
    {
        $requisicion = $orden->requisicion;
        if (! $requisicion) {
            return;
        }

        EgresoRequisicionBitacora::create([
            'id_tb_egreso_requisicion_bitacora' => (EgresoRequisicionBitacora::max('id_tb_egreso_requisicion_bitacora') ?? 0) + 1,
            'id_tb_egreso_requisicion' => $requisicion->id_tb_egreso_requisicion,
            'id_cat_egreso_requisicion_estado_anterior' => $requisicion->id_cat_egreso_requisicion_estado,
            'id_cat_egreso_requisicion_estado_nuevo' => $requisicion->id_cat_egreso_requisicion_estado,
            'id_tb_usuarios' => 1,
            'observaciones' => $observaciones,
        ]);
    }
}
