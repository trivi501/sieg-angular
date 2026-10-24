<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CorridaSaldo;
use App\Models\EgresoRequisicion;
use App\Models\EgresoRequisicionBitacora;
use App\Models\EgresoRequisicionDetalle;
use App\Models\EgresoRequisicionEstado;
use App\Models\EgresoUnidadAdministrativa;
use App\Models\EjercicioFiscal;
use App\Models\ModificacionPresupuestal;
use App\Models\PresupuestoEgreso;
use App\Models\VehiculoMantenimiento;
use App\Services\ExcelGasto;
use App\Services\PresupuestoService;
use App\Services\SaldosMensuales;
use App\Services\TramiteClc;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Requisiciones de la captura hasta la suficiencia presupuestal (estados 1 a 7), bandeja de Recursos
 * Materiales y "Ejercicio y control del gasto" (SIEG: EgresoRequisicionController, respondiendo JSON en
 * vez de páginas Inertia). La orden de compra, la factura y la CLC se muestran aquí en solo lectura;
 * sus acciones van en su propio módulo.
 *
 * Diferencia con SIEG: todas las acciones sobre una requisición revisan que sea de la secretaría del
 * usuario (autorizarUnidad), no solo la consulta.
 */
class RequisicionController extends Controller
{
    private const IVA_FACTOR = 0.16;

    /** En Recursos Materiales, En Cotización, Comprometida, Orden de Compra, Con Factura, CLC Generada, Pagada. */
    private const ESTADOS_RECURSOS_MATERIALES = [5, 6, 7, 8, 9, 10, 11];

    /**
     * Ids de cat_egreso_unidad_administrativa visibles para el usuario: null (todas) si es Admin o Control Presupuestal;
     * si no, la de su secretaría o dirección (en todos los años); [] si no tiene asignada.
     */
    private function unidadesVisibles($user): ?array
    {
        return self::veTodas($user) ? null : EgresoUnidadAdministrativa::idsMismaClave($user->id_cat_egreso_unidad_administrativa);
    }

    /** Admin o Control Presupuestal (autoriza modificaciones): ve y trabaja las requisiciones de todas las áreas. */
    private static function veTodas($user): bool
    {
        return PresupuestoService::clavesUnidadVisibles($user) === null;
    }

    // ---------------------------------------------------------------- Consulta

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = EgresoRequisicion::with(['catEgresoRequisicionEstado', 'catEgresoUnidadAdministrativa:id_cat_egreso_unidad_administrativa,clave,nombre',
            'ordenCompra:id,id_tb_egreso_requisicion,folio_completo,cancelada_at,clc_folio_completo,clc_estado,clc_cancelada_at']);

        $unidades = $this->unidadesVisibles($user);
        if ($unidades !== null) {
            // El taller también ve las de mantenimiento vehicular, que van a nombre de otras secretarías.
            $query->where(fn ($q) => $q->whereIn('id_cat_egreso_unidad_administrativa', $unidades)
                ->when($user->can('vehiculos-mantenimiento'), fn ($w) => $w->orWhereIn('id_tb_egreso_requisicion',
                    VehiculoMantenimiento::whereNotNull('id_tb_egreso_requisicion')->select('id_tb_egreso_requisicion'))));
        }

        $this->filtrar($query, $request);
        if ($filtro = $request->input('año')) {
            $query->where('año', $filtro);
        }
        if ($filtro = $request->input('unidad')) {
            $query->whereHas('catEgresoUnidadAdministrativa', fn ($q) => $q->where(fn ($w) => $w->where('clave', 'like', "%{$filtro}%")->orWhere('nombre', 'like', "%{$filtro}%")));
        }

