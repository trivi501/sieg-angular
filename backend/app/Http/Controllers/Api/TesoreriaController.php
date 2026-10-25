<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Auditoria;
use App\Models\EgresoRequisicionDetalle;
use App\Models\OrdenCompra;
use App\Models\TesoreriaCuenta;
use App\Models\TesoreriaMovimiento;
use App\Models\TesoreriaPago;
use App\Services\ExcelMovimientos;
use App\Services\ExcelTabla;
use App\Services\TesoreriaPagos;
use App\Services\TramiteClc;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Tesorería (nuevo; SIEG no lo tenía). Mesa de control de pagos en Egresos: CLC por recibir y por pagar agrupadas
 * por beneficiario, verificación en el SAT, pago agrupado (TRF) por transferencia o cheque. Conciliación con los
 * movimientos del estado de cuenta (captura o plantilla) y reportes: posición bancaria, movimientos y cheques.
 */
class TesoreriaController extends Controller
{
    private const FORMATO_IMPORTE = '#,##0.00;[Red]-#,##0.00';

    public function __construct(private TesoreriaPagos $pagos) {}

    /** CLC enviadas a Egresos (por recibir) y recibidas (por pagar, agrupadas por beneficiario). */
    public function mesa(Request $request): JsonResponse
    {
        $fuente = trim((string) $request->input('fuente'));
        $buscar = mb_strtoupper(trim((string) $request->input('buscar')));
        $ordenes = OrdenCompra::with('proveedor:id,nombre,rfc,clabe,cuenta_bancaria', 'requisicion:id_tb_egreso_requisicion,folio_completo,beneficiario,id_cat_egreso_unidad_administrativa,observaciones',
            'requisicion.catEgresoUnidadAdministrativa:id_cat_egreso_unidad_administrativa,clave,nombre')
            ->whereIn('clc_estado', ['enviada_egresos', 'recibida_egresos'])->orderBy('clc_folio')->get();
        $fuentes = $this->fuentesPorRequisicion($ordenes->pluck('id_tb_egreso_requisicion')->filter()->unique()->all());

        $filas = $ordenes->map(fn (OrdenCompra $o) => $this->filaClc($o, $fuentes[$o->id_tb_egreso_requisicion] ?? []))
            ->filter(fn ($f) => $fuente === '' || in_array($fuente, $f['fuentes'], true))
            ->filter(fn ($f) => $buscar === '' || str_contains(mb_strtoupper($f['clc'].' '.$f['orden'].' '.$f['beneficiario'].' '.$f['rfc'].' '.$f['requisicion']), $buscar))
            ->values();
        $porPagar = $filas->where('estado', 'recibida_egresos');

        return response()->json([
            'por_recibir' => $filas->where('estado', 'enviada_egresos')->values(),
            'beneficiarios' => $porPagar->groupBy('beneficiario_clave')->map(fn (Collection $g) => [
                'clave' => $g->first()['beneficiario_clave'],
                'beneficiario' => $g->first()['beneficiario'],
                'rfc' => $g->first()['rfc'],
                'proveedor_id' => $g->first()['proveedor_id'],
                'cuenta_destino' => $g->first()['cuenta_destino'],
                'clc' => $g->count(),
                'importe' => round($g->sum('importe'), 2),
                'isr' => round($g->sum('isr'), 2),
                'neto' => round($g->sum('neto'), 2),
                'fuentes' => $g->pluck('fuentes')->flatten()->unique()->sort()->values(),
                'ordenes' => $g->values(),
            ])->sortBy('beneficiario')->values(),
            'totales' => ['por_recibir' => $filas->where('estado', 'enviada_egresos')->count(), 'por_pagar' => $porPagar->count(), 'neto' => round($porPagar->sum('neto'), 2)],
            'cuentas' => $this->cuentas(),
            'fuentes' => $fuentes->flatten()->unique()->sort()->values(),
            'puede' => ['recibir' => $request->user()->can('clc-egresos'), 'pagar' => $request->user()->can('tesoreria-pagar')],
        ]);
    }

