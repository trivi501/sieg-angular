<?php

namespace App\Services;

use App\Models\ClcMovimiento;
use App\Models\ClcRelacion;
use App\Models\EgresoRequisicion;
use App\Models\EgresoRequisicionBitacora;
use App\Models\EgresoUnidadAdministrativa;
use App\Models\OrdenCompra;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Trámite de la CLC entre áreas. Cada acción mueve las CLC seleccionadas de un estado a otro y las
 * recepciones se marcan una por una (las que no se marcan siguen pendientes de recibir). Solo los
 * envíos entre Control Presupuestal y Sindicatura (ida y regreso) generan relación (documento).
 *
 *   área (envía las de su secretaría) → Recursos Materiales → Control Presupuestal (revisa;
 *   puede devolver a RM con observaciones) → Sindicatura → Control Presupuestal (firmadas) → Egresos (pago)
 */
final class TramiteClc
{
    public const AREAS = [
        'area' => 'Área solicitante',
        'recursos_materiales' => 'Recursos Materiales',
        'control_presupuestal' => 'Control Presupuestal',
        'sindicatura' => 'Sindicatura',
        'egresos' => 'Egresos',
    ];

    /** Estado => [descripción, área donde está la CLC]. */
    public const ESTADOS = [
        'generada' => ['Generada', 'area'],
        'enviada_rm' => ['Enviada a Recursos Materiales', 'recursos_materiales'],
        'recibida_rm' => ['En Recursos Materiales', 'recursos_materiales'],
        'devuelta' => ['Devuelta a Recursos Materiales', 'recursos_materiales'],
        'enviada_cp' => ['Enviada a Control Presupuestal', 'control_presupuestal'],
        'recibida_cp' => ['En revisión (Control Presupuestal)', 'control_presupuestal'],
        'enviada_sindicatura' => ['Enviada a Sindicatura', 'sindicatura'],
        'recibida_sindicatura' => ['En Sindicatura', 'sindicatura'],
        'enviada_cp_firmada' => ['Enviada a Control Presupuestal (firmada)', 'control_presupuestal'],
        'recibida_cp_firmada' => ['En Control Presupuestal (firmada)', 'control_presupuestal'],
        'enviada_egresos' => ['Enviada a Egresos', 'egresos'],
        'recibida_egresos' => ['En Egresos', 'egresos'],
        'pagada' => ['Pagada', 'egresos'],
        // Fuera del trámite: no entra en ninguna bandeja. Sigue cancelar la orden de compra y la requisición.
        'cancelada' => ['Cancelada', 'control_presupuestal'],
    ];

