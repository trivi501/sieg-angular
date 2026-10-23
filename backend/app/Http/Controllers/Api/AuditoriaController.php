<?php

namespace App\Http\Controllers\Api;

use App\Catalogos\Catalogos;
use App\Http\Controllers\Controller;
use App\Models\AlmacenEntrada;
use App\Models\AlmacenEntradaDetalle;
use App\Models\AlmacenSalida;
use App\Models\AlmacenSalidaDetalle;
use App\Models\Auditoria;
use App\Models\ClcRelacion;
use App\Models\EgresoRequisicion;
use App\Models\EgresoRequisicionDetalle;
use App\Models\ModificacionPresupuestal;
use App\Models\OrdenCompra;
use App\Models\OrdenCompraRefactura;
use App\Models\PresupuestoEgreso;
use App\Models\User;
use App\Models\VehiculoMantenimiento;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Bitácora de auditoría general: altas, modificaciones, bajas y accesos de todo el sistema.
 * SIEG: AuditoriaController, respondiendo JSON; los enlaces llevan a las rutas de Angular.
 */
class AuditoriaController extends Controller
{
    /**
     * Módulos que no son catálogos: etiqueta y nombres legibles de sus campos más usados. Secretarías y
     * tickets van por nombre de clase: sus modelos aún no están en este backend, pero sus movimientos sí
     * están en la bitácora copiada de SIEG.
     */
    private const MODULOS = [
        EgresoRequisicion::class => ['Requisiciones', [
            'id_cat_egreso_requisicion_estado' => 'Estado', 'id_cat_egreso_unidad_administrativa' => 'Unidad administrativa',
            'folio_completo' => 'Folio', 'observaciones' => 'Concepto', 'solicitado' => 'Fecha de trámite',
            'sub_total' => 'Subtotal', 'iva' => 'IVA', 'total' => 'Total', 'monto_requisicion' => 'Monto',
            'suficiencia' => 'Suficiencia', 'suficiencia_fecha' => 'Fecha de suficiencia', 'suficiencia_user_id' => 'Validó suficiencia (usuario)',
        ]],
        EgresoRequisicionDetalle::class => ['Conceptos de requisición', [
            'id_tb_egreso_requisicion' => 'Requisición', 'descripcion' => 'Descripción', 'cantidad' => 'Cantidad',
            'fuente' => 'Fuente', 'proyecto' => 'Proyecto', 'partida' => 'Partida', 'precio_unitario' => 'Precio unitario',
            'sub_total' => 'Subtotal', 'iva' => 'IVA', 'iva_factor' => 'Factor de IVA', 'total' => 'Total',
        ]],
        OrdenCompra::class => ['Órdenes de compra', [
            'id_tb_egreso_requisicion' => 'Requisición', 'proveedor_id' => 'Proveedor', 'folio_completo' => 'Folio',
            'fecha' => 'Fecha', 'importe' => 'Importe', 'observaciones' => 'Observaciones',
            'xml_uuid' => 'UUID de la factura', 'xml_rfc_emisor' => 'RFC emisor', 'xml_total' => 'Total de la factura',
        ]],
        OrdenCompraRefactura::class => ['Refacturas de órdenes de compra', ['xml_uuid_anterior' => 'Factura anterior (UUID)', 'clc_folio_anterior' => 'CLC anterior', 'clc_nueva' => 'CLC nueva', 'motivo' => 'Motivo']],
        VehiculoMantenimiento::class => ['Mantenimiento vehicular', ['folio_completo' => 'Folio', 'vehiculo_id' => 'Vehículo', 'fecha_recepcion' => 'Recepción', 'kilometraje' => 'Kilometraje', 'tipo' => 'Tipo', 'descripcion' => 'Falla o servicio', 'estado' => 'Estado', 'fecha_entrega' => 'Entrega', 'recibe_entrega' => 'Recibe', 'id_tb_egreso_requisicion' => 'Requisición']],
        AlmacenEntrada::class => ['Entradas de almacén', ['folio_completo' => 'Folio', 'orden_compra_id' => 'Orden de compra', 'fecha' => 'Fecha', 'observaciones' => 'Observaciones']],
        AlmacenEntradaDetalle::class => ['Conceptos de entradas de almacén', ['descripcion' => 'Descripción', 'cantidad' => 'Cantidad', 'precio_unitario' => 'Precio unitario']],
        AlmacenSalida::class => ['Salidas de almacén', ['folio_completo' => 'Folio', 'orden_compra_id' => 'Orden de compra', 'fecha' => 'Fecha', 'recibe' => 'Recibe', 'observaciones' => 'Observaciones']],
        AlmacenSalidaDetalle::class => ['Conceptos de salidas de almacén', ['descripcion' => 'Descripción', 'cantidad' => 'Cantidad']],
        ClcRelacion::class => ['Relaciones de CLC', [
            'folio_completo' => 'Folio', 'origen' => 'Entrega', 'destino' => 'Recibe', 'observaciones' => 'Observaciones',
        ]],
        ModificacionPresupuestal::class => ['Modificaciones presupuestales', [
            'folio_completo' => 'Folio', 'tipo' => 'Tipo', 'estado' => 'Estado', 'importe' => 'Importe',
            'justificacion' => 'Justificación', 'observaciones_resolucion' => 'Observaciones de la resolución',
        ]],
        PresupuestoEgreso::class => ['Presupuesto de egresos', [
            'CLAVE' => 'Unidad', 'CLAVE3' => 'Fuente', 'CLAVE6' => 'Proyecto', 'PARTIDA' => 'Partida',
            'NOMBRE_PARTIDA' => 'Nombre de la partida', 'IMPORTE_TOTAL' => 'Importe total',
        ]],
        'App\Models\Secretaria' => ['Secretarías', ['nombre' => 'Nombre', 'prefijo' => 'Prefijo']],
        User::class => ['Usuarios y accesos', [
            'name' => 'Nombre', 'email' => 'Correo', 'secretaria_id' => 'Secretaría (anterior)', 'id_cat_egreso_unidad_administrativa' => 'Secretaría o dirección', 'password' => 'Contraseña',
            'roles' => 'Roles', 'email_verified_at' => 'Correo verificado',
        ]],
        Role::class => ['Roles', ['name' => 'Nombre', 'permisos' => 'Permisos']],
        Permission::class => ['Permisos', ['name' => 'Clave', 'nombre_mostrar' => 'Nombre', 'categoria' => 'Categoría']],
        'App\Models\SupportTicket' => ['Tickets de soporte', []],
        'App\Models\TicketComment' => ['Comentarios de tickets', []],
    ];