    /** Cuentas activas del Ayuntamiento (para elegir de dónde sale un pago, también en la pantalla de CLC). */
    public function cuentasActivas(): JsonResponse
    {
        return response()->json($this->cuentas());
    }

    /** Vuelve a consultar en el SAT las facturas de una CLC (la de la orden o las de su trámite). */
    public function verificarSat(OrdenCompra $ordenCompra): JsonResponse
    {
        abort_unless($ordenCompra->clc_folio_completo, 404);
        $r = $this->pagos->verificarSat($ordenCompra);
        $mensaje = match (true) {
            $r['facturas'] === 0 => "{$ordenCompra->clc_folio_completo}: no tiene facturas que verificar (CLC sin factura).",
            (bool) $r['errores'] => "{$ordenCompra->clc_folio_completo}: ".implode(' ', $r['errores']),
            $r['sin_respuesta'] > 0 => "{$ordenCompra->clc_folio_completo}: el SAT no respondió para {$r['sin_respuesta']} de {$r['facturas']} factura(s); intenta más tarde.",
            default => "{$ordenCompra->clc_folio_completo}: ".($r['facturas'] === 1 ? 'la factura está vigente' : "las {$r['facturas']} facturas están vigentes").' en el SAT.',
        };

        return response()->json(['message' => $mensaje, 'vigente' => $r['vigente'], 'errores' => $r['errores']], $r['errores'] ? 422 : 200);
    }