    /**
     * Acción => estados de origen, estado nuevo, permiso y, si es un envío, de qué área a cuál (relación).
     * "recibe" marca la recepción en la relación con la que llegó; "observaciones" las exige; "pago" pide
     * fecha, forma y referencia.
     */
    public const ACCIONES = [
        // "propias": el área solo envía las CLC de su secretaría o dirección.
        'enviar_rm' => ['etiqueta' => 'Enviar a Recursos Materiales', 'de' => ['generada'], 'a' => 'enviada_rm', 'permiso' => 'requisiciones-clc', 'envio' => ['area', 'recursos_materiales'], 'relacion' => false, 'propias' => true],
        'recibir_rm' => ['etiqueta' => 'Recibir', 'de' => ['enviada_rm'], 'a' => 'recibida_rm', 'permiso' => 'clc-recursos-materiales', 'recibe' => true],
        'enviar_cp' => ['etiqueta' => 'Enviar a Control Presupuestal', 'de' => ['recibida_rm', 'devuelta'], 'a' => 'enviada_cp', 'permiso' => 'clc-recursos-materiales', 'envio' => ['recursos_materiales', 'control_presupuestal'], 'relacion' => false],
        'recibir_cp' => ['etiqueta' => 'Recibir', 'de' => ['enviada_cp'], 'a' => 'recibida_cp', 'permiso' => 'clc-control-presupuestal', 'recibe' => true],
        'devolver' => ['etiqueta' => 'Devolver a Recursos Materiales', 'de' => ['recibida_cp'], 'a' => 'devuelta', 'permiso' => 'clc-control-presupuestal', 'observaciones' => true],
        'enviar_sindicatura' => ['etiqueta' => 'Enviar a Sindicatura', 'de' => ['recibida_cp'], 'a' => 'enviada_sindicatura', 'permiso' => 'clc-control-presupuestal', 'envio' => ['control_presupuestal', 'sindicatura'], 'relacion' => true],
        'recibir_sindicatura' => ['etiqueta' => 'Recibir', 'de' => ['enviada_sindicatura'], 'a' => 'recibida_sindicatura', 'permiso' => 'clc-sindicatura', 'recibe' => true],
        'enviar_cp_firmada' => ['etiqueta' => 'Enviar a Control Presupuestal', 'de' => ['recibida_sindicatura'], 'a' => 'enviada_cp_firmada', 'permiso' => 'clc-sindicatura', 'envio' => ['sindicatura', 'control_presupuestal'], 'relacion' => true],
        'recibir_cp_firmada' => ['etiqueta' => 'Recibir', 'de' => ['enviada_cp_firmada'], 'a' => 'recibida_cp_firmada', 'permiso' => 'clc-control-presupuestal', 'recibe' => true],
        'enviar_egresos' => ['etiqueta' => 'Enviar a Egresos', 'de' => ['recibida_cp_firmada'], 'a' => 'enviada_egresos', 'permiso' => 'clc-control-presupuestal', 'envio' => ['control_presupuestal', 'egresos'], 'relacion' => false],
        'recibir_egresos' => ['etiqueta' => 'Recibir', 'de' => ['enviada_egresos'], 'a' => 'recibida_egresos', 'permiso' => 'clc-egresos', 'recibe' => true],
        'pagar' => ['etiqueta' => 'Marcar pagada', 'de' => ['recibida_egresos'], 'a' => 'pagada', 'permiso' => 'ordenes-compra-pagar', 'pago' => true],
    ];

    public static function descripcion(?string $estado): string
    {
        return self::ESTADOS[$estado][0] ?? '—';
    }

    /** Registra el inicio del trámite al generar la CLC: queda en el área para que la envíe a RM. */
    public function generada(OrdenCompra $orden, User $user): void
    {
        $orden->update(['clc_estado' => 'generada', 'clc_relacion_id' => null]);
        $this->movimiento($orden, 'generar', null, 'generada', null, null, $user);
    }

    /**
     * Pasos en que cada área puede cancelar la CLC (con el permiso clc-cancelar): el área hasta que
     * Recursos Materiales la recibe; Recursos Materiales hasta que la recibe Control Presupuestal; Control
     * Presupuestal hasta que la recibe Sindicatura. Después, solo Admin.
     */
    public const CANCELA_HASTA = [
        'area' => ['generada', 'enviada_rm'],
        'recursos_materiales' => ['generada', 'enviada_rm', 'recibida_rm', 'devuelta', 'enviada_cp'],
        'control_presupuestal' => ['generada', 'enviada_rm', 'recibida_rm', 'devuelta', 'enviada_cp', 'recibida_cp', 'enviada_sindicatura'],
    ];

    /** Por qué el usuario no puede cancelar la CLC en el paso en que está (null = sí puede). */
    public static function impedimentoParaCancelar(OrdenCompra $orden, User $user): ?string
    {
        if (! $orden->clcActiva()) {
            return 'Esta orden de compra no tiene una CLC vigente.';
        }
        if ($user->hasRole(['Super Admin', 'Admin'])) {
            return null;
        }
        if (! $user->can('clc-cancelar')) {
            return 'No tienes permiso para cancelar CLC.';
        }

        $paso = self::descripcion($orden->clc_estado);
        [$area, $mensaje] = match (true) {
            $user->can('clc-control-presupuestal') => ['control_presupuestal', "Ya llegó a Sindicatura ({$paso}): Control Presupuestal ya no puede cancelarla."],
            $user->can('clc-recursos-materiales') => ['recursos_materiales', "Control Presupuestal ya la recibió ({$paso}): Recursos Materiales ya no puede cancelarla."],
            $user->can('requisiciones-clc') => ['area', "Recursos Materiales ya la recibió ({$paso}): el área ya no puede cancelarla."],
            default => [null, 'No participas en el trámite de esta CLC.'],
        };
        if ($area === null) {
            return $mensaje;
        }
        if ($area === 'area') {
            $unidad = (int) $user->id_cat_egreso_unidad_administrativa;
            if (! $unidad || $unidad !== (int) $orden->requisicion?->id_cat_egreso_unidad_administrativa) {
                return 'Esta CLC no es de tu área.';
            }
        }

        return in_array($orden->clc_estado, self::CANCELA_HASTA[$area], true) ? null : $mensaje;
    }