        return response()->json([
            ...$query->orderBy('id_tb_egreso_requisicion', 'desc')->paginate(15)->through(fn ($r) => $this->fila($r))->toArray(),
            'estados' => EgresoRequisicionEstado::orderBy('id_cat_egreso_requisicion_estado')->get(['id_cat_egreso_requisicion_estado as id', 'descripcion']),
            'veTodas' => self::veTodas($user),
        ]);
    }

    /** Bandeja de Recursos Materiales: recibe de todas las secretarías, sin filtro de unidad. */
    public function recursosMateriales(Request $request): JsonResponse
    {
        $query = EgresoRequisicion::with(['catEgresoRequisicionEstado', 'catEgresoUnidadAdministrativa:id_cat_egreso_unidad_administrativa,clave,nombre',
            'ordenCompra:id,id_tb_egreso_requisicion,folio_completo,cancelada_at,clc_folio_completo,clc_estado,clc_cancelada_at,xml_uuid'])
            ->whereIn('id_cat_egreso_requisicion_estado', self::ESTADOS_RECURSOS_MATERIALES);

        $this->filtrar($query, $request);

        return response()->json([
            ...$query->orderBy('id_tb_egreso_requisicion', 'desc')->paginate(15)->through(fn ($r) => $this->fila($r))->toArray(),
            'estados' => EgresoRequisicionEstado::whereIn('id_cat_egreso_requisicion_estado', self::ESTADOS_RECURSOS_MATERIALES)
                ->orderBy('id_cat_egreso_requisicion_estado')->get(['id_cat_egreso_requisicion_estado as id', 'descripcion']),
        ]);
    }

    private function filtrar($query, Request $request): void
    {
        if ($filtro = $request->input('folio_completo')) {
            $query->where('folio_completo', 'like', "%{$filtro}%");
        }
        if ($filtro = $request->input('concepto')) {
            $query->where('observaciones', 'like', "%{$filtro}%");
        }
        if ($filtro = $request->input('estado')) {
            $query->where('id_cat_egreso_requisicion_estado', $filtro);
        }
    }

    /** Renglón de las listas: lo que muestran Index y la bandeja de Recursos Materiales de SIEG. */
    private function fila(EgresoRequisicion $r): array
    {
        $oc = $r->ordenCompra;

        return [
            'id' => $r->id_tb_egreso_requisicion,
            'folio' => $r->folio_completo,
            'año' => $r->año,
            'registro' => $r->registro,
            'solicitado' => $r->solicitado ? substr((string) $r->solicitado, 0, 10) : null,
            'concepto' => $r->observaciones,
            'unidad' => $r->catEgresoUnidadAdministrativa ? trim($r->catEgresoUnidadAdministrativa->clave.' '.$r->catEgresoUnidadAdministrativa->nombre) : null,
            'estado_id' => (int) $r->id_cat_egreso_requisicion_estado,
            'estado' => $r->catEgresoRequisicionEstado?->descripcion,
            'total' => (float) $r->total,
            'suficiencia' => $r->suficiencia,
            'extemporanea' => (bool) $r->extemporanea,
            'extemporanea_pendiente' => $r->extemporaneaPendiente(),
            'orden' => $oc && ! $oc->cancelada_at ? $oc->folio_completo : null,
            'clc' => $oc && $oc->clc_folio_completo && ! $oc->clc_cancelada_at ? $oc->clc_folio_completo : null,
            'clc_estado' => $oc && $oc->clc_folio_completo && ! $oc->clc_cancelada_at ? TramiteClc::descripcion($oc->clc_estado) : null,
        ];
    }

    public function show(Request $request, EgresoRequisicion $requisicion): JsonResponse
    {
        $requisicion->load('tbEgresoRequisicionDetalles', 'catEgresoRequisicionEstado', 'catEgresoUnidadAdministrativa',
            'tbEgresoRequisicionBitacoras.catEgresoRequisicionEstadoNuevo', 'ordenCompra.proveedor:id,nombre,rfc',
            'extemporaneaAutorizador:id,name', 'canceladaPor:id,name');

        $this->autorizarUnidad($requisicion);
        $user = $request->user();
        $estado = (int) $requisicion->id_cat_egreso_requisicion_estado;

        // fuente/proyecto/partida en el detalle son códigos en texto libre; se resuelve su nombre
        // contra el presupuesto importado, acotado a la unidad de esta requisición.
        $unidadClave = $requisicion->catEgresoUnidadAdministrativa?->clave;
        $nombres = PresupuestoEgreso::when($unidadClave, fn ($q) => $q->where('CLAVE', $unidadClave))
            ->get(['CLAVE3', 'FUENTE_DE_FINANCIAMIENTO', 'CLAVE6', 'PROYECTO', 'PARTIDA', 'NOMBRE_PARTIDA'])
            ->reduce(function ($acc, $p) {
                $acc['fuentes'][trim((string) $p->CLAVE3)] ??= trim($p->FUENTE_DE_FINANCIAMIENTO ?? '');
                $acc['proyectos'][trim((string) $p->CLAVE6)] ??= trim($p->PROYECTO ?? '');
                $acc['partidas'][trim((string) $p->PARTIDA)] ??= trim($p->NOMBRE_PARTIDA ?? '');

                return $acc;
            }, ['fuentes' => [], 'proyectos' => [], 'partidas' => []]);

        $periodo = EjercicioFiscal::periodoDelMes($requisicion->registro ? Carbon::parse($requisicion->registro, 'America/Mexico_City') : now('America/Mexico_City'));
        $oc = $requisicion->ordenCompra;
        $m = VehiculoMantenimiento::with('vehiculo', 'departamento')->where('id_tb_egreso_requisicion', $requisicion->id_tb_egreso_requisicion)->first();
        $editable = $this->puedeEditarse($requisicion);
        // Mantenimiento vehicular: el taller hace los pasos de Recursos Materiales (VehiculoMantenimiento::delTaller).
        $taller = $m !== null && $user->can('vehiculos-mantenimiento');
        $sinSuficiencia = $requisicion->suficiencia !== true;
        // Sin factura: el registro de la orden solo lleva la CLC del apoyo (folio AP-…); no es una compra.
        $sf = (bool) $requisicion->sin_factura;
        // Mantenimiento con todos sus precios (capturados por el taller en el alta): no se envía a cotizar.
        $conPrecios = $requisicion->tbEgresoRequisicionDetalles->isNotEmpty()
            && $requisicion->tbEgresoRequisicionDetalles->every(fn ($d) => (float) $d->precio_unitario > 0);
        $directoASuficiencia = $sf || ($conPrecios && $m !== null);
        // Registro sin proveedor (apoyo sin factura AP-… o fondo revolvente FR-…): solo lleva la CLC, no es una compra.
        $compra = $sf || $requisicion->ordenCompra?->sin_factura ? null : $requisicion->ordenCompra;

        return response()->json([
            'requisicion' => [
                'id' => $requisicion->id_tb_egreso_requisicion,
                'folio' => $requisicion->folio_completo,
                'año' => $requisicion->año,
                'registro' => $requisicion->registro,
                'solicitado' => $requisicion->solicitado ? substr((string) $requisicion->solicitado, 0, 10) : null,
                'concepto' => $requisicion->observaciones,
                'departamento' => $requisicion->departamento,
                'id_unidad' => $requisicion->id_cat_egreso_unidad_administrativa,
                'unidad' => $requisicion->catEgresoUnidadAdministrativa ? trim($requisicion->catEgresoUnidadAdministrativa->clave.' — '.$requisicion->catEgresoUnidadAdministrativa->nombre) : null,
                'estado_id' => $estado,
                'estado' => $requisicion->catEgresoRequisicionEstado?->descripcion,
                'sub_total' => (float) $requisicion->sub_total,
                'iva' => (float) $requisicion->iva,
                'total' => (float) $requisicion->total,
                'suficiencia' => $requisicion->suficiencia,
                'suficiencia_fecha' => $requisicion->suficiencia_fecha?->toIso8601String(),
                // Sin factura (apoyo a un beneficiario).
                'sin_factura' => (bool) $requisicion->sin_factura,
                'beneficiario' => $requisicion->beneficiario,
                'beneficiario_referencia' => $requisicion->beneficiario_referencia,
                'beneficiario_fecha' => $requisicion->beneficiario_fecha?->format('Y-m-d'),
                'extemporanea' => (bool) $requisicion->extemporanea,
                'extemporanea_pendiente' => $requisicion->extemporaneaPendiente(),
                'extemporanea_autorizada_at' => $requisicion->extemporanea_autorizada_at?->toIso8601String(),
                'extemporanea_autorizador' => $requisicion->extemporaneaAutorizador?->name,
                'cancelada_at' => $requisicion->cancelada_at?->toIso8601String(),
                'cancelada_por' => $requisicion->canceladaPor?->name,
                'motivo_cancelacion' => $requisicion->motivo_cancelacion,
                'detalles' => $requisicion->tbEgresoRequisicionDetalles->sortBy('id_tb_egreso_requisicion_detalle')->values()->map(fn ($d) => [
                    'id' => $d->id_tb_egreso_requisicion_detalle,
                    'cantidad' => (float) $d->cantidad,
                    'descripcion' => $d->descripcion,
                    'fuente' => trim((string) $d->fuente),
                    'proyecto' => trim((string) $d->proyecto),
                    'partida' => trim((string) $d->partida),
                    'precio_unitario' => $d->precio_unitario !== null ? (float) $d->precio_unitario : null,
                    'lleva_iva' => (float) $d->iva_factor > 0,
                    'sub_total' => $d->sub_total !== null ? (float) $d->sub_total : null,
                    'iva' => $d->iva !== null ? (float) $d->iva : null,
                    'total' => $d->total !== null ? (float) $d->total : null,
                ]),
                'bitacora' => $requisicion->tbEgresoRequisicionBitacoras->sortByDesc('id_tb_egreso_requisicion_bitacora')->values()->map(fn ($b) => [
                    'id' => $b->id_tb_egreso_requisicion_bitacora,
                    'fecha' => $b->registro,
                    'estado' => $b->catEgresoRequisicionEstadoNuevo?->descripcion,
                    'observaciones' => $b->observaciones,
                ]),
            ],
            'nombres' => $nombres,
            'validacion' => app(PresupuestoService::class)->validarRequisicion($requisicion),
            'periodoCaptura' => [$periodo['inicio']->format('d/m/Y'), $periodo['fin']->format('d/m/Y')],
            'orden' => $oc ? [
                'id' => $oc->id,
                'folio' => $oc->folio_completo,
                'fecha' => $oc->fecha?->format('Y-m-d'),
                'importe' => (float) $oc->importe,
                'observaciones' => $oc->observaciones,
                'proveedor_id' => $oc->proveedor_id,
                'proveedor' => $oc->aFavorDe(),
                'rfc' => $oc->proveedor?->rfc,
                'sin_factura' => (bool) $oc->sin_factura,
                'tramite' => $oc->tramite_servicio_id ? ['id' => $oc->tramite_servicio_id, 'tipo' => $oc->tramiteServicio?->tipo, 'folio' => $oc->tramiteServicio?->folio_completo, 'facturas' => $oc->tramiteServicio?->facturas()->count()] : null,
                'factura' => $oc->xml_uuid ? (trim(($oc->xml_serie ?? '').' '.($oc->xml_folio ?? '')) ?: $oc->xml_uuid) : null,
                'xml' => $oc->xml_uuid ? [
                    'uuid' => $oc->xml_uuid,
                    'emisor' => $oc->xml_nombre_emisor,
                    'rfc_emisor' => $oc->xml_rfc_emisor,
                    'serie_folio' => trim(($oc->xml_serie ?? '').' '.($oc->xml_folio ?? '')) ?: null,
                    'fecha' => $oc->xml_fecha?->toIso8601String(),
                    'total' => (float) $oc->xml_total,
                    'isr' => (float) $oc->xml_isr,
                    'cargado_at' => $oc->xml_cargado_at?->toIso8601String(),
                    'sat_estado' => $oc->sat_estado,
                    'sat_estatus_cancelacion' => $oc->sat_estatus_cancelacion,
                    'sat_efos' => (bool) $oc->sat_efos,
                    'sat_verificado_at' => $oc->sat_verificado_at?->toIso8601String(),
                ] : null,
                'xml_uuid_cancelado' => $oc->xml_uuid_cancelado,
                'refacturas' => $oc->refacturas()->with('usuario:id,name')->reorder('id', 'desc')->get()->map(fn ($r) => [
                    'fecha' => $r->created_at?->toIso8601String(),
                    'usuario' => $r->usuario?->name,
                    'factura' => trim(($r->xml_folio_anterior ?? '').' '.($r->xml_uuid_anterior ?? '')),
                    'clc_anterior' => $r->clc_folio_anterior,
                    'clc_nueva' => $r->clc_nueva,
                    'motivo' => $r->motivo,
                ]),
                'clc' => $oc->clc_folio_completo,
                'clc_fecha' => $oc->clc_fecha?->format('Y-m-d'),
                'clc_estado' => $oc->clc_folio_completo ? TramiteClc::descripcion($oc->clc_estado) : null,
                'clc_cancelada' => $oc->clc_cancelada_at !== null,
                'clc_motivo_cancelacion' => $oc->clc_motivo_cancelacion,
                'neto' => $oc->netoAPagar(),
                'pago_fecha' => $oc->clc_cancelada_at ? null : $oc->pago_fecha?->format('Y-m-d'),
                'pago_forma' => $oc->pago_forma ? (\App\Models\OrdenCompra::FORMAS_PAGO[$oc->pago_forma] ?? $oc->pago_forma) : null,
                'pago_referencia' => trim(($oc->transferencia_numero ?? '').' '.($oc->pago_referencia ?? '')) ?: null,
                // "Trámite de la CLC" en la requisición: cada paso con quién y cuándo.
                'movimientos' => $oc->movimientosClc()->with('usuario:id,name', 'relacion:id,folio_completo')->reorder('id', 'desc')->get()->map(fn ($m) => [
                    'fecha' => $m->created_at?->toIso8601String(),
                    'accion' => TramiteClc::ACCIONES[$m->accion]['etiqueta'] ?? ucfirst($m->accion),
                    'estado' => TramiteClc::descripcion($m->estado_nuevo),
                    'usuario' => $m->usuario?->name,
                    'relacion' => $m->relacion?->folio_completo,
                    'observaciones' => $m->observaciones,
                ]),
                'almacen_asignada_at' => $oc->almacen_asignada_at?->toIso8601String(),
                'cancelada' => $oc->cancelada_at !== null,
                'cancelada_at' => $oc->cancelada_at?->toIso8601String(),
                'motivo_cancelacion' => $oc->motivo_cancelacion,
            ] : null,
            'mantenimiento' => $m ? ['folio' => $m->folio_completo, 'vehiculo' => $m->textoVehiculo(), 'area' => $m->departamento?->nombre, 'estado' => VehiculoMantenimiento::ESTADOS[$m->estado] ?? $m->estado] : null,
            'modificaciones' => ModificacionPresupuestal::where('id_tb_egreso_requisicion', $requisicion->id_tb_egreso_requisicion)
                ->orderByDesc('id')->get(['id', 'folio_completo', 'tipo', 'estado', 'importe']),
            // Las mismas condiciones con que SIEG muestra cada botón (Show.tsx); el servidor las vuelve a revisar.
            'puede' => [
                'editar' => in_array($estado, [1, 4], true) && $editable && ($user->can('requisiciones-edit') || $taller),
                'enviar' => in_array($estado, [1, 4], true) && $editable && $user->can('requisiciones-create'),
                'validar' => $estado === 2 && $user->hasRole(['Super Admin', 'Admin']) && $user->can('requisiciones-edit'),
                'imprimir' => $estado === 3 || ($estado >= 5 && $estado !== EgresoRequisicion::CANCELADA),
                // Sin factura (apoyo): no va a Recursos Materiales; de aprobada pasa a la suficiencia.
                'enviarRecursos' => $estado === 3 && ! $directoASuficiencia && ($user->can('requisiciones-edit') || $taller),
                'recibir' => $estado === 5 && ($user->can('requisiciones-cotizar') || $taller),
                'devolver' => in_array($estado, [5, 6], true) && $sinSuficiencia && $user->can('requisiciones-devolver'),
                'cotizar' => $estado === 6 && $editable && ($user->can('requisiciones-cotizar') || $taller),
                'suficiencia' => ($estado === 6 || ($directoASuficiencia && $estado === 3)) && $sinSuficiencia && $user->can('requisiciones-suficiencia'),
                'extemporanea' => $requisicion->extemporaneaPendiente() && $user->can('requisiciones-extemporaneas'),
                'cambiarFecha' => $this->puedeCambiarFecha($requisicion),
                'cancelar' => $this->puedeCancelar($requisicion),
                'eliminar' => $user->can('requisiciones-delete') && in_array($estado, [1, 4], true),
                'modificacion' => $requisicion->suficiencia === false && $user->can('modificaciones-create'),
                // Orden de compra (SIEG: Show.tsx y OrdenCompraController).
                'generarOrden' => ! $sf && $estado === EgresoRequisicion::COMPROMETIDA && ! $oc && ($user->can('ordenes-compra-create') || $taller),
                'imprimirOrden' => $compra && ! $compra->cancelada_at,
                'cambiarProveedor' => $compra && ! $compra->tramite_servicio_id && ! $compra->cancelada_at && ! $compra->xml_uuid && ($user->can('ordenes-compra-create') || $taller),
                'cambiarFechaOrden' => $compra && ! $compra->cancelada_at && $user->can('ordenes-compra-fecha'),
                // Trámite de servicios: la orden ya trae sus facturas (en el trámite); no se sube XML aparte.
                'cargarXml' => $compra && ! $compra->tramite_servicio_id && ! $compra->cancelada_at && ! $compra->xml_uuid && ($user->can('ordenes-compra-create') || $taller),
                'descargarXml' => $oc && $oc->xml_path !== null,
                'verificarSat' => $oc && $oc->xml_uuid && $user->can('ordenes-compra-verificar-sat'),
                'refacturar' => $oc && $oc->xml_uuid && ! $oc->cancelada_at && ! ($oc->pago_fecha && ! $oc->clc_cancelada_at) && $user->can('ordenes-compra-refacturar'),
                'cancelarOrden' => $compra && ! $compra->cancelada_at && ! $compra->clcActiva() && $user->can('ordenes-compra-cancelar'),
                'altaProveedor' => ! $sf && $user->can('proveedores-create'),
                // Apoyo sin factura: la CLC se genera desde la requisición comprometida (sin orden ni factura).
                'generarClcSinFactura' => $sf && $estado === EgresoRequisicion::COMPROMETIDA && ! $oc && $user->can('requisiciones-clc'),
                // CLC de la orden.
                'generarClc' => $oc && $oc->xml_uuid && ! $oc->clc_folio_completo && ! $oc->cancelada_at && $user->can('requisiciones-clc'),
                'imprimirClc' => $oc && $oc->clc_folio_completo && ! $oc->clc_cancelada_at,
                'cancelarClc' => $oc && TramiteClc::impedimentoParaCancelar($oc, $user) === null,
                // Almacén: ya no se asigna desde la requisición (se quitó el botón); se sigue viendo si ya estaba asignada.
                'verAlmacen' => $oc && $oc->almacen_asignada_at && $user->can('almacen-index'),
            ],
            // Si la CLC está vigente pero ya no le toca cancelarla, por qué (se muestra junto al botón).
            'motivoNoCancelarClc' => $oc?->clcActiva() && $user->can('clc-cancelar') ? TramiteClc::impedimentoParaCancelar($oc, $user) : null,
        ]);
    }

    /** Fuera de Recursos Materiales, solo se ve y se trabaja lo de las unidades de la propia secretaría. */
    private function autorizarUnidad(EgresoRequisicion $requisicion): void
    {
        $user = auth()->user();
        if ($user->can('ordenes-compra-create') || $user->can('requisiciones-suficiencia') || $user->can('requisiciones-cotizar')) {
            return;
        }
        if ($user->can('vehiculos-mantenimiento') && VehiculoMantenimiento::where('id_tb_egreso_requisicion', $requisicion->id_tb_egreso_requisicion)->exists()) {
            return;
        }

        $idsVisibles = $this->unidadesVisibles($user);
        if ($idsVisibles !== null && ! in_array((int) $requisicion->id_cat_egreso_unidad_administrativa, $idsVisibles, true)) {
            abort(403, 'Esta requisición no pertenece a tu secretaría.');
        }
    }

    // ---------------------------------------------------------------- Captura

    /** Catálogos del formulario de alta: unidades visibles, líneas con disponible y periodo de captura. */
    public function opciones(Request $request): JsonResponse
    {
        $user = $request->user();
        $mantenimiento = $this->mantenimientoParaRequisicion($request->input('mantenimiento'));

        $unidadesQuery = EgresoUnidadAdministrativa::where('activo', 1);
        $idsVisibles = $this->unidadesVisibles($user);
        if ($mantenimiento) {
            // Mantenimiento vehicular: la requisición va a nombre de la secretaría de resguardo del vehículo.
            $unidadesQuery->whereKey($mantenimiento->id_cat_egreso_unidad_administrativa);
        } elseif ($idsVisibles !== null) {
            $unidadesQuery->whereIn('id_cat_egreso_unidad_administrativa', $idsVisibles);
        }
        $unidades = $unidadesQuery->orderBy('nombre')->get(['id_cat_egreso_unidad_administrativa', 'clave', 'nombre']);

        $hoy = now('America/Mexico_City');
        $periodo = EjercicioFiscal::periodoDelMes($hoy);

        return response()->json([
            'unidades' => $this->unidadesParaFront($unidades),
            // Mantenimiento vehicular: solo sus partidas.
            'lineas' => $this->lineasParaCaptura($unidades, $mantenimiento ? VehiculoMantenimiento::PARTIDAS : null),
            'unidadDefault' => ! self::veTodas($user) || $mantenimiento ? $unidades->first()?->id_cat_egreso_unidad_administrativa : null,
            'mantenimiento' => $mantenimiento ? [
                'id' => $mantenimiento->id,
                'folio' => $mantenimiento->folio_completo,
                'vehiculo' => $mantenimiento->textoVehiculo(),
                'area' => $mantenimiento->departamento?->nombre,
                'tipo' => VehiculoMantenimiento::TIPOS[$mantenimiento->tipo] ?? $mantenimiento->tipo,
                'descripcion' => $mantenimiento->descripcion,
            ] : null,
            'periodoCaptura' => [
                'inicio' => $periodo['inicio']->format('d/m/Y'),
                'fin' => $periodo['fin']->format('d/m/Y'),
                'abierto' => EjercicioFiscal::enPeriodoDeCaptura($hoy),
            ],
        ]);
    }

    /** Datos para editar: la captura (pendiente o rechazada) o la cotización (precios e IVA). */
    public function edicion(Request $request, EgresoRequisicion $requisicion): JsonResponse
    {
        $this->autorizarUnidad($requisicion);
        abort_unless($this->puedeEditarse($requisicion), 422, 'Esta requisición ya no se puede modificar en su estado actual.');

        $esCotizacion = (int) $requisicion->id_cat_egreso_requisicion_estado === 6;
        // En las de mantenimiento vehicular, el taller captura y cotiza (VehiculoMantenimiento::delTaller).
        $taller = VehiculoMantenimiento::delTaller($request->user(), $requisicion->id_tb_egreso_requisicion);
        abort_if($esCotizacion && ! $taller && ! $request->user()->can('requisiciones-cotizar'), 403, 'La cotización la captura Recursos Materiales.');
        abort_if(! $esCotizacion && ! $taller && ! $request->user()->can('requisiciones-edit'), 403);

        $unidadesQuery = EgresoUnidadAdministrativa::where('activo', 1);
        $idsVisibles = $this->unidadesVisibles($request->user());
        if ($esCotizacion) {
            // En cotización la unidad ya no cambia; basta con sus líneas para mostrar los nombres.
            $unidadesQuery->whereKey($requisicion->id_cat_egreso_unidad_administrativa);
        } elseif ($idsVisibles !== null) {
            $unidadesQuery->whereIn('id_cat_egreso_unidad_administrativa', $idsVisibles);
        }
        $unidades = $unidadesQuery->orderBy('nombre')->get(['id_cat_egreso_unidad_administrativa', 'clave', 'nombre']);

        $requisicion->load('tbEgresoRequisicionDetalles');
        // Al editar una requisición de mantenimiento vehicular solo se ofrecen sus partidas (al cotizar no se eligen).
        $deMantenimiento = ! $esCotizacion && VehiculoMantenimiento::where('id_tb_egreso_requisicion', $requisicion->id_tb_egreso_requisicion)->exists();

        return response()->json([
            'cotizacion' => $esCotizacion,
            'unidades' => $this->unidadesParaFront($unidades),
            'lineas' => $this->lineasParaCaptura($unidades, $deMantenimiento ? VehiculoMantenimiento::PARTIDAS : null),
            'requisicion' => [
                'id' => $requisicion->id_tb_egreso_requisicion,
                'folio' => $requisicion->folio_completo,
                'id_cat_egreso_unidad_administrativa' => $requisicion->id_cat_egreso_unidad_administrativa,
                'concepto' => $requisicion->observaciones,
                'fecha_tramite' => $requisicion->solicitado ? substr((string) $requisicion->solicitado, 0, 10) : null,
                'filas' => $requisicion->tbEgresoRequisicionDetalles->sortBy('id_tb_egreso_requisicion_detalle')->values()->map(fn ($d) => [
                    'fuente' => trim((string) $d->fuente),
                    'proyecto' => trim((string) $d->proyecto),
                    'partida' => trim((string) $d->partida),
                    'cantidad' => (float) $d->cantidad,
                    'descripcion' => $d->descripcion,
                    'precio_unitario' => $d->precio_unitario !== null ? (float) $d->precio_unitario : null,
                    'lleva_iva' => (float) $d->iva_factor > 0,
                ]),
                'sin_factura' => (bool) $requisicion->sin_factura,
                'beneficiario' => $requisicion->beneficiario,
                'beneficiario_referencia' => $requisicion->beneficiario_referencia,
                'beneficiario_fecha' => $requisicion->beneficiario_fecha?->format('Y-m-d'),
                'incluye_iva' => (float) $requisicion->iva > 0,
                'de_mantenimiento' => VehiculoMantenimiento::where('id_tb_egreso_requisicion', $requisicion->id_tb_egreso_requisicion)->exists(),
            ],
        ]);
    }

    private function unidadesParaFront(Collection $unidades): array
    {
        return $unidades->map(fn ($u) => ['id' => $u->id_cat_egreso_unidad_administrativa, 'clave' => trim((string) $u->clave), 'nombre' => $u->nombre])->values()->all();
    }

    /**
     * Líneas de presupuesto (fuente + proyecto + partida) de las unidades dadas, con su disponible,
     * para el selector de cada concepto.
     */
    private function lineasParaCaptura($unidades, ?array $soloPartidas = null): array
    {
        return app(PresupuestoService::class)
            ->lineas($unidades->pluck('clave')->map(fn ($c) => trim((string) $c))->all())
            ->when($soloPartidas !== null, fn ($lineas) => $lineas->filter(fn ($l) => in_array((string) $l['partida'], $soloPartidas, true)))
            ->map(fn ($l) => collect($l)->only([
                'clave', 'unidad_clave', 'fuente', 'nombre_fuente', 'proyecto', 'nombre_proyecto', 'partida', 'nombre_partida', 'disponible',
            ]))
            ->values()
            ->all();
    }

    /** Estados en los que la requisición todavía se puede modificar. */
    private function puedeEditarse(EgresoRequisicion $requisicion): bool
    {
        return match ((int) $requisicion->id_cat_egreso_requisicion_estado) {
            1, 4 => true,                                     // pendiente / no válida (rechazada)
            6 => $requisicion->suficiencia !== true,          // cotización, hasta que tenga suficiencia
            default => false,                                 // en revisión, validada, en Recursos Materiales
        };
    }

    /**
     * Mantenimiento vehicular del que se hace la requisición: en el taller, sin requisición y solo para
     * quien recibe vehículos (vehiculos-mantenimiento) o Admin.
     */
    private function mantenimientoParaRequisicion($id): ?VehiculoMantenimiento
    {
        $user = auth()->user();
        if (! $id || ! ($user->can('vehiculos-mantenimiento') || $user->hasRole(['Super Admin', 'Admin']))) {
            return null;
        }

        // Sin requisición, o con una cancelada: el taller puede hacer una nueva que la sustituye.
        return VehiculoMantenimiento::with('vehiculo', 'departamento', 'requisicion:id_tb_egreso_requisicion,folio_completo,id_cat_egreso_requisicion_estado')
            ->whereKey($id)->where('estado', 'en_taller')
            ->where(fn ($q) => $q->whereNull('id_tb_egreso_requisicion')
                ->orWhereHas('requisicion', fn ($r) => $r->where('id_cat_egreso_requisicion_estado', EgresoRequisicion::CANCELADA)))
            ->first();
    }

    /**
     * Reglas de la captura de una requisición (alta y edición): el concepto es obligatorio y debe
     * llevar al menos un renglón con su descripción.
     *
     * @return array{0: array, 1: array, 2: array} reglas, mensajes y nombres de atributos para validate()
     */
    private function reglasCaptura(): array
    {
        return [
            [
                'id_cat_egreso_unidad_administrativa' => 'required|integer',
                'concepto' => 'required|string|max:3000',
                'fecha_tramite' => 'required|date',
                'incluye_iva' => 'boolean',
                'filas' => 'required|array|min:1',
                'filas.*.fuente' => 'nullable|string|max:200',
                'filas.*.proyecto' => 'nullable|string|max:200',
                'filas.*.partida' => 'nullable|string|max:200',
                'filas.*.cantidad' => 'nullable|numeric',
                'filas.*.descripcion' => 'required|string|max:3000',
                // Sin factura (apoyo): beneficiario, documento de referencia y el monto de cada renglón.
                'sin_factura' => 'boolean',
                'beneficiario' => [Rule::requiredIf(fn () => request()->boolean('sin_factura')), 'nullable', 'string', 'max:1500'],
                'beneficiario_referencia' => [Rule::requiredIf(fn () => request()->boolean('sin_factura')), 'nullable', 'string', 'max:100'],
                'beneficiario_fecha' => [Rule::requiredIf(fn () => request()->boolean('sin_factura')), 'nullable', 'date'],
                'filas.*.monto' => [Rule::requiredIf(fn () => request()->boolean('sin_factura')), 'nullable', 'numeric', 'min:0.01'],
                'filas.*.precio_unitario' => 'nullable|numeric|min:0|decimal:0,4',
                'filas.*.lleva_iva' => 'boolean',
            ],
            [
                'concepto.required' => 'Captura el concepto de la requisición.',
                'filas.required' => 'Agrega al menos un renglón.',
                'filas.min' => 'Agrega al menos un renglón.',
                'filas.*.descripcion.required' => 'Describe el artículo, material o servicio del renglón.',
                'beneficiario.required' => 'Captura el beneficiario del apoyo.',
                'beneficiario_referencia.required' => 'Captura el folio del documento de referencia.',
                'beneficiario_fecha.required' => 'Captura la fecha del documento de referencia.',
                'filas.*.monto.required' => 'Captura el monto del apoyo.',
                'filas.*.monto.min' => 'El monto del apoyo debe ser mayor a cero.',
            ],
            ['id_cat_egreso_unidad_administrativa' => 'unidad administrativa', 'fecha_tramite' => 'fecha de trámite',
                'beneficiario_referencia' => 'folio de referencia', 'beneficiario_fecha' => 'fecha de referencia'],
        ];
    }

    /**
     * Calcula cantidad/precio/iva/subtotal/total de cada fila; el total de la requisición
     * se deriva siempre de esta suma, nunca se captura a mano, para que nunca puedan quedar distintos.
     */
    /**
     * Sin factura (apoyo): no pasa por cotización, así que el monto de cada renglón se captura desde el
     * alta como su precio (cantidad 1). Con factura, las filas quedan como vienen (el precio lo pone la cotización).
     */
    private function filasDeCaptura(array $validated, bool $conPrecios = false): array
    {
        $filas = $validated['filas'] ?? [];
        if ($conPrecios) {
            // Mantenimiento vehicular: el taller captura precio unitario e IVA de cada concepto desde el alta.
            return array_map(fn ($f) => ['precio_unitario' => (float) $f['precio_unitario'], 'lleva_iva' => (bool) ($f['lleva_iva'] ?? false)]
                + array_diff_key($f, ['monto' => true]), $filas);
        }
        if (empty($validated['sin_factura'])) {
            return array_map(fn ($f) => array_diff_key($f, ['monto' => true, 'precio_unitario' => true, 'lleva_iva' => true]), $filas);
        }

        return array_map(fn ($f) => ['cantidad' => 1, 'precio_unitario' => (float) $f['monto']] + array_diff_key($f, ['monto' => true, 'cantidad' => true]), $filas);
    }

    /** Datos del apoyo que se guardan en la requisición (vacíos si es con factura). */
    private function datosSinFactura(array $validated): array
    {
        $sinFactura = (bool) ($validated['sin_factura'] ?? false);

        return [
            'sin_factura' => $sinFactura,
            'beneficiario' => $sinFactura ? mb_strtoupper(trim($validated['beneficiario'])) : null,
            'beneficiario_referencia' => $sinFactura ? mb_strtoupper(trim($validated['beneficiario_referencia'])) : null,
            'beneficiario_fecha' => $sinFactura ? $validated['beneficiario_fecha'] : null,
        ];
    }

    private function calcularFilas(array $filas, bool $incluyeIva): array
    {
        $ivaFactor = $incluyeIva ? self::IVA_FACTOR : 0;
        $calculadas = [];
        $totales = ['sub_total' => 0.0, 'iva' => 0.0, 'total' => 0.0];

        foreach ($filas as $fila) {
            $cantidad = (float) ($fila['cantidad'] ?? 1);
            $precio = isset($fila['precio_unitario']) && $fila['precio_unitario'] !== '' ? (float) $fila['precio_unitario'] : null;
            $subTotal = $precio !== null ? round($precio * $cantidad, 4) : null;
            $factor = array_key_exists('lleva_iva', $fila) ? ($fila['lleva_iva'] ? self::IVA_FACTOR : 0) : $ivaFactor;
            $iva = $subTotal !== null ? round($subTotal * $factor, 4) : null;
            $total = $subTotal !== null ? round($subTotal + $iva, 4) : null;

            $calculadas[] = $fila + [
                'cantidad' => $cantidad,
                'precio_unitario' => $precio,
                'sub_total' => $subTotal,
                'iva' => $iva,
                'iva_factor' => $factor,
                'total' => $total,
            ];

            $totales['sub_total'] += $subTotal ?? 0;
            $totales['iva'] += $iva ?? 0;
            $totales['total'] += $total ?? 0;
        }

        return [$calculadas, $totales];
    }

    /**
     * Cada concepto capturado debe ir a una línea (fuente + proyecto + partida) que exista
     * en el presupuesto de la unidad administrativa elegida.
     */
    /** El taller captura el precio de cada concepto desde el alta (no pasa por cotización). */
    private function validarPreciosMantenimiento(array $filas): void
    {
        $errores = [];
        foreach ($filas as $i => $fila) {
            if (! isset($fila['precio_unitario']) || (float) $fila['precio_unitario'] <= 0) {
                $errores["filas.{$i}.precio_unitario"] = 'Captura el precio unitario del concepto.';
            }
        }
        if ($errores) {
            throw ValidationException::withMessages($errores);
        }
    }

    /** La requisición de un mantenimiento vehicular solo admite las partidas de VehiculoMantenimiento::PARTIDAS. */
    private function validarPartidasMantenimiento(array $filas): void
    {
        $errores = [];
        foreach ($filas as $i => $fila) {
            if (! in_array((string) ($fila['partida'] ?? ''), VehiculoMantenimiento::PARTIDAS, true)) {
                $errores["filas.{$i}.partida"] = 'En un mantenimiento vehicular solo se permiten las partidas '.implode(', ', VehiculoMantenimiento::PARTIDAS).'.';
            }
        }
        if ($errores) {
            throw ValidationException::withMessages($errores);
        }
    }

    private function validarLineasPresupuesto(array $filas, ?EgresoUnidadAdministrativa $unidad): void
    {
        $existentes = PresupuestoEgreso::where('CLAVE', $unidad?->clave)
            ->get(['CLAVE', 'CLAVE3', 'CLAVE6', 'PARTIDA'])
            ->map(fn ($p) => PresupuestoService::clave($p->CLAVE, $p->CLAVE3, $p->CLAVE6, $p->PARTIDA))
            ->flip();

        $errores = [];
        foreach ($filas as $i => $fila) {
            if (empty($fila['descripcion'])) {
                continue;
            }
            $clave = PresupuestoService::clave($unidad?->clave, $fila['fuente'] ?? null, $fila['proyecto'] ?? null, $fila['partida'] ?? null);
            if (! isset($existentes[$clave])) {
                $errores["filas.{$i}.partida"] = 'Selecciona una fuente / proyecto / partida del presupuesto de la unidad (concepto '.($i + 1).').';
            }
        }

        if ($errores) {
            throw ValidationException::withMessages($errores);
        }
    }

    /** Guarda los conceptos calculados de la requisición (los ids de la tabla anterior se calculan). */
    private function guardarDetalles(EgresoRequisicion $requisicion, array $filasCalculadas): void
    {
        $detalleId = (EgresoRequisicionDetalle::max('id_tb_egreso_requisicion_detalle') ?? 0) + 1;
        foreach ($filasCalculadas as $fila) {
            if (empty($fila['descripcion'])) {
                continue;
            }
            EgresoRequisicionDetalle::create([
                'id_tb_egreso_requisicion_detalle' => $detalleId++,
                'id_tb_egreso_requisicion' => $requisicion->id_tb_egreso_requisicion,
                'id_cat_egreso_producto' => 1,
                'cantidad' => $fila['cantidad'],
                'descripcion' => $fila['descripcion'] ?? '',
                'fuente' => $fila['fuente'] ?? null,
                'proyecto' => $fila['proyecto'] ?? null,
                'partida' => $fila['partida'] ?? null,
                'articulo' => $fila['descripcion'] ?? null,
                'precio_unitario' => $fila['precio_unitario'],
                'sub_total' => $fila['sub_total'],
                'iva' => $fila['iva'],
                'iva_factor' => $fila['iva_factor'],
                'descuento' => 0,
                'total' => $fila['total'],
                'id_tb_egreso_presupuesto_detalle' => null,
                'id_tb_egreso_presupuesto' => null,
            ]);
        }
    }

    /** Renglón de la bitácora de la requisición (tabla del sistema anterior: el id se calcula). */
    private function bitacora(EgresoRequisicion $requisicion, int $anterior, int $nuevo, string $observaciones): void
    {
        EgresoRequisicionBitacora::create([
            'id_tb_egreso_requisicion_bitacora' => (EgresoRequisicionBitacora::max('id_tb_egreso_requisicion_bitacora') ?? 0) + 1,
            'id_tb_egreso_requisicion' => $requisicion->id_tb_egreso_requisicion,
            'id_cat_egreso_requisicion_estado_anterior' => $anterior,
            'id_cat_egreso_requisicion_estado_nuevo' => $nuevo,
            'id_tb_usuarios' => 1,
            'observaciones' => $observaciones,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate(...$this->reglasCaptura());

        $user = $request->user();
        $mantenimiento = $this->mantenimientoParaRequisicion($request->input('mantenimiento_id'));
        if ($request->filled('mantenimiento_id') && ! $mantenimiento) {
            throw ValidationException::withMessages(['concepto' => 'Ese mantenimiento ya tiene una requisición vigente (no cancelada) o ya se entregó el vehículo.']);
        }
        if ($mantenimiento) {
            if ((int) $validated['id_cat_egreso_unidad_administrativa'] !== (int) $mantenimiento->id_cat_egreso_unidad_administrativa) {
                abort(403, 'La requisición del mantenimiento va a nombre de la secretaría de resguardo del vehículo.');
            }
        } else {
            $idsVisibles = $this->unidadesVisibles($user);
            if ($idsVisibles !== null && ! in_array((int) $validated['id_cat_egreso_unidad_administrativa'], $idsVisibles, true)) {
                abort(403, 'Esa unidad administrativa no pertenece a tu secretaría.');
            }
        }

        // El folio se numera por el año en curso: si ese ejercicio está cerrado no se admiten requisiciones.
        if (EjercicioFiscal::estaCerrado((int) date('Y'))) {
            throw ValidationException::withMessages(['fecha_tramite' => 'El ejercicio fiscal '.date('Y').' está cerrado; ya no admite requisiciones nuevas.']);
        }

        $unidad = EgresoUnidadAdministrativa::find($validated['id_cat_egreso_unidad_administrativa']);
        $this->validarLineasPresupuesto($validated['filas'] ?? [], $unidad);
        if ($mantenimiento) {
            $this->validarPartidasMantenimiento($validated['filas'] ?? []);
            $this->validarPreciosMantenimiento($validated['filas'] ?? []);
            if (! empty($validated['sin_factura'])) {
                throw ValidationException::withMessages(['sin_factura' => 'Un mantenimiento vehicular lleva orden de compra y factura: no puede ser sin factura.']);
            }
        }
        $prefijo = $unidad?->prefijo ?: 'REQ';

        [$filasCalculadas, $totales] = $this->calcularFilas($this->filasDeCaptura($validated, $mantenimiento !== null), (bool) ($validated['incluye_iva'] ?? false));

        // Fuera del periodo ordinario de captura queda extemporánea (no avanza sin autorización).
        $extemporanea = ! EjercicioFiscal::enPeriodoDeCaptura(now('America/Mexico_City'));

        $requisicion = DB::transaction(function () use ($user, $validated, $prefijo, $filasCalculadas, $totales, $extemporanea, $mantenimiento) {
            // Folio e id dentro de la transacción y con bloqueo para que dos capturas simultáneas no se repitan.
            $nuevoFolio = (EgresoRequisicion::where('año', date('Y'))->lockForUpdate()->max('folio') ?? 0) + 1;
            $newId = (EgresoRequisicion::lockForUpdate()->max('id_tb_egreso_requisicion') ?? 0) + 1;

            $requisicion = EgresoRequisicion::create([
                'id_tb_egreso_requisicion' => $newId,
                'id_cat_egreso_requisicion_estado' => 1, // pendiente
                'id_cat_egreso_solicitud_tipo' => 1,
                'id_tb_egreso_presupuesto' => 1,
                'id_cat_egreso_unidad_administrativa' => $validated['id_cat_egreso_unidad_administrativa'],
                'id_cat_area_x_nombre_y_puesto_Solicita' => 1,
                'id_cat_area_x_nombre_y_puesto_Autoriza' => 1,
                'id_tb_usuarios' => 1,
                'id_user_laravel' => $user->id,
                'id_tb_egreso_presupuesto_detalle' => null,
                'id_tb_egreso_proveedor' => null,
                'folio' => $nuevoFolio,
                'año' => date('Y'),
                'folio_completo' => $prefijo.'-'.str_pad($nuevoFolio, 4, '0', STR_PAD_LEFT),
                'solicitado' => $validated['fecha_tramite'],
                'observaciones' => $validated['concepto'] ?? null,
                'sub_total' => $totales['sub_total'],
                'isr' => null,
                'iva' => $totales['iva'],
                'descuento' => 0,
                'total' => $totales['total'],
                'monto_requisicion' => $totales['total'],
                'sub_direccion' => null,
                'departamento' => $mantenimiento?->departamento?->nombre,
                ...$this->datosSinFactura($validated),
                'extemporanea' => $extemporanea,
            ]);

            // Si el mantenimiento tenía una requisición cancelada, esta la sustituye (queda anotado en la bitácora de ambas).
            $sustituida = $mantenimiento?->requisicion;
            $mantenimiento?->update(['id_tb_egreso_requisicion' => $requisicion->id_tb_egreso_requisicion]);
            $this->guardarDetalles($requisicion, $filasCalculadas);
            $this->bitacora($requisicion, 1, 1, ($extemporanea
                ? 'Requisición creada fuera del periodo de captura (extemporánea): requiere autorización de la Subdirección Administrativa.'
                : 'Requisición creada.')
                .($sustituida ? " Sustituye a la requisición cancelada {$sustituida->folio_completo} del mantenimiento {$mantenimiento->folio_completo}." : ''));
            if ($sustituida) {
                $this->bitacora($sustituida, EgresoRequisicion::CANCELADA, EgresoRequisicion::CANCELADA,
                    "Sustituida por la requisición {$requisicion->folio_completo} del mantenimiento {$mantenimiento->folio_completo}.");
            }

            return $requisicion;
        });

        return response()->json([
            'id' => $requisicion->id_tb_egreso_requisicion,
            'extemporanea' => $extemporanea,
            'message' => $extemporanea
                ? 'Requisición creada como EXTEMPORÁNEA: la Subdirección Administrativa debe autorizarla; al autorizarla se envía sola a revisión.'
                : "Requisición {$requisicion->folio_completo} creada.",
        ], 201);
    }

    public function update(Request $request, EgresoRequisicion $requisicion): JsonResponse
    {
        $this->autorizarUnidad($requisicion);
        abort_unless($this->puedeEditarse($requisicion), 403, 'Esta requisición ya no se puede modificar en su estado actual.');

        $esCotizacion = (int) $requisicion->id_cat_egreso_requisicion_estado === 6;
        // En las de mantenimiento vehicular, el taller captura y cotiza (VehiculoMantenimiento::delTaller).
        $taller = VehiculoMantenimiento::delTaller($request->user(), $requisicion->id_tb_egreso_requisicion);
        abort_if($esCotizacion && ! $taller && ! $request->user()->can('requisiciones-cotizar'), 403, 'La cotización la captura Recursos Materiales.');
        abort_if(! $esCotizacion && ! $taller && ! $request->user()->can('requisiciones-edit'), 403);

        if ($esCotizacion) {
            $validated = $request->validate([
                'fecha_tramite' => 'required|date',
                'filas' => 'nullable|array',
                'filas.*.precio_unitario' => 'nullable|numeric|min:0|decimal:0,4', // la cotización guarda hasta 4 decimales
                // El IVA se decide aquí, en Recursos Materiales, al cotizar, y por renglón.
                'filas.*.lleva_iva' => 'boolean',
            ]);

            DB::transaction(function () use ($requisicion, $validated) {
                // Uno por uno (no un update masivo) para que quede en la bitácora de auditoría.
                $detalles = EgresoRequisicionDetalle::where('id_tb_egreso_requisicion', $requisicion->id_tb_egreso_requisicion)->orderBy('id_tb_egreso_requisicion_detalle')->get();
                foreach ($validated['filas'] ?? [] as $i => $fila) {
                    if (! isset($detalles[$i])) {
                        continue;
                    }
                    $precioVal = $fila['precio_unitario'] ?? '';
                    $precio = ($precioVal !== '' && is_numeric($precioVal)) ? (float) $precioVal : null;
                    $ivaFactor = ! empty($fila['lleva_iva']) ? self::IVA_FACTOR : 0;
                    $cant = (float) $detalles[$i]->cantidad;
                    $subTotal = $precio !== null ? round($precio * $cant, 4) : null;
                    $iva = $subTotal !== null ? round($subTotal * $ivaFactor, 4) : null;
                    $detalles[$i]->update([
                        'precio_unitario' => $precio,
                        'iva_factor' => $ivaFactor,
                        'sub_total' => $subTotal,
                        'iva' => $iva,
                        'total' => $subTotal !== null ? round($subTotal + $iva, 4) : null,
                    ]);
                }

                // El total de la requisición siempre es la suma de sus conceptos, nunca se captura aparte.
                $detalles = EgresoRequisicionDetalle::where('id_tb_egreso_requisicion', $requisicion->id_tb_egreso_requisicion)->get();
                $requisicion->update([
                    'solicitado' => $validated['fecha_tramite'],
                    'sub_total' => $detalles->sum('sub_total'),
                    'iva' => $detalles->sum('iva'),
                    'total' => $detalles->sum('total'),
                    'monto_requisicion' => $detalles->sum('total'),
                    // Si cambian los precios hay que volver a validar la suficiencia.
                    'suficiencia' => null,
                    'suficiencia_fecha' => null,
                    'suficiencia_user_id' => null,
                ]);

                $estado = (int) $requisicion->id_cat_egreso_requisicion_estado;
                $this->bitacora($requisicion, $estado, $estado, 'Cotización actualizada: precios unitarios modificados.');
            });

            return response()->json(['message' => 'Cotización actualizada.']);
        }

        $validated = $request->validate(...$this->reglasCaptura());

        $idsVisibles = $this->unidadesVisibles($request->user());
        if ($idsVisibles !== null && ! in_array((int) $validated['id_cat_egreso_unidad_administrativa'], $idsVisibles, true)) {
            abort(403, 'Esa unidad administrativa no pertenece a tu secretaría.');
        }

        $this->validarLineasPresupuesto($validated['filas'] ?? [], EgresoUnidadAdministrativa::find($validated['id_cat_egreso_unidad_administrativa']));
        $deMantenimiento = VehiculoMantenimiento::where('id_tb_egreso_requisicion', $requisicion->id_tb_egreso_requisicion)->exists();
        if ($deMantenimiento) {
            $this->validarPartidasMantenimiento($validated['filas'] ?? []);
            $this->validarPreciosMantenimiento($validated['filas'] ?? []);
        }

        [$filasCalculadas, $totales] = $this->calcularFilas($this->filasDeCaptura($validated, $deMantenimiento), (bool) ($validated['incluye_iva'] ?? false));

        DB::transaction(function () use ($requisicion, $validated, $filasCalculadas, $totales) {
            $requisicion->update([
                'id_cat_egreso_unidad_administrativa' => $validated['id_cat_egreso_unidad_administrativa'],
                'observaciones' => $validated['concepto'] ?? null,
                'solicitado' => $validated['fecha_tramite'],
                'sub_total' => $totales['sub_total'],
                'iva' => $totales['iva'],
                'total' => $totales['total'],
                'monto_requisicion' => $totales['total'],
                ...$this->datosSinFactura($validated),
            ]);

            EgresoRequisicionDetalle::where('id_tb_egreso_requisicion', $requisicion->id_tb_egreso_requisicion)
                ->get()
                ->each(fn ($detalle) => $detalle->delete());

            $this->guardarDetalles($requisicion, $filasCalculadas);

            $estado = (int) $requisicion->id_cat_egreso_requisicion_estado;
            $this->bitacora($requisicion, $estado, $estado, 'Requisición modificada: unidad admin y partidas actualizadas.');
        });

        return response()->json(['message' => 'Requisición actualizada.']);
    }

    public function destroy(EgresoRequisicion $requisicion): JsonResponse
    {
        $this->autorizarUnidad($requisicion);
        // En SIEG no se revisaba el estado; aquí solo se elimina lo que aún no se envía (después se cancela).
        abort_unless(in_array((int) $requisicion->id_cat_egreso_requisicion_estado, [1, 4], true), 422, 'Solo se elimina una requisición pendiente o rechazada; después se cancela.');

        // Los conceptos se borran uno por uno primero para que la bitácora guarde lo que tenían.
        DB::transaction(function () use ($requisicion) {
            $requisicion->tbEgresoRequisicionDetalles()->get()->each(fn ($detalle) => $detalle->delete());
            $requisicion->delete();
        });

        return response()->json(['message' => 'Requisición eliminada.']);
    }

    // ---------------------------------------------------------------- Trámite

    public function enviarRevision(EgresoRequisicion $requisicion): JsonResponse
    {
        $this->autorizarUnidad($requisicion);

        if ($requisicion->extemporaneaPendiente()) {
            return $this->error('Requisición extemporánea: la Subdirección Administrativa debe autorizarla antes de enviarla a revisión.');
        }
        if (! in_array((int) $requisicion->id_cat_egreso_requisicion_estado, [1, 4], true)) {
            return $this->error('Solo se envía a revisión una requisición pendiente o rechazada.');
        }

        $anterior = (int) $requisicion->id_cat_egreso_requisicion_estado;
        DB::transaction(function () use ($requisicion, $anterior) {
            $requisicion->update(['id_cat_egreso_requisicion_estado' => 2]);
            $this->bitacora($requisicion, $anterior, 2, 'Enviado a revisión.');
        });

        return response()->json(['message' => 'Requisición enviada a revisión.']);
    }

    /**
     * Recursos Materiales y Control Presupuestal (permiso requisiciones-fecha) cambian la fecha de
     * cualquier requisición; el enlace de cada secretaría, la de las suyas.
     */
    private function puedeCambiarFecha(EgresoRequisicion $requisicion): bool
    {
        $user = auth()->user();
        if ($user->can('requisiciones-fecha')) {
            return true;
        }

        $idsVisibles = $this->unidadesVisibles($user);

        return $user->can('requisiciones-create')
            && ($idsVisibles === null || in_array((int) $requisicion->id_cat_egreso_unidad_administrativa, $idsVisibles, true));
    }

    /**
     * Antes de la orden de compra se puede cancelar: con el permiso requisiciones-cancelar en
     * cualquiera de esos estados; el enlace de la secretaría, las suyas mientras no se envíen
     * (pendiente o rechazada).
     */
    private function puedeCancelar(EgresoRequisicion $requisicion): bool
    {
        $estado = (int) $requisicion->id_cat_egreso_requisicion_estado;
        if ($estado === EgresoRequisicion::CANCELADA) {
            return false;
        }

        // Con orden de compra se cancela de lo último a lo primero: CLC, orden de compra y al final la requisición.
        $orden = $requisicion->ordenCompra;
        if ($orden) {
            // Apoyo sin factura: no hay orden que cancelar aparte; basta con que su CLC no esté vigente.
            $libre = $orden->sin_factura ? ! $orden->clcActiva() : $orden->cancelada_at !== null;

            return $libre && auth()->user()->can('requisiciones-cancelar');
        }
        if (! in_array($estado, EgresoRequisicion::CANCELABLES, true)) {
            return false;
        }

        $user = auth()->user();
        if ($user->can('requisiciones-cancelar')) {
            return true;
        }

        $idsVisibles = $this->unidadesVisibles($user);

        return in_array($estado, [1, 4], true) && $user->can('requisiciones-create')
            && ($idsVisibles === null || in_array((int) $requisicion->id_cat_egreso_unidad_administrativa, $idsVisibles, true));
    }

    /** Cancela la requisición con su motivo; deja de contar en el presupuesto y queda en la bitácora. */
    public function cancelar(Request $request, EgresoRequisicion $requisicion): JsonResponse
    {
        abort_unless($this->puedeCancelar($requisicion), 403, 'Esta requisición ya no se puede cancelar o no tienes permiso.');

        $validated = $request->validate(
            ['motivo' => 'required|string|min:5|max:1000'],
            ['motivo.required' => 'Indica el motivo de la cancelación.', 'motivo.min' => 'El motivo debe tener al menos 5 caracteres.'],
        );

        $user = $request->user();
        $anterior = (int) $requisicion->id_cat_egreso_requisicion_estado;

        DB::transaction(function () use ($requisicion, $validated, $user, $anterior) {
            // Apoyo sin factura: su registro de CLC (AP-…) se cancela junto con la requisición.
            $apoyo = $requisicion->ordenCompra;
            if ($apoyo?->sin_factura && ! $apoyo->cancelada_at) {
                $apoyo->update(['cancelada_at' => now(), 'cancelada_user_id' => $user->id, 'motivo_cancelacion' => $validated['motivo']]);
            }
            $requisicion->update([
                'id_cat_egreso_requisicion_estado' => EgresoRequisicion::CANCELADA,
                'cancelada_at' => now(),
                'cancelada_user_id' => $user->id,
                'motivo_cancelacion' => $validated['motivo'],
            ]);
            $this->bitacora($requisicion, $anterior, EgresoRequisicion::CANCELADA, "Requisición cancelada por {$user->name}. Motivo: {$validated['motivo']}");
        });

        return response()->json(['message' => "Requisición {$requisicion->folio_completo} cancelada."]);
    }

    /** Cambia la fecha de trámite de la requisición (la que imprime el FUR); queda en la bitácora. */
    public function cambiarFecha(Request $request, EgresoRequisicion $requisicion): JsonResponse
    {
        abort_unless($this->puedeCambiarFecha($requisicion), 403, 'No puedes cambiar la fecha de esta requisición.');

        $año = (int) ($requisicion->año ?: date('Y'));
        $validated = $request->validate([
            'fecha' => ['required', 'date', 'before_or_equal:today', function ($attr, $valor, $fallo) use ($año) {
                if ((int) date('Y', strtotime($valor)) !== $año) {
                    $fallo("La fecha debe ser del ejercicio {$año}, como el folio de la requisición.");
                }
            }],
        ], ['fecha.before_or_equal' => 'La fecha no puede ser futura.']);

        $anterior = $requisicion->solicitado ? substr((string) $requisicion->solicitado, 0, 10) : null;
        if ($anterior === $validated['fecha']) {
            return response()->json(['message' => 'La fecha no cambió.']);
        }

        DB::transaction(function () use ($requisicion, $validated, $anterior) {
            $requisicion->update(['solicitado' => $validated['fecha']]);
            $estado = (int) $requisicion->id_cat_egreso_requisicion_estado;
            $this->bitacora($requisicion, $estado, $estado,
                'Fecha de trámite cambiada'.($anterior ? " del {$anterior}" : '')." al {$validated['fecha']} por ".auth()->user()->name.'.');
        });

        return response()->json(['message' => 'Fecha de la requisición actualizada.']);
    }

    /**
     * La Subdirección Administrativa (Adquisiciones) autoriza una requisición capturada fuera del
     * periodo; queda registrado quién y cuándo. Al autorizarla se envía sola a revisión.
     */
    public function autorizarExtemporanea(Request $request, EgresoRequisicion $requisicion): JsonResponse
    {
        if (! $requisicion->extemporaneaPendiente()) {
            return $this->error('Esta requisición no es extemporánea o ya fue autorizada.');
        }

        $user = $request->user();
        $anterior = (int) $requisicion->id_cat_egreso_requisicion_estado;
        $aRevision = in_array($anterior, [1, 4], true);

        DB::transaction(function () use ($requisicion, $user, $anterior, $aRevision) {
            $requisicion->update([
                'extemporanea_user_id' => $user->id,
                'extemporanea_autorizada_at' => now(),
                ...($aRevision ? ['id_cat_egreso_requisicion_estado' => 2] : []),
            ]);
            $this->bitacora($requisicion, $anterior, (int) $requisicion->id_cat_egreso_requisicion_estado,
                "Requisición extemporánea autorizada por {$user->name}.".($aRevision ? ' Enviada a revisión.' : ''));
        });

        return response()->json(['message' => $aRevision
            ? 'Requisición extemporánea autorizada y enviada a revisión.'
            : 'Requisición extemporánea autorizada.']);
    }

    /**
     * Mueve la requisición al estado $nuevo si está en $desde y lo deja en su bitácora.
     * Devuelve false si no estaba en el estado esperado (doble clic, otra pestaña, otro usuario).
     */
    private function cambiarEstado(EgresoRequisicion $requisicion, int $desde, int $nuevo, string $observaciones): bool
    {
        if ((int) $requisicion->id_cat_egreso_requisicion_estado !== $desde) {
            return false;
        }

        DB::transaction(function () use ($requisicion, $desde, $nuevo, $observaciones) {
            $requisicion->update(['id_cat_egreso_requisicion_estado' => $nuevo]);
            $this->bitacora($requisicion, $desde, $nuevo, $observaciones);
        });

        return true;
    }

    /** "Válida": en SIEG el botón solo lo ven Admin y Super Admin; aquí también lo exige el servidor. */
    public function aprobar(Request $request, EgresoRequisicion $requisicion): JsonResponse
    {
        abort_unless($request->user()->hasRole(['Super Admin', 'Admin']), 403, 'La validación de requisiciones la hacen Admin y Super Admin.');

        if (! $this->cambiarEstado($requisicion, 2, 3, 'Requisición aprobada.')) {
            return $this->error('Solo se puede validar una requisición en revisión.');
        }

        return response()->json(['message' => "Requisición {$requisicion->folio_completo} aprobada."]);
    }

    /** "No válida": el motivo es obligatorio y queda en la bitácora para que el área lo corrija. */
    public function rechazar(Request $request, EgresoRequisicion $requisicion): JsonResponse
    {
        abort_unless($request->user()->hasRole(['Super Admin', 'Admin']), 403, 'La validación de requisiciones la hacen Admin y Super Admin.');

        $validated = $request->validate(
            ['motivo' => 'required|string|min:5|max:1000'],
            ['motivo.required' => 'Escribe el motivo del rechazo.', 'motivo.min' => 'Explica el motivo del rechazo.'],
        );

        if (! $this->cambiarEstado($requisicion, 2, 4, 'Requisición rechazada. Motivo: '.trim($validated['motivo']))) {
            return $this->error('Solo se puede rechazar una requisición en revisión.');
        }

        return response()->json(['message' => "Requisición {$requisicion->folio_completo} rechazada."]);
    }

    /**
     * Recursos Materiales regresa la requisición al área (p. ej. un producto que no está en stock) para que
     * la modifique y la vuelva a enviar. Queda como No válida (4), editable por el área, con el motivo.
     */
    public function devolverArea(Request $request, EgresoRequisicion $requisicion): JsonResponse
    {
        $validated = $request->validate(
            ['motivo' => 'required|string|min:5|max:1000'],
            ['motivo.required' => 'Escribe por qué se regresa al área.', 'motivo.min' => 'Explica por qué se regresa al área.'],
        );
        if ($requisicion->suficiencia === true) {
            return $this->error('Ya tiene suficiencia presupuestal: ya no se regresa al área.');
        }

        $observaciones = 'Regresada al área por Recursos Materiales ('.$request->user()->name.') para que la modifique. Motivo: '.trim($validated['motivo']);
        if (! $this->cambiarEstado($requisicion, 5, 4, $observaciones) && ! $this->cambiarEstado($requisicion, 6, 4, $observaciones)) {
            return $this->error('Solo se regresa al área una requisición en Recursos Materiales o en cotización.');
        }

        return response()->json(['message' => "Requisición {$requisicion->folio_completo} regresada al área para que la modifique."]);
    }

    public function enviarRecursosMateriales(EgresoRequisicion $requisicion): JsonResponse
    {
        $this->autorizarUnidad($requisicion);
        if ($requisicion->sin_factura) {
            return $this->error('Un apoyo sin factura no va a Recursos Materiales: de aprobada pasa a validar la suficiencia.');
        }
        $deTaller = VehiculoMantenimiento::where('id_tb_egreso_requisicion', $requisicion->id_tb_egreso_requisicion)->exists();
        abort_unless(auth()->user()->can('requisiciones-edit') || ($deTaller && auth()->user()->can('vehiculos-mantenimiento')), 403);

        // Las de mantenimiento vehicular las cotiza el taller, no Recursos Materiales.
        $destino = $deTaller ? 'al taller para cotizar' : 'a Recursos Materiales';
        if (! $this->cambiarEstado($requisicion, 3, 5, "Enviado {$destino}. PDF firmado por el titular.")) {
            return $this->error('Solo se envía a cotizar una requisición aprobada.');
        }

        return response()->json(['message' => "Requisición {$requisicion->folio_completo} enviada {$destino}."]);
    }

    public function recibido(EgresoRequisicion $requisicion): JsonResponse
    {
        $taller = VehiculoMantenimiento::delTaller(auth()->user(), $requisicion->id_tb_egreso_requisicion);
        abort_unless($taller || auth()->user()->can('requisiciones-cotizar'), 403);

        $observaciones = $taller ? 'Recibido en el taller. Inicia proceso de cotización.' : 'Recibido en Recursos Materiales. Inicia proceso de cotización.';
        if (! $this->cambiarEstado($requisicion, 5, 6, $observaciones)) {
            return $this->error('Solo se recibe una requisición enviada a Recursos Materiales.');
        }

        return response()->json(['message' => 'Requisición recibida. En cotización.']);
    }

    /**
     * Recursos Materiales manda a validar la cotización contra el presupuesto: si cada línea
     * alcanza a cubrir lo cotizado, la requisición queda con suficiencia (y su monto comprometido).
     */
    public function validarSuficiencia(Request $request, EgresoRequisicion $requisicion): JsonResponse
    {
        // Sin factura (apoyo) no pasa por cotización: se valida en cuanto está aprobada (3).
        // Mantenimiento aprobado con los precios del taller: también desde aprobada; si se fue a cotizar, desde ahí.
        $deTaller = VehiculoMantenimiento::where('id_tb_egreso_requisicion', $requisicion->id_tb_egreso_requisicion)->exists();
        $estadoValido = $requisicion->sin_factura || ($deTaller && (int) $requisicion->id_cat_egreso_requisicion_estado === 3) ? 3 : 6;
        if ((int) $requisicion->id_cat_egreso_requisicion_estado !== $estadoValido || $requisicion->suficiencia === true) {
            return $this->error($requisicion->sin_factura
                ? 'La suficiencia de un apoyo sin factura se valida cuando la requisición está aprobada y aún no la tiene.'
                : 'Solo se valida la suficiencia de una requisición en cotización que aún no la tiene.');
        }

        $requisicion->load('tbEgresoRequisicionDetalles');
        $sinPrecio = $requisicion->tbEgresoRequisicionDetalles->filter(fn ($d) => $d->precio_unitario === null || (float) $d->precio_unitario <= 0);
        if ($requisicion->tbEgresoRequisicionDetalles->isEmpty() || $sinPrecio->isNotEmpty()) {
            return $this->error('Captura el precio unitario de todos los conceptos antes de validar la suficiencia.');
        }

        $resultado = DB::transaction(function () use ($requisicion, $request) {
            // Bloquea la requisición para que dos validaciones simultáneas no comprometan el mismo saldo.
            EgresoRequisicion::whereKey($requisicion->id_tb_egreso_requisicion)->lockForUpdate()->first();

            $resultado = app(PresupuestoService::class)->validarRequisicion($requisicion);

            // Con suficiencia la requisición deja de estar "En Cotización": queda comprometida.
            $anterior = (int) $requisicion->id_cat_egreso_requisicion_estado;
            $requisicion->update([
                'suficiencia' => $resultado['suficiente'],
                'suficiencia_fecha' => now(),
                'suficiencia_user_id' => $request->user()->id,
                ...($resultado['suficiente'] ? ['id_cat_egreso_requisicion_estado' => EgresoRequisicion::COMPROMETIDA] : []),
            ]);

            $this->bitacora($requisicion, $anterior, (int) $requisicion->id_cat_egreso_requisicion_estado, $resultado['suficiente']
                ? 'Validada contra presupuesto: CON SUFICIENCIA por $'.number_format($resultado['total'], 2).'. Requisición comprometida.'
                : 'Validada contra presupuesto: SIN SUFICIENCIA. Se requiere modificación presupuestal.');

            return $resultado;
        });

        return response()->json([
            'suficiente' => $resultado['suficiente'],
            'resultado' => $resultado,
            'message' => $resultado['suficiente'] ? 'Con suficiencia presupuestal: la requisición queda comprometida.' : 'Sin suficiencia presupuestal.',
        ]);
    }

    public function pdf(EgresoRequisicion $requisicion)
    {
        $this->autorizarUnidad($requisicion);

        // Una cancelada (12) tampoco se imprime.
        abort_unless(in_array((int) $requisicion->id_cat_egreso_requisicion_estado, [3, ...self::ESTADOS_RECURSOS_MATERIALES], true), 403,
            'El PDF solo está disponible para requisiciones validadas y no canceladas.');

        $requisicion->load('tbEgresoRequisicionDetalles', 'catEgresoUnidadAdministrativa');
        $pdf = Pdf::loadView('requisiciones.pdf', compact('requisicion'))->setPaper('letter');

        // Pie: "Página N de M" y el folio (dompdf no conoce el total de páginas desde el CSS).
        $pdf->render();
        $canvas = $pdf->getDomPDF()->getCanvas();
        $fuente = $pdf->getDomPDF()->getFontMetrics()->getFont('Helvetica');
        $canvas->page_text($canvas->get_width() - 185, $canvas->get_height() - 27, "Página {PAGE_NUM} de {PAGE_COUNT}     FUR {$requisicion->folio_completo}", $fuente, 7.5);

        return $pdf->stream("FUR-{$requisicion->folio_completo}.pdf");
    }

    private function error(string $mensaje): JsonResponse
    {
        return response()->json(['message' => $mensaje], 422);
    }

    // ---------------------------------------------------------------- Ejercicio y control del gasto

    /**
     * "Ejercicio y control del gasto": por línea de presupuesto, lo asignado, las modificaciones
     * autorizadas, lo comprometido, devengado, pagado, lo disponible y el saldo del mes.
     */
    public function gasto(Request $request): JsonResponse
    {
        [$filas, $unidadesDisponibles] = $this->lineasDelGasto($request);
        $hoy = now(SaldosMensuales::ZONA);
        $corrida = CorridaSaldo::with('usuario:id,name')->where('ejercicio', $hoy->year)->where('mes', $hoy->month)->first();

        // 20 partidas por página; los totales son de todas las que cumplen los filtros.
        $porPagina = 20;
        $pagina = max(1, (int) $request->input('page', 1));
        $suma = fn (string $campo) => round($filas->sum($campo), 2);

        return response()->json([
            'data' => $filas->forPage($pagina, $porPagina)->values(),
            'current_page' => $pagina,
            'last_page' => max(1, (int) ceil($filas->count() / $porPagina)),
            'per_page' => $porPagina,
            'total' => $filas->count(),
            'totales' => collect(['asignado', 'modificado', 'vigente', 'comprometido', 'devengado', 'pagado', 'disponible', 'saldo_corrido', 'disponible_mes'])
                ->mapWithKeys(fn ($c) => [$c => $suma($c)]),
            'unidades' => $this->unidadesParaFront($unidadesDisponibles),
            'saldos' => [
                'mes' => SaldosMensuales::MESES[$hoy->month],
                'mesAnterior' => $hoy->month > 1 ? SaldosMensuales::MESES[$hoy->month - 1] : null,
                'corrida' => $corrida ? [
                    'fecha' => $corrida->created_at->toIso8601String(),
                    'origen' => $corrida->origen,
                    'usuario' => $corrida->usuario?->name,
                    'total' => (float) $corrida->total_corrido,
                ] : null,
                'puedeCorrer' => ! $corrida && $request->user()->can('presupuesto-correr-saldos'),
            ],
        ]);
    }

    /**
     * Detalle de una partida en el ejercicio del gasto: requisiciones que la usan (con su orden de
     * compra, CLC, proveedor, importe de la partida y fechas) y modificaciones presupuestales.
     */
    public function gastoDetalle(Request $request): JsonResponse
    {
        $clave = (string) $request->input('clave');
        $partes = explode('|', $clave);
        abort_unless(count($partes) === 4, 404);
        [$unidad, $fuente, $proyecto, $partida] = $partes;

        $ids = $this->unidadesVisibles($request->user());
        $idsUnidad = EgresoUnidadAdministrativa::whereRaw('TRIM(clave) = ?', [$unidad])
            ->when($ids !== null, fn ($q) => $q->whereIn('id_cat_egreso_unidad_administrativa', $ids))
            ->pluck('id_cat_egreso_unidad_administrativa');
        abort_if($idsUnidad->isEmpty(), 403, 'Esta partida no pertenece a tu unidad administrativa.');

        // Importe de la partida en cada requisición (una requisición puede usar varias partidas).
        $importes = DB::table('tb_egreso_requisicion_detalle as d')
            ->join('tb_egreso_requisicion as r', 'r.id_tb_egreso_requisicion', '=', 'd.id_tb_egreso_requisicion')
            ->whereIn('r.id_cat_egreso_unidad_administrativa', $idsUnidad)
            ->whereRaw('TRIM(d.fuente) = ?', [$fuente])
            ->whereRaw('TRIM(d.proyecto) = ?', [$proyecto])
            ->whereRaw('TRIM(d.partida) = ?', [$partida])
            ->groupBy('r.id_tb_egreso_requisicion')
            ->selectRaw('r.id_tb_egreso_requisicion as id, SUM(COALESCE(d.total, d.sub_total, 0)) as importe')
            ->pluck('importe', 'id');

        $requisiciones = EgresoRequisicion::with('catEgresoRequisicionEstado', 'ordenCompra.proveedor:id,nombre,rfc')
            ->whereIn('id_tb_egreso_requisicion', $importes->keys())
            ->orderByDesc('id_tb_egreso_requisicion')
            ->get()
            ->map(function (EgresoRequisicion $r) use ($importes) {
                $oc = $r->ordenCompra;
                $estado = (int) $r->id_cat_egreso_requisicion_estado;

                return [
                    'id' => $r->id_tb_egreso_requisicion,
                    'folio' => $r->folio_completo,
                    'fecha' => $r->registro,
                    'estado_id' => $estado,
                    'estado' => $r->catEgresoRequisicionEstado?->descripcion,
                    'momento' => match (true) {
                        in_array($estado, [4, EgresoRequisicion::CANCELADA], true) => null,
                        $oc && ! $oc->cancelada_at && ! $oc->clc_cancelada_at && $oc->pago_fecha => 'Pagado',
                        $oc && ! $oc->cancelada_at && $oc->xml_uuid => 'Devengado',
                        (bool) $r->suficiencia => 'Comprometido',
                        default => 'En trámite',
                    },
                    'importe' => round((float) $importes[$r->id_tb_egreso_requisicion], 2),
                    'suficiencia_fecha' => $r->suficiencia_fecha?->toIso8601String(),
                    'cancelada_at' => $r->cancelada_at?->toIso8601String(),
                    'orden' => $oc ? [
                        'folio' => $oc->folio_completo,
                        'proveedor' => $oc->aFavorDe(),
                        'factura' => $oc->xml_uuid ? (trim(($oc->xml_serie ?? '').' '.($oc->xml_folio ?? '')) ?: $oc->xml_uuid) : null,
                        'clc' => $oc->clc_folio_completo,
                        'clc_estado' => $oc->clc_folio_completo ? TramiteClc::descripcion($oc->clc_estado) : null,
                        'pago_fecha' => $oc->clc_cancelada_at ? null : $oc->pago_fecha?->format('Y-m-d'),
                        'cancelada' => $oc->cancelada_at !== null,
                    ] : null,
                ];
            })->values();

        $lado = fn ($q, string $l) => $q->where("{$l}_clave", $unidad)->where("{$l}_fuente", $fuente)
            ->where("{$l}_proyecto", $proyecto)->where("{$l}_partida", $partida);

        $modificaciones = ModificacionPresupuestal::query()
            ->where(fn ($q) => $q->where(fn ($q) => $lado($q, 'origen'))->orWhere(fn ($q) => $lado($q, 'destino')))
            ->orderByDesc('id')
            ->get()
            ->map(function (ModificacionPresupuestal $m) use ($clave) {
                $esOrigen = $m->claveLinea('origen') === $clave;
                $mes = $m->{$esOrigen ? 'origen_mes' : 'destino_mes'};

                return [
                    'id' => $m->id,
                    'folio' => $m->folio_completo,
                    'tipo' => ModificacionPresupuestal::TIPOS[$m->tipo] ?? $m->tipo,
                    'estado' => $m->estado,
                    'lado' => $esOrigen ? 'Emisor' : 'Receptor',
                    'contraparte' => $esOrigen ? $m->destino_descripcion : $m->origen_descripcion,
                    'mes' => $mes ? SaldosMensuales::MESES[$mes] : null,
                    // Con signo: lo que suma o resta a esta partida.
                    'importe' => round(($esOrigen ? -1 : 1) * $m->importe, 2),
                    'fecha' => $m->created_at?->toIso8601String(),
                ];
            })->values();

        return response()->json(['requisiciones' => $requisiciones, 'modificaciones' => $modificaciones]);
    }

    /** La misma consulta de la pantalla (unidad, solo con movimiento y búsqueda) en un Excel. */
    public function exportarGasto(Request $request)
    {
        [$filas, $unidadesDisponibles] = $this->lineasDelGasto($request);

        $buscar = trim((string) $request->input('buscar'));
        $unidad = $request->input('unidad')
            ? $unidadesDisponibles->firstWhere('id_cat_egreso_unidad_administrativa', (int) $request->input('unidad'))?->nombre
            : null;

        $filtros = array_filter([
            $unidad ? "Unidad: {$unidad}" : 'Todas las unidades visibles',
            $request->boolean('con_movimiento') ? 'Solo partidas con movimiento' : null,
            $buscar !== '' ? "Búsqueda: {$buscar}" : null,
        ]);

        $libro = (new ExcelGasto)->libro($filas, $filtros);

        return response()->streamDownload(
            fn () => (new Xlsx($libro))->save('php://output'),
            'ejercicio_y_control_del_gasto_'.now('America/Mexico_City')->format('Ymd_Hi').'.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    /**
     * Líneas visibles para el usuario con los filtros de la pantalla del gasto.
     *
     * @return array{0: Collection, 1: Collection}
     */
    private function lineasDelGasto(Request $request): array
    {
        $unidadesQuery = EgresoUnidadAdministrativa::where('activo', 1);
        $idsVisibles = $this->unidadesVisibles($request->user());
        if ($idsVisibles !== null) {
            $unidadesQuery->whereIn('id_cat_egreso_unidad_administrativa', $idsVisibles);
        }
        $unidadesDisponibles = $unidadesQuery->orderBy('nombre')->get(['id_cat_egreso_unidad_administrativa', 'clave', 'nombre']);

        $unidadSeleccionada = $request->input('unidad');
        $unidades = $unidadSeleccionada
            ? $unidadesDisponibles->where('id_cat_egreso_unidad_administrativa', (int) $unidadSeleccionada)->values()
            : $unidadesDisponibles;

        $filas = app(PresupuestoService::class)->lineas($unidades->pluck('clave')->map(fn ($c) => trim((string) $c))->unique()->values()->all());

        // Saldo corrido de meses anteriores y disponible del mes actual de cada línea.
        $hoy = now(SaldosMensuales::ZONA);
        $situacion = app(SaldosMensuales::class)->situacion($hoy->year, $hoy->month);
        $filas = $filas->map(fn ($l) => [
            ...$l,
            'saldo_corrido' => $situacion[$l['clave']]['saldo_corrido'] ?? 0.0,
            'disponible_mes' => $situacion[$l['clave']]['disponible_mes'] ?? 0.0,
        ]);

        if ($request->boolean('con_movimiento')) {
            $filas = $filas->filter(fn ($l) => $l['modificado'] != 0 || $l['comprometido'] != 0 || $l['devengado'] != 0 || $l['pagado'] != 0)->values();
        }

        // Todas las palabras deben aparecer en unidad, fuente, proyecto o partida.
        $terminos = array_filter(preg_split('/\s+/', mb_strtolower(trim((string) $request->input('buscar')))));

        if ($terminos !== []) {
            $filas = $filas->filter(function ($l) use ($terminos) {
                $texto = mb_strtolower("{$l['unidad_clave']} {$l['unidad']} {$l['fuente']} {$l['nombre_fuente']} {$l['proyecto']} {$l['nombre_proyecto']} {$l['partida']} {$l['nombre_partida']}");

                return collect($terminos)->every(fn ($t) => str_contains($texto, $t));
            })->values();
        }

        return [$filas, $unidadesDisponibles];
    }
}