    /** Paga las CLC seleccionadas: un pago (TRF) por beneficiario. */
    public function pagar(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ordenes' => 'required|array|min:1',
            'ordenes.*' => 'integer',
            'cuenta_id' => 'nullable|integer',
            'pago_forma' => 'nullable|string',
            'pago_fecha' => 'nullable|date',
            'pago_referencia' => 'nullable|string|max:100',
            'observaciones' => 'nullable|string|max:2000',
        ], ['ordenes.required' => 'Selecciona al menos una CLC.']);
        $pagos = $this->pagos->pagar($validated['ordenes'], $request->user(), $validated);

        return response()->json([
            'message' => $pagos->count() === 1
                ? "Pago {$pagos[0]->numero} por $".number_format($pagos[0]->importe, 2)." a {$pagos[0]->beneficiario} (".$pagos[0]->ordenes()->count().' CLC).'
                : "{$pagos->count()} pagos generados, uno por beneficiario: ".$pagos->pluck('numero')->implode(', ').'.',
            'pagos' => $pagos->map(fn ($p) => ['id' => $p->id, 'numero' => $p->numero]),
        ], 201);
    }

    public function listaPagos(Request $request): JsonResponse
    {
        $pagos = $this->consultaPagos($request)->with('cuenta.banco')->withCount('ordenes')->orderByDesc('id')->paginate(25);

        return response()->json([
            ...$pagos->through(fn (TesoreriaPago $p) => $this->filaPago($p))->toArray(),
            'estados' => TesoreriaPago::ESTADOS,
            'cuentas' => $this->cuentas(),
        ]);
    }

    public function pago(TesoreriaPago $pago): JsonResponse
    {
        $pago->load('cuenta.banco', 'usuario:id,name', 'ordenes.requisicion:id_tb_egreso_requisicion,folio_completo', 'movimientos');
        $user = auth()->user();

        return response()->json([
            'pago' => [...$this->filaPago($pago), 'observaciones' => $pago->observaciones, 'capturo' => $pago->usuario?->name,
                'entregado_at' => $pago->entregado_at?->toIso8601String(), 'entregado_a' => $pago->entregado_a,
                'conciliado_at' => $pago->conciliado_at?->toIso8601String(), 'motivo_cancelacion' => $pago->motivo_cancelacion],
            'ordenes' => $pago->ordenes->map(fn (OrdenCompra $o) => [
                'id' => $o->id, 'clc' => $o->clc_folio_completo, 'orden' => $o->folio_completo, 'requisicion' => $o->requisicion?->folio_completo,
                'requisicion_id' => $o->id_tb_egreso_requisicion, 'importe' => (float) $o->pivot->importe, 'sat_estado' => $o->sat_estado,
            ]),
            'movimiento' => ($m = $pago->movimientos->first()) ? $this->filaMovimiento($m) : null,
            'candidatos' => $pago->enTransito() ? $this->pagos->candidatos($pago)->take(20)->map(fn ($m) => $this->filaMovimiento($m))->values() : [],
            'puede' => [
                'cancelar' => $pago->enTransito() && $user->can('tesoreria-pagar'),
                'entregar' => $pago->esCheque() && $pago->estado === 'pagado' && $user->can('tesoreria-pagar'),
                'conciliar' => $pago->enTransito() && $user->can('tesoreria-conciliar'),
                'desconciliar' => $pago->estado === 'conciliado' && $user->can('tesoreria-conciliar'),
            ],
        ]);
    }

    public function cancelarPago(Request $request, TesoreriaPago $pago): JsonResponse
    {
        $validated = $request->validate(['motivo' => 'required|string|min:5|max:1000'], ['motivo.required' => 'Escribe el motivo de la cancelación.']);
        $this->pagos->cancelar($pago, $request->user(), trim($validated['motivo']));

        return response()->json(['message' => "Pago {$pago->numero} cancelado: sus CLC regresaron a la mesa de control para pagarse de nuevo."]);
    }

    /** Cheque entregado al beneficiario. */
    public function entregar(Request $request, TesoreriaPago $pago): JsonResponse
    {
        $validated = $request->validate(['entregado_a' => 'required|string|max:300'], ['entregado_a.required' => 'Escribe a quién se entregó el cheque.']);
        if (! $pago->esCheque() || $pago->estado !== 'pagado') {
            return response()->json(['message' => 'Solo se entrega un cheque emitido.'], 422);
        }
        $pago->update(['estado' => 'entregado', 'entregado_at' => now(), 'entregado_a' => mb_strtoupper(trim($validated['entregado_a']))]);

        return response()->json(['message' => "Cheque {$pago->cheque_numero} entregado."]);
    }

    public function conciliar(Request $request, TesoreriaPago $pago): JsonResponse
    {
        $validated = $request->validate(['movimiento_id' => 'required|integer|exists:tesoreria_movimientos,id']);
        $this->pagos->conciliar($pago, TesoreriaMovimiento::findOrFail($validated['movimiento_id']), $request->user());

        return response()->json(['message' => "Pago {$pago->numero} conciliado con el banco."]);
    }

    public function desconciliar(TesoreriaPago $pago): JsonResponse
    {
        if ($pago->estado !== 'conciliado') {
            return response()->json(['message' => 'El pago no está conciliado.'], 422);
        }
        $this->pagos->desconciliar($pago);

        return response()->json(['message' => "Se quitó la conciliación del pago {$pago->numero}."]);
    }

    /** Concilia solos los pagos con un único cargo que trae su número y el mismo monto; avisa los que no cuadran. */
    public function conciliarAutomatico(Request $request): JsonResponse
    {
        $validated = $request->validate(['cuenta_id' => 'nullable|integer']);
        $r = $this->pagos->conciliarAutomatico($request->user(), $validated['cuenta_id'] ?? null);

        return response()->json([
            'message' => ($r['conciliados'] ? count($r['conciliados']).' pago(s) conciliados: '.implode(', ', $r['conciliados']).'.' : 'Ningún pago se pudo conciliar solo.')
                .($r['con_diferencia'] ? ' No cuadran: '.implode('; ', $r['con_diferencia']).'.' : ''),
            ...$r,
        ]);
    }

    // ---------------------------------------------------------------- movimientos bancarios

    public function movimientos(Request $request): JsonResponse
    {
        $movimientos = $this->consultaMovimientos($request)->with('cuenta.banco', 'pago:id,numero')->orderByDesc('fecha')->orderByDesc('id')->paginate(50);
        $totales = (clone $this->consultaMovimientos($request))->toBase()->selectRaw("SUM(CASE WHEN tipo = 'abono' THEN importe ELSE 0 END) AS abonos, SUM(CASE WHEN tipo = 'cargo' THEN importe ELSE 0 END) AS cargos")->first();

        return response()->json([
            ...$movimientos->through(fn (TesoreriaMovimiento $m) => $this->filaMovimiento($m))->toArray(),
            'totales' => ['abonos' => round((float) $totales->abonos, 2), 'cargos' => round((float) $totales->cargos, 2)],
            'cuentas' => $this->cuentas(),
        ]);
    }

    public function movimientosExcel(Request $request)
    {
        $filas = $this->consultaMovimientos($request)->with('cuenta.banco', 'pago:id,numero')->orderBy('fecha')->orderBy('id')->get()
            ->map(fn (TesoreriaMovimiento $m) => [$m->fecha?->format('d/m/Y'), $m->cuenta?->etiqueta(), $m->cuenta?->fuente, TesoreriaMovimiento::TIPOS[$m->tipo] ?? $m->tipo,
                $m->tipo === 'abono' ? $m->importe : null, $m->tipo === 'cargo' ? $m->importe : null, $m->referencia, $m->clave_rastreo, $m->concepto, $m->pago?->numero]);

        return $this->excel(ExcelTabla::libro('Movimientos bancarios', $this->descripcionFiltros($request),
            ['Fecha', 'Cuenta', 'Fuente', 'Tipo', 'Abono', 'Cargo', 'Referencia', 'Clave de rastreo', 'Concepto', 'Pago conciliado'],
            $filas, [5 => self::FORMATO_IMPORTE, 6 => self::FORMATO_IMPORTE], [5, 6], [11, 40, 8, 8, 15, 15, 18, 26, 44, 15]), 'movimientos_bancarios');
    }

    public function guardarMovimiento(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'cuenta_id' => 'required|integer|exists:tesoreria_cuentas,id',
            'fecha' => 'required|date|before_or_equal:today',
            'tipo' => 'required|in:cargo,abono',
            'importe' => 'required|numeric|gt:0|max:999999999999',
            'referencia' => 'nullable|string|max:100',
            'clave_rastreo' => 'nullable|string|max:60',
            'concepto' => 'nullable|string|max:500',
        ], [], ['cuenta_id' => 'cuenta', 'clave_rastreo' => 'clave de rastreo']);
        $movimiento = TesoreriaMovimiento::create([...$validated, 'importe' => round((float) $validated['importe'], 2), 'origen' => 'captura', 'user_id' => $request->user()->id]);

        return response()->json(['id' => $movimiento->id, 'message' => 'Movimiento registrado.'], 201);
    }

    public function eliminarMovimiento(TesoreriaMovimiento $movimiento): JsonResponse
    {
        if ($movimiento->tesoreria_pago_id) {
            return response()->json(['message' => 'Está conciliado con un pago: primero quita la conciliación.'], 422);
        }
        $movimiento->delete();

        return response()->json(['message' => 'Movimiento eliminado.']);
    }

    public function plantillaMovimientos()
    {
        $ejemplo = [
            [now('America/Mexico_City')->format('d/m/Y'), 'CARGO', 15000.00, 'TRF-'.now()->year.'-0001', 'BNET01002611250000012345', 'SPEI ENVIADO PROVEEDOR'],
            [now('America/Mexico_City')->format('d/m/Y'), 'ABONO', 500000.00, 'PARTICIPACIONES', null, 'DEPÓSITO DE PARTICIPACIONES'],
        ];

        return $this->excel(ExcelTabla::libro('Movimientos bancarios', 'Plantilla: un renglón por movimiento del estado de cuenta (Tipo CARGO o ABONO); borra los de ejemplo',
            ExcelMovimientos::PLANTILLA, $ejemplo, [3 => self::FORMATO_IMPORTE], [], [12, 9, 15, 18, 28, 40]), 'plantilla_movimientos');
    }

    /** Importa el estado de cuenta de una cuenta. Los movimientos ya registrados (misma fecha, tipo, importe y referencia) se saltan. */
    public function importarMovimientos(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'cuenta_id' => 'required|integer|exists:tesoreria_cuentas,id',
            'archivo' => 'required|file|mimes:xlsx,xls,csv|max:10240',
        ], [], ['cuenta_id' => 'cuenta', 'archivo' => 'archivo']);
        $leido = ExcelMovimientos::leer($request->file('archivo')->getRealPath());
        if ($leido['errores']) {
            throw ValidationException::withMessages(['archivo' => count($leido['errores']).' renglón(es) con error; corrígelos y vuelve a subirlo: '.implode(' ', array_slice($leido['errores'], 0, 5))]);
        }
        if (! $leido['movimientos']) {
            throw ValidationException::withMessages(['archivo' => 'El archivo no tiene movimientos.']);
        }

        $nuevos = 0;
        $repetidos = 0;
        // Closure (no fn): los contadores van por referencia hasta adentro de la transacción.
        Auditoria::enLote("Importación de movimientos bancarios ({$request->file('archivo')->getClientOriginalName()})", function () use ($leido, $validated, $request, &$nuevos, &$repetidos) {
            DB::transaction(function () use ($leido, $validated, $request, &$nuevos, &$repetidos) {
                foreach ($leido['movimientos'] as $m) {
                    $existe = TesoreriaMovimiento::where('cuenta_id', $validated['cuenta_id'])->whereDate('fecha', $m['fecha'])->where('tipo', $m['tipo'])
                        ->where('importe', $m['importe'])->where('referencia', $m['referencia'])->exists();
                    if ($existe) {
                        $repetidos++;

                        continue;
                    }
                    TesoreriaMovimiento::create([...collect($m)->except('fila')->all(), 'cuenta_id' => $validated['cuenta_id'], 'origen' => 'plantilla', 'user_id' => $request->user()->id]);
                    $nuevos++;
                }
            });
        });

        return response()->json(['message' => "{$nuevos} movimiento(s) importados".($repetidos ? "; {$repetidos} ya estaban registrados y se saltaron" : '').'.']);
    }

    // ---------------------------------------------------------------- reportes

    /**
     * Posición bancaria a una fecha: por cuenta, saldo según el banco (saldo inicial + abonos − cargos), pagos en
     * tránsito (emitidos y aún no conciliados) y saldo disponible; con totales por banco y por fuente.
     */
    public function posicion(Request $request): JsonResponse
    {
        return response()->json($this->datosPosicion($request));
    }

    public function posicionExcel(Request $request)
    {
        $d = $this->datosPosicion($request);
        $filas = collect($d['cuentas'])->map(fn ($c) => [$c['banco'], $c['cuenta'], $c['fuente'], $c['saldo_inicial'], $c['abonos'], $c['cargos'], $c['saldo_banco'], $c['en_transito'], $c['disponible']]);

        return $this->excel(ExcelTabla::libro('Posición bancaria', 'Al '.date('d/m/Y', strtotime($d['corte'])),
            ['Banco', 'Cuenta', 'Fuente', 'Saldo inicial', 'Abonos', 'Cargos', 'Saldo en banco', 'Pagos en tránsito', 'Disponible'],
            $filas, array_fill_keys(range(4, 9), self::FORMATO_IMPORTE), range(4, 9), [24, 40, 8, 16, 16, 16, 16, 16, 16]), 'posicion_bancaria');
    }

    /** Cheques emitidos, entregados, cobrados (conciliados) y cancelados. */
    public function cheques(Request $request): JsonResponse
    {
        $cheques = $this->consultaPagos($request)->where('forma', 'cheque')->with('cuenta.banco')->withCount('ordenes')->orderByDesc('fecha')->orderByDesc('id')->get();

        return response()->json([
            'cheques' => $cheques->map(fn ($p) => $this->filaPago($p))->values(),
            'resumen' => collect(TesoreriaPago::ESTADOS_CHEQUE)->map(fn ($texto, $estado) => ['estado' => $estado, 'texto' => $texto,
                'cantidad' => $cheques->where('estado', $estado)->count(), 'importe' => round($cheques->where('estado', $estado)->sum('importe'), 2)])->values(),
            'cuentas' => $this->cuentas(),
        ]);
    }

    public function chequesExcel(Request $request)
    {
        $filas = $this->consultaPagos($request)->where('forma', 'cheque')->with('cuenta.banco')->orderBy('fecha')->get()
            ->map(fn (TesoreriaPago $p) => [$p->fecha?->format('d/m/Y'), $p->cheque_numero, $p->numero, $p->beneficiario, $p->cuenta?->etiqueta(), $p->importe,
                $p->estadoTexto(), $p->entregado_at?->format('d/m/Y'), $p->entregado_a, $p->conciliado_at?->format('d/m/Y'), $p->motivo_cancelacion]);

        return $this->excel(ExcelTabla::libro('Cheques', $this->descripcionFiltros($request),
            ['Fecha', 'Cheque', 'Pago', 'Beneficiario', 'Cuenta', 'Importe', 'Estado', 'Entregado', 'Entregado a', 'Cobrado', 'Motivo de cancelación'],
            $filas, [6 => self::FORMATO_IMPORTE], [6], [11, 12, 15, 40, 36, 15, 12, 11, 30, 11, 36]), 'cheques');
    }

    // ----------------------------------------------------------------

    private function filaClc(OrdenCompra $o, array $fuentes): array
    {
        $b = TesoreriaPagos::beneficiario($o);

        return [
            'id' => $o->id,
            'clc' => $o->clc_folio_completo,
            'clc_fecha' => $o->clc_fecha?->toDateString(),
            'estado' => $o->clc_estado,
            'estado_texto' => TramiteClc::descripcion($o->clc_estado),
            'orden' => $o->folio_completo,
            'requisicion' => $o->requisicion?->folio_completo,
            'requisicion_id' => $o->id_tb_egreso_requisicion,
            'unidad' => $o->requisicion?->catEgresoUnidadAdministrativa ? trim($o->requisicion->catEgresoUnidadAdministrativa->clave.' '.$o->requisicion->catEgresoUnidadAdministrativa->nombre) : null,
            'concepto' => $o->requisicion?->observaciones,
            'beneficiario_clave' => $b['clave'],
            'beneficiario' => $b['nombre'],
            'proveedor_id' => $b['proveedor_id'],
            'rfc' => $b['proveedor_id'] ? $o->proveedor?->rfc : null,
            'cuenta_destino' => $b['proveedor_id'] ? ($o->proveedor?->clabe ?: $o->proveedor?->cuenta_bancaria) : null,
            'importe' => (float) $o->importe,
            'isr' => (float) ($o->xml_isr ?? 0),
            'neto' => $o->netoAPagar(),
            'fuentes' => array_values($fuentes),
            'facturas' => $o->xml_uuid ? 1 : ($o->tramite_servicio_id ? null : 0),
            'sat_estado' => $o->sat_estado,
            'sat_verificado_at' => $o->sat_verificado_at?->toIso8601String(),
        ];
    }

    /** @return Collection<int, string[]> id_tb_egreso_requisicion → claves de fuente de sus renglones */
    private function fuentesPorRequisicion(array $ids): Collection
    {
        return EgresoRequisicionDetalle::whereIn('id_tb_egreso_requisicion', $ids ?: [0])->whereNotNull('fuente')
            ->get(['id_tb_egreso_requisicion', 'fuente'])->groupBy('id_tb_egreso_requisicion')
            ->map(fn ($g) => $g->pluck('fuente')->map(fn ($f) => trim((string) $f))->filter()->unique()->values()->all());
    }

    private function filaPago(TesoreriaPago $p): array
    {
        return [
            'id' => $p->id,
            'numero' => $p->numero,
            'fecha' => $p->fecha?->toDateString(),
            'beneficiario' => $p->beneficiario,
            'forma' => $p->forma,
            'forma_texto' => TesoreriaPago::FORMAS[$p->forma] ?? $p->forma,
            'cheque_numero' => $p->cheque_numero,
            'cuenta_destino' => $p->cuenta_destino,
            'cuenta_id' => $p->cuenta_id,
            'cuenta' => $p->cuenta?->etiqueta(),
            'fuente' => $p->cuenta?->fuente,
            'importe' => $p->importe,
            'estado' => $p->estado,
            'estado_texto' => $p->estadoTexto(),
            'clc' => $p->ordenes_count ?? $p->ordenes()->count(),
        ];
    }

    private function filaMovimiento(TesoreriaMovimiento $m): array
    {
        return [
            'id' => $m->id,
            'cuenta_id' => $m->cuenta_id,
            'cuenta' => $m->cuenta?->etiqueta(),
            'fecha' => $m->fecha?->toDateString(),
            'tipo' => $m->tipo,
            'importe' => $m->importe,
            'referencia' => $m->referencia,
            'clave_rastreo' => $m->clave_rastreo,
            'concepto' => $m->concepto,
            'origen' => $m->origen,
            'pago_id' => $m->tesoreria_pago_id,
            'pago' => $m->pago?->numero,
        ];
    }

    private function cuentas(): Collection
    {
        return TesoreriaCuenta::with('banco')->where('activo', true)->orderBy('nombre')->get()
            ->map(fn (TesoreriaCuenta $c) => ['id' => $c->id, 'etiqueta' => $c->etiqueta(), 'fuente' => $c->fuente, 'banco' => $c->banco?->descripcion]);
    }

    private function consultaPagos(Request $request)
    {
        $f = $request->validate([
            'estado' => 'nullable|string', 'forma' => 'nullable|in:transferencia,cheque', 'cuenta_id' => 'nullable|integer',
            'desde' => 'nullable|date', 'hasta' => 'nullable|date', 'buscar' => 'nullable|string|max:100',
        ]);

        return TesoreriaPago::query()
            ->when($f['estado'] ?? null, fn ($q, $v) => $v === 'transito' ? $q->whereIn('estado', ['pagado', 'entregado']) : $q->where('estado', $v))
            ->when($f['forma'] ?? null, fn ($q, $v) => $q->where('forma', $v))
            ->when($f['cuenta_id'] ?? null, fn ($q, $v) => $q->where('cuenta_id', $v))
            ->when($f['desde'] ?? null, fn ($q, $v) => $q->whereDate('fecha', '>=', $v))
            ->when($f['hasta'] ?? null, fn ($q, $v) => $q->whereDate('fecha', '<=', $v))
            ->when(filled($f['buscar'] ?? null), fn ($q) => $q->where(fn ($q) => $q->where('numero', 'like', "%{$f['buscar']}%")
                ->orWhere('beneficiario', 'like', "%{$f['buscar']}%")->orWhere('cheque_numero', 'like', "%{$f['buscar']}%")));
    }

    private function consultaMovimientos(Request $request)
    {
        $f = $request->validate([
            'cuenta_id' => 'nullable|integer', 'banco' => 'nullable|integer', 'fuente' => 'nullable|string|max:20', 'tipo' => 'nullable|in:cargo,abono',
            'conciliado' => 'nullable|in:si,no', 'desde' => 'nullable|date', 'hasta' => 'nullable|date', 'buscar' => 'nullable|string|max:100',
        ]);

        return TesoreriaMovimiento::query()
            ->when($f['cuenta_id'] ?? null, fn ($q, $v) => $q->where('cuenta_id', $v))
            ->when($f['banco'] ?? null, fn ($q, $v) => $q->whereHas('cuenta', fn ($c) => $c->where('id_cat_banco', $v)))
            ->when($f['fuente'] ?? null, fn ($q, $v) => $q->whereHas('cuenta', fn ($c) => $c->where('fuente', $v)))
            ->when($f['tipo'] ?? null, fn ($q, $v) => $q->where('tipo', $v))
            ->when(($f['conciliado'] ?? null) === 'si', fn ($q) => $q->whereNotNull('tesoreria_pago_id'))
            ->when(($f['conciliado'] ?? null) === 'no', fn ($q) => $q->whereNull('tesoreria_pago_id'))
            ->when($f['desde'] ?? null, fn ($q, $v) => $q->whereDate('fecha', '>=', $v))
            ->when($f['hasta'] ?? null, fn ($q, $v) => $q->whereDate('fecha', '<=', $v))
            ->when(filled($f['buscar'] ?? null), fn ($q) => $q->where(fn ($q) => $q->where('referencia', 'like', "%{$f['buscar']}%")
                ->orWhere('clave_rastreo', 'like', "%{$f['buscar']}%")->orWhere('concepto', 'like', "%{$f['buscar']}%")));
    }

    private function datosPosicion(Request $request): array
    {
        $corte = $request->validate(['corte' => 'nullable|date'])['corte'] ?? now('America/Mexico_City')->toDateString();
        $cuentas = TesoreriaCuenta::with('banco')->where('activo', true)->orderBy('nombre')->get();
        $movs = TesoreriaMovimiento::whereDate('fecha', '<=', $corte)->toBase()
            ->selectRaw("cuenta_id, SUM(CASE WHEN tipo = 'abono' THEN importe ELSE 0 END) AS abonos, SUM(CASE WHEN tipo = 'cargo' THEN importe ELSE 0 END) AS cargos")
            ->groupBy('cuenta_id')->get()->keyBy('cuenta_id');
        $transito = TesoreriaPago::whereIn('estado', ['pagado', 'entregado'])->whereDate('fecha', '<=', $corte)
            ->toBase()->selectRaw('cuenta_id, SUM(importe) AS importe, COUNT(*) AS pagos')->groupBy('cuenta_id')->get()->keyBy('cuenta_id');

        $filas = $cuentas->map(function (TesoreriaCuenta $c) use ($movs, $transito, $corte) {
            $inicial = ! $c->fecha_saldo_inicial || $c->fecha_saldo_inicial->toDateString() <= $corte ? $c->saldo_inicial : 0.0;
            $abonos = round((float) ($movs[$c->id]->abonos ?? 0), 2);
            $cargos = round((float) ($movs[$c->id]->cargos ?? 0), 2);
            $saldo = round($inicial + $abonos - $cargos, 2);
            $enTransito = round((float) ($transito[$c->id]->importe ?? 0), 2);

            return [
                'id' => $c->id, 'banco' => $c->banco?->descripcion ?? 'SIN BANCO', 'cuenta' => $c->etiqueta(), 'fuente' => $c->fuente ?? 'SIN FUENTE',
                'saldo_inicial' => round($inicial, 2), 'abonos' => $abonos, 'cargos' => $cargos, 'saldo_banco' => $saldo,
                'en_transito' => $enTransito, 'pagos_en_transito' => (int) ($transito[$c->id]->pagos ?? 0), 'disponible' => round($saldo - $enTransito, 2),
            ];
        })->values();
        $sumar = fn (Collection $g, string $por) => $g->groupBy($por)->map(fn ($x, $k) => ['clave' => $k, 'cuentas' => $x->count(),
            ...collect(['saldo_banco', 'en_transito', 'disponible'])->mapWithKeys(fn ($campo) => [$campo => round($x->sum($campo), 2)])])->values();

        return [
            'corte' => $corte,
            'cuentas' => $filas,
            'por_banco' => $sumar($filas, 'banco'),
            'por_fuente' => $sumar($filas, 'fuente'),
            'totales' => collect(['saldo_inicial', 'abonos', 'cargos', 'saldo_banco', 'en_transito', 'disponible'])->mapWithKeys(fn ($c) => [$c => round($filas->sum($c), 2)]),
        ];
    }

    private function descripcionFiltros(Request $request): string
    {
        $desde = $request->input('desde');
        $hasta = $request->input('hasta');
        $cuenta = $request->input('cuenta_id') ? TesoreriaCuenta::find($request->input('cuenta_id'))?->etiqueta() : null;

        return implode(' · ', array_filter([
            $desde || $hasta ? 'Del '.($desde ? date('d/m/Y', strtotime($desde)) : 'inicio').' al '.($hasta ? date('d/m/Y', strtotime($hasta)) : 'hoy') : 'Todas las fechas',
            $cuenta ?? 'Todas las cuentas',
            $request->input('fuente') ? 'Fuente '.$request->input('fuente') : null,
        ]));
    }

    private function excel($libro, string $nombre)
    {
        return response()->streamDownload(
            fn () => (new Xlsx($libro))->save('php://output'),
            $nombre.'_'.now('America/Mexico_City')->format('Ymd_Hi').'.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }
}