    /**
     * Cancela la CLC en el paso en que esté (también pagada: el pago queda anulado y deja de contar como
     * pagado). Se conservan folio, pago y número de transferencia como historial.
     */
    public function cancelar(OrdenCompra $orden, User $user, string $motivo): void
    {
        $anterior = $orden->clc_estado;
        $orden->update([
            'clc_estado' => 'cancelada',
            'clc_relacion_id' => null,
            'clc_cancelada_at' => now(),
            'clc_cancelada_user_id' => $user->id,
            'clc_motivo_cancelacion' => $motivo,
        ]);
        $this->movimiento($orden, 'cancelar', $anterior, 'cancelada', null, $motivo, $user);
    }

    /** Admin y Control Presupuestal ven todas las bandejas y el seguimiento de todas las CLC. */
    public static function veTodo(User $user): bool
    {
        return $user->hasRole(['Super Admin', 'Admin']) || $user->can('clc-control-presupuestal');
    }

    /** Estados de las bandejas en que el usuario puede actuar (por sus permisos). */
    public static function estadosDeSusBandejas(User $user): array
    {
        return collect(self::ACCIONES)
            // Las del área (propias) no: esas solo se ven de su secretaría.
            ->filter(fn ($a) => ! ($a['propias'] ?? false) && $user->can($a['permiso']))
            ->flatMap(fn ($a) => $a['de'])
            ->unique()->values()->all();
    }

    /**
     * Ejecuta la acción sobre las CLC (ids de ordenes_compra). Devuelve la relación creada si fue un envío.
     *
     * @param  array<int>  $ids
     * @param  array{observaciones?: string|null, pago_fecha?: string, pago_forma?: string, pago_referencia?: string}  $datos
     */
    public function ejecutar(string $accion, array $ids, User $user, array $datos = []): ?ClcRelacion
    {
        $config = self::ACCIONES[$accion] ?? throw ValidationException::withMessages(['accion' => 'Acción no válida.']);

        if (! $user->can($config['permiso'])) {
            abort(403, 'No tienes permiso para esta acción del trámite de CLC.');
        }
        if (($config['observaciones'] ?? false) && blank($datos['observaciones'] ?? null)) {
            throw ValidationException::withMessages(['observaciones' => 'Escribe las observaciones de la devolución.']);
        }
        if ($config['pago'] ?? false) {
            $this->verificarFacturasEnSat($ids);
        }

        return DB::transaction(function () use ($accion, $config, $ids, $user, $datos) {
            /** @var Collection<int, OrdenCompra> $ordenes */
            $ordenes = OrdenCompra::with('requisicion')->whereIn('id', $ids)->whereNotNull('clc_folio_completo')->lockForUpdate()->get();
            if ($ordenes->isEmpty() || $ordenes->count() !== count(array_unique($ids))) {
                throw ValidationException::withMessages(['ordenes' => 'Selecciona al menos una CLC válida.']);
            }

            $fuera = $ordenes->reject(fn ($o) => in_array($o->clc_estado, $config['de'], true));
            if ($fuera->isNotEmpty()) {
                throw ValidationException::withMessages(['ordenes' => 'Estas CLC ya no están en el paso para "'.$config['etiqueta'].'": '
                    .$fuera->map(fn ($o) => "{$o->clc_folio_completo} (".self::descripcion($o->clc_estado).')')->implode(', ').'. Actualiza la página.']);
            }

            if ($config['propias'] ?? false) {
                $this->soloDeSuArea($ordenes, $user);
            }

            $relacion = ($config['relacion'] ?? false) ? $this->nuevaRelacion($config['envio'], $ordenes, $user, $datos['observaciones'] ?? null) : null;

            foreach ($ordenes as $orden) {
                $anterior = $orden->clc_estado;

                if ($config['recibe'] ?? false) {
                    if ($orden->clc_relacion_id) {
                        DB::table('clc_relacion_orden')
                            ->where('clc_relacion_id', $orden->clc_relacion_id)->where('orden_compra_id', $orden->id)
                            ->update(['recibida_at' => now(), 'recibida_user_id' => $user->id]);
                    }
                }

                $cambios = ['clc_estado' => $config['a']];
                if (isset($config['envio'])) {
                    // Envío sin documento (RM → CP, CP → Egresos): ya no hay relación pendiente de recibir.
                    $cambios['clc_relacion_id'] = $relacion?->id;
                }
                if ($config['pago'] ?? false) {
                    $cambios += $this->datosPago($orden, $datos, $user);
                }
                $orden->update($cambios);

                $this->movimiento($orden, $accion, $anterior, $config['a'], $relacion?->id ?? (($config['recibe'] ?? false) ? $orden->clc_relacion_id : null), $datos['observaciones'] ?? null, $user);

                if ($config['pago'] ?? false) {
                    $this->pagarRequisicion($orden, $user);
                }
            }

            return $relacion;
        });
    }