    public function index(Request $request): JsonResponse
    {
        $tipos = $this->tipos();

        $query = Auditoria::with('user:id,name')->latest('id');

        if ($tipo = $request->input('tipo')) {
            $query->where('auditable_type', $tipo);
        }

        if ($userId = $request->input('usuario')) {
            $query->where('user_id', $userId);
        }

        if ($accion = $request->input('accion')) {
            $query->where('accion', $accion);
        }

        // Las fechas del filtro son de hora local; created_at se guarda en UTC.
        if ($desde = $request->date('desde', null, 'America/Mexico_City')) {
            $query->where('created_at', '>=', $desde->startOfDay()->utc());
        }

        if ($hasta = $request->date('hasta', null, 'America/Mexico_City')) {
            $query->where('created_at', '<=', $hasta->endOfDay()->utc());
        }

        if ($lote = $request->input('lote')) {
            $query->where('lote', $lote);
        }

        if ($id = $request->input('registro')) {
            $query->where('auditable_id', $id);
        }

        $registros = $query->paginate(30)->through(function (Auditoria $a) use ($tipos) {
            $catalogo = Catalogos::deModelo($a->auditable_type);

            return [
                'id' => $a->id,
                'fecha' => $a->created_at?->toIso8601String(),
                'usuario' => $a->user?->name,
                'accion' => $a->accion,
                'tipo' => $tipos[$a->auditable_type] ?? class_basename($a->auditable_type),
                'auditable_type' => $a->auditable_type,
                'registro' => $a->auditable_id,
                'catalogo' => $catalogo?->slug,
                'enlace' => $this->enlace($a, $catalogo?->slug),
                'etiquetas' => $catalogo
                    ? collect($catalogo->campos)->mapWithKeys(fn ($c) => [$c->nombre => $c->etiqueta])->all()
                    : (self::MODULOS[$a->auditable_type][1] ?? []),
                'descripcion' => $a->descripcion,
                'lote' => $a->lote,
                'antes' => $a->antes,
                'despues' => $a->despues,
                'ip' => $a->ip,
            ];
        });

        return response()->json([
            ...$registros->toArray(),
            'tipos' => collect($tipos)->sort()->map(fn ($etiqueta, $clase) => ['valor' => $clase, 'etiqueta' => $etiqueta])->values(),
            'usuarios' => User::whereIn('id', Auditoria::query()->select('user_id')->distinct())->orderBy('name')->get(['id', 'name']),
            'acciones' => Auditoria::ACCIONES,
        ]);
    }

    /** @return array<class-string, string> */
    private function tipos(): array
    {
        return [
            ...collect(Catalogos::todos())->mapWithKeys(fn ($c) => [$c->modelo => $c->titulo])->all(),
            ...collect(self::MODULOS)->map(fn ($m) => $m[0])->all(),
        ];
    }

    /** Pantalla de Angular donde se ve el registro afectado, si existe una. */
    private function enlace(Auditoria $a, ?string $catalogo): ?string
    {
        if ($a->accion === 'eliminación' || $a->auditable_id === '') {
            return null;
        }

        $id = $a->auditable_id;
        $valor = fn (string $campo) => $a->despues[$campo] ?? $a->antes[$campo] ?? null;
        $requisicion = $valor('id_tb_egreso_requisicion');

        return match ($a->auditable_type) {
            EgresoRequisicion::class => "/requisiciones/{$id}",
            EgresoRequisicionDetalle::class, OrdenCompra::class => $requisicion ? "/requisiciones/{$requisicion}" : null,
            AlmacenEntrada::class, AlmacenSalida::class => ($oc = $valor('orden_compra_id')) ? "/almacen/{$oc}" : null,
            VehiculoMantenimiento::class => '/vehiculos/mantenimientos',
            ModificacionPresupuestal::class => "/modificaciones/{$id}",
            PresupuestoEgreso::class => "/presupuesto/{$id}",
            User::class => "/usuarios/{$id}",
            Role::class => "/roles/{$id}",
            Permission::class => '/permisos',
            default => $catalogo ? "/catalogos/{$catalogo}" : null,
        };
    }
}