    /**
     * Antes de pagar se vuelve a consultar cada factura en el SAT (fuera de la transacción: es una llamada
     * externa). Una cancelada o no encontrada detiene el pago; si el SAT no responde, se deja pagar.
     *
     * @param  array<int>  $ids
     */
    private function verificarFacturasEnSat(array $ids): void
    {
        $sat = app(EstadoCfdiSat::class);
        $errores = [];

        foreach (OrdenCompra::whereIn('id', $ids)->whereNotNull('xml_uuid')->get() as $orden) {
            $estado = $sat->consultarOrden($orden);
            EstadoCfdiSat::guardar($orden, $estado);
            if ($estado && ($error = EstadoCfdiSat::error($estado, $orden->xml_uuid))) {
                $errores[] = "{$orden->clc_folio_completo}: {$error}";
            }
        }

        if ($errores) {
            throw ValidationException::withMessages(['ordenes' => 'No se puede pagar. '.implode(' ', $errores)]);
        }
    }

    /** El área solo envía las CLC de su secretaría o dirección (Admin, todas). */
    private function soloDeSuArea(Collection $ordenes, User $user): void
    {
        if ($user->hasRole(['Super Admin', 'Admin'])) {
            return;
        }

        $propias = EgresoUnidadAdministrativa::idsMismaClave($user->id_cat_egreso_unidad_administrativa);
        $ajenas = $ordenes->reject(fn ($o) => in_array((int) $o->requisicion?->id_cat_egreso_unidad_administrativa, $propias, true));
        if ($ajenas->isNotEmpty()) {
            abort(403, 'Solo puedes enviar las CLC de tu secretaría o dirección.');
        }
    }

    /** @param  array{0: string, 1: string}  $envio */
    private function nuevaRelacion(array $envio, Collection $ordenes, User $user, ?string $observaciones): ClcRelacion
    {
        $año = (int) now('America/Mexico_City')->format('Y');
        $folio = (ClcRelacion::where('año', $año)->lockForUpdate()->max('folio') ?? 0) + 1;

        $relacion = ClcRelacion::create([
            'folio' => $folio,
            'año' => $año,
            'folio_completo' => sprintf('REL-%d-%04d', $año, $folio),
            'origen' => $envio[0],
            'destino' => $envio[1],
            'observaciones' => $observaciones,
            'user_id' => $user->id,
        ]);
        $relacion->ordenes()->attach($ordenes->pluck('id'));

        return $relacion;
    }

    private function datosPago(OrdenCompra $orden, array $datos, User $user): array
    {
        $fecha = $datos['pago_fecha'] ?? null;
        $errores = [];
        if (! $fecha || ! strtotime($fecha)) {
            $errores['pago_fecha'] = 'Indica la fecha de pago.';
        } elseif ($fecha > now('America/Mexico_City')->toDateString()) {
            $errores['pago_fecha'] = 'La fecha de pago no puede ser futura.';
        } elseif ($orden->fecha && $fecha < $orden->fecha->toDateString()) {
            $errores['pago_fecha'] = "La fecha de pago no puede ser anterior a la orden {$orden->folio_completo}.";
        }
        if (! array_key_exists($datos['pago_forma'] ?? '', OrdenCompra::FORMAS_PAGO)) {
            $errores['pago_forma'] = 'Indica la forma de pago.';
        }
        // La transferencia lleva su número automático; el cheque, su número capturado.
        $esTransferencia = ($datos['pago_forma'] ?? null) === 'transferencia';
        if (! $esTransferencia && blank($datos['pago_referencia'] ?? null)) {
            $errores['pago_referencia'] = 'Indica el número de cheque.';
        }
        if ($errores) {
            throw ValidationException::withMessages($errores);
        }

        $referencia = trim((string) ($datos['pago_referencia'] ?? ''));

        return [
            'pago_fecha' => $fecha,
            'pago_forma' => $datos['pago_forma'],
            'pago_referencia' => $referencia === '' ? null : mb_substr($referencia, 0, 100),
            'pago_user_id' => $user->id,
            'pago_registrado_at' => now(),
            ...($esTransferencia ? $this->numeroTransferencia((int) substr($fecha, 0, 4)) : []),
        ];
    }

    /**
     * Siguiente número de transferencia del año del pago (TRF-2026-0001), para la posición bancaria.
     * Se calcula dentro de la transacción del pago con bloqueo, así que dos pagos no repiten número.
     */
    private function numeroTransferencia(int $año): array
    {
        $folio = (OrdenCompra::where('transferencia_año', $año)->lockForUpdate()->max('transferencia_folio') ?? 0) + 1;

        return [
            'transferencia_folio' => $folio,
            'transferencia_año' => $año,
            'transferencia_numero' => sprintf('TRF-%d-%04d', $año, $folio),
        ];
    }

    /** Con el pago la requisición queda Pagada y el importe pasa de devengado a pagado. */
    private function pagarRequisicion(OrdenCompra $orden, User $user): void
    {
        $requisicion = $orden->requisicion;
        if (! $requisicion) {
            return;
        }

        $anterior = $requisicion->id_cat_egreso_requisicion_estado;
        $requisicion->update(['id_cat_egreso_requisicion_estado' => EgresoRequisicion::PAGADA]);

        EgresoRequisicionBitacora::create([
            'id_tb_egreso_requisicion_bitacora' => (EgresoRequisicionBitacora::max('id_tb_egreso_requisicion_bitacora') ?? 0) + 1,
            'id_tb_egreso_requisicion' => $requisicion->id_tb_egreso_requisicion,
            'id_cat_egreso_requisicion_estado_anterior' => $anterior,
            'id_cat_egreso_requisicion_estado_nuevo' => EgresoRequisicion::PAGADA,
            'id_tb_usuarios' => 1,
            'observaciones' => sprintf('Pago de la orden %s (CLC %s) registrado por %s: %s %s del %s por $%s%s.',
                $orden->folio_completo, $orden->clc_folio_completo, $user->name, OrdenCompra::FORMAS_PAGO[$orden->pago_forma],
                trim($orden->transferencia_numero.' '.$orden->pago_referencia), $orden->pago_fecha->format('d/m/Y'), number_format($orden->netoAPagar(), 2),
                $orden->xml_isr > 0 ? sprintf(' (importe $%s menos ISR retenido $%s)', number_format($orden->importe, 2), number_format($orden->xml_isr, 2)) : ''),
        ]);
    }

    private function movimiento(OrdenCompra $orden, string $accion, ?string $anterior, string $nuevo, ?int $relacionId, ?string $observaciones, User $user): void
    {
        ClcMovimiento::create([
            'orden_compra_id' => $orden->id,
            'accion' => $accion,
            'estado_anterior' => $anterior,
            'estado_nuevo' => $nuevo,
            'clc_relacion_id' => $relacionId,
            'observaciones' => $observaciones,
            'user_id' => $user->id,
        ]);
    }
}
