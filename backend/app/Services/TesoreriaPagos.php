<?php

namespace App\Services;

use App\Models\EgresoRequisicionBitacora;
use App\Models\OrdenCompra;
use App\Models\TesoreriaCuenta;
use App\Models\TesoreriaMovimiento;
use App\Models\TesoreriaPago;
use App\Models\TramiteServicioFactura;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Pago a proveedores (mesa de control de Egresos). Un pago (TRF-AÑO-0001) agrupa las CLC "En Egresos" de un mismo
 * beneficiario y sale de una cuenta del Ayuntamiento, por transferencia o cheque. Antes de pagar se vuelve a
 * consultar en el SAT cada factura (también las de trámites de servicios, fondos y combustible). Se concilia con el
 * cargo del estado de cuenta solo si el monto cuadra.
 */
class TesoreriaPagos
{
    /** Diferencia máxima entre el pago y el cargo del banco para conciliar (redondeo de centavos). */
    public const TOLERANCIA = 0.005;

    public function __construct(private EstadoCfdiSat $sat, private TramiteClc $tramite) {}

    /**
     * Beneficiario de la CLC para agruparla: el proveedor, o (sin factura / fondo revolvente) a favor de quien va.
     *
     * @return array{clave: string, proveedor_id: int|null, nombre: string}
     */
    public static function beneficiario(OrdenCompra $orden): array
    {
        return $orden->proveedor_id && ! $orden->sin_factura
            ? ['clave' => "p{$orden->proveedor_id}", 'proveedor_id' => (int) $orden->proveedor_id, 'nombre' => (string) $orden->proveedor?->nombre]
            : ['clave' => 'b'.mb_strtoupper(trim((string) $orden->aFavorDe())), 'proveedor_id' => null, 'nombre' => (string) $orden->aFavorDe()];
    }

    /**
     * Consulta en el SAT las facturas de la CLC: la de la orden de compra o, si salió de un trámite (servicios,
     * fondo o combustible), cada una del trámite. Guarda el estado y devuelve los problemas encontrados.
     *
     * @return array{vigente: bool, facturas: int, errores: string[], sin_respuesta: int}
     */
    public function verificarSat(OrdenCompra $orden): array
    {
        $errores = [];
        $sinRespuesta = 0;
        $facturas = 0;

        if ($orden->xml_uuid) {
            $facturas = 1;
            $estado = $this->sat->consultarOrden($orden);
            EstadoCfdiSat::guardar($orden, $estado);
            if ($estado === null) {
                $sinRespuesta++;
            } elseif ($e = EstadoCfdiSat::error($estado, $orden->xml_uuid)) {
                $errores[] = $e;
            }
        } elseif ($orden->tramite_servicio_id) {
            $receptor = (string) config('services.sat.rfc_receptor');
            foreach (TramiteServicioFactura::where('tramite_servicio_id', $orden->tramite_servicio_id)->get() as $f) {
                $facturas++;
                $xml = $f->xml_path && Storage::disk('local')->exists($f->xml_path) ? EstadoCfdiSat::datosDelXml(Storage::disk('local')->get($f->xml_path)) : [];
                $estado = $this->sat->consultar($f->rfc_emisor, ($xml['rfc_receptor'] ?? null) ?: ($f->rfc_receptor ?: $receptor), $f->total, $f->uuid, $xml['sello'] ?? null);
                if ($estado === null) {
                    $sinRespuesta++;

                    continue;
                }
                $f->update(['sat_estado' => $estado['estado'], 'sat_verificado_at' => now()]);
                if ($e = EstadoCfdiSat::error($estado, $f->uuid)) {
                    $errores[] = $e;
                }
            }
            if ($facturas && ! $sinRespuesta) {
                $orden->update(['sat_estado' => $errores ? null : EstadoCfdiSat::VIGENTE, 'sat_verificado_at' => now()]);
            }
        }

        return ['vigente' => ! $errores && ! $sinRespuesta && $facturas > 0, 'facturas' => $facturas, 'errores' => $errores, 'sin_respuesta' => $sinRespuesta];
    }

    /**
     * Paga las CLC: una por beneficiario. Todas deben estar "En Egresos". Si el SAT no responde se deja pagar; si
     * una factura está cancelada o no existe, no se paga ninguna.
     *
     * @param  array<int>  $ids
     * @param  array{cuenta_id?: int|null, pago_forma?: string, pago_fecha?: string, pago_referencia?: string|null, observaciones?: string|null}  $datos
     * @return Collection<int, TesoreriaPago>
     */
    public function pagar(array $ids, User $user, array $datos): Collection
    {
        $errores = $this->validarDatos($datos);
        if ($errores) {
            throw ValidationException::withMessages($errores);
        }

        // Fuera de la transacción: son llamadas externas.
        $problemas = [];
        foreach (OrdenCompra::whereIn('id', $ids)->get() as $orden) {
            foreach ($this->verificarSat($orden)['errores'] as $e) {
                $problemas[] = "{$orden->clc_folio_completo}: {$e}";
            }
        }
        if ($problemas) {
            throw ValidationException::withMessages(['ordenes' => 'No se puede pagar. '.implode(' ', $problemas)]);
        }

        return DB::transaction(function () use ($ids, $user, $datos) {
            $ordenes = OrdenCompra::with('requisicion', 'proveedor')->whereIn('id', $ids)->whereNotNull('clc_folio_completo')->lockForUpdate()->get();
            if ($ordenes->isEmpty() || $ordenes->count() !== count(array_unique($ids))) {
                throw ValidationException::withMessages(['ordenes' => 'Selecciona al menos una CLC válida.']);
            }
            $fuera = $ordenes->reject(fn ($o) => $o->clc_estado === 'recibida_egresos');
            if ($fuera->isNotEmpty()) {
                throw ValidationException::withMessages(['ordenes' => 'Estas CLC no están "En Egresos" (recibidas para pago): '
                    .$fuera->map(fn ($o) => "{$o->clc_folio_completo} (".TramiteClc::descripcion($o->clc_estado).')')->implode(', ').'. Actualiza la página.']);
            }
            $grupos = $ordenes->groupBy(fn ($o) => self::beneficiario($o)['clave']);
            if (($datos['pago_forma'] ?? '') === 'cheque' && $grupos->count() > 1) {
                throw ValidationException::withMessages(['ordenes' => 'Un cheque es para un solo beneficiario: selecciona CLC de un mismo proveedor.']);
            }

            return $grupos->map(fn ($grupo) => $this->crearPago($grupo, $user, $datos))->values();
        });
    }

    /** Cancela un pago no conciliado: sus CLC regresan a "En Egresos" y sus requisiciones a su estado anterior. */
    public function cancelar(TesoreriaPago $pago, User $user, string $motivo): void
    {
        DB::transaction(function () use ($pago, $user, $motivo) {
            $pago = TesoreriaPago::whereKey($pago->id)->lockForUpdate()->firstOrFail();
            if (! $pago->enTransito()) {
                throw ValidationException::withMessages(['pago' => $pago->estado === 'conciliado'
                    ? "El pago {$pago->numero} ya está conciliado con el banco: primero quita la conciliación."
                    : "El pago {$pago->numero} ya está cancelado."]);
            }
            foreach ($pago->ordenes()->with('requisicion')->get() as $orden) {
                $orden->update([
                    'clc_estado' => 'recibida_egresos', 'tesoreria_pago_id' => null,
                    'pago_fecha' => null, 'pago_forma' => null, 'pago_referencia' => null, 'pago_user_id' => null, 'pago_registrado_at' => null,
                    'transferencia_folio' => null, 'transferencia_año' => null, 'transferencia_numero' => null,
                ]);
                $this->tramite->movimiento($orden, 'cancelar_pago', 'pagada', 'recibida_egresos', null, "Pago {$pago->numero} cancelado: {$motivo}", $user);
                $this->regresarRequisicion($orden, (int) $orden->pivot->requisicion_estado_anterior, "Pago {$pago->numero} cancelado por {$user->name}: {$motivo}");
            }
            $pago->update(['estado' => 'cancelado', 'cancelado_at' => now(), 'motivo_cancelacion' => $motivo]);
        });
    }

    /**
     * Concilia el pago con el cargo del estado de cuenta. Si los montos no cuadran no se cierra: es la alerta de la
     * propuesta (se revisa el pago o el movimiento).
     */
    public function conciliar(TesoreriaPago $pago, TesoreriaMovimiento $movimiento, User $user): void
    {
        DB::transaction(function () use ($pago, $movimiento, $user) {
            $pago = TesoreriaPago::whereKey($pago->id)->lockForUpdate()->firstOrFail();
            $movimiento = TesoreriaMovimiento::whereKey($movimiento->id)->lockForUpdate()->firstOrFail();
            $errores = [];
            if (! $pago->enTransito()) {
                $errores[] = "El pago {$pago->numero} está ".mb_strtolower($pago->estadoTexto()).'.';
            }
            if ($movimiento->tipo !== 'cargo') {
                $errores[] = 'Un pago se concilia con un cargo, no con un abono.';
            }
            if ($movimiento->tesoreria_pago_id) {
                $errores[] = 'Ese movimiento ya está conciliado con '.($movimiento->pago?->numero ?? 'otro pago').'.';
            }
            if ((int) $movimiento->cuenta_id !== (int) $pago->cuenta_id) {
                $errores[] = 'El movimiento es de otra cuenta bancaria que la del pago.';
            }
            if (! $errores && abs($movimiento->importe - $pago->importe) > self::TOLERANCIA) {
                $errores[] = sprintf('Los montos no cuadran: el pago %s es por $%s y el cargo del banco por $%s (diferencia de $%s). Revisa el pago o el movimiento antes de cerrar.',
                    $pago->numero, number_format($pago->importe, 2), number_format($movimiento->importe, 2), number_format($movimiento->importe - $pago->importe, 2));
            }
            if ($errores) {
                throw ValidationException::withMessages(['conciliacion' => implode(' ', $errores)]);
            }

            $movimiento->update(['tesoreria_pago_id' => $pago->id]);
            $pago->update(['estado' => 'conciliado', 'conciliado_at' => now(), 'conciliado_user_id' => $user->id]);
        });
    }

    /** Quita la conciliación (p. ej. se ligó el movimiento equivocado): el pago vuelve a estar en tránsito. */
    public function desconciliar(TesoreriaPago $pago): void
    {
        DB::transaction(function () use ($pago) {
            $pago->movimientos()->update(['tesoreria_pago_id' => null]);
            $pago->update(['estado' => $pago->entregado_at ? 'entregado' : 'pagado', 'conciliado_at' => null, 'conciliado_user_id' => null]);
        });
    }

    /**
     * Cargos sin conciliar de la cuenta que podrían ser el pago: primero los que traen su número (TRF o cheque) en la
     * referencia, la clave de rastreo o el concepto; luego los del mismo monto.
     *
     * @return Collection<int, TesoreriaMovimiento>
     */
    public function candidatos(TesoreriaPago $pago): Collection
    {
        $claves = array_filter([$pago->numero, $pago->cheque_numero]);

        return TesoreriaMovimiento::where('cuenta_id', $pago->cuenta_id)->where('tipo', 'cargo')->whereNull('tesoreria_pago_id')
            ->where(fn ($q) => $q->whereBetween('importe', [$pago->importe - 0.01, $pago->importe + 0.01])
                ->orWhere(function ($q) use ($claves) {
                    foreach ($claves as $c) {
                        foreach (['referencia', 'clave_rastreo', 'concepto'] as $campo) {
                            $q->orWhere($campo, 'like', '%'.$c.'%');
                        }
                    }
                }))
            ->orderBy('fecha')->get()
            ->sortByDesc(fn ($m) => (int) $this->traeNumero($m, $claves) * 2 + (int) (abs($m->importe - $pago->importe) <= self::TOLERANCIA))->values();
    }

    /**
     * Concilia solos los pagos en tránsito que tienen un único cargo con su número y el mismo monto.
     *
     * @return array{conciliados: string[], con_diferencia: string[]}
     */
    public function conciliarAutomatico(User $user, ?int $cuentaId = null): array
    {
        $conciliados = [];
        $diferencia = [];
        $pagos = TesoreriaPago::whereIn('estado', ['pagado', 'entregado'])->when($cuentaId, fn ($q) => $q->where('cuenta_id', $cuentaId))->get();
        foreach ($pagos as $pago) {
            $claves = array_filter([$pago->numero, $pago->cheque_numero]);
            $conNumero = $this->candidatos($pago)->filter(fn ($m) => $this->traeNumero($m, $claves));
            if ($conNumero->count() !== 1) {
                continue;
            }
            $movimiento = $conNumero->first();
            if (abs($movimiento->importe - $pago->importe) > self::TOLERANCIA) {
                $diferencia[] = sprintf('%s: el banco cargó $%s y el pago es por $%s', $pago->numero, number_format($movimiento->importe, 2), number_format($pago->importe, 2));

                continue;
            }
            $this->conciliar($pago, $movimiento, $user);
            $conciliados[] = $pago->numero;
        }

        return ['conciliados' => $conciliados, 'con_diferencia' => $diferencia];
    }

    // ----------------------------------------------------------------

    private function validarDatos(array $datos): array
    {
        $errores = [];
        $fecha = $datos['pago_fecha'] ?? null;
        if (! $fecha || ! strtotime($fecha)) {
            $errores['pago_fecha'] = 'Indica la fecha de pago.';
        } elseif ($fecha > now('America/Mexico_City')->toDateString()) {
            $errores['pago_fecha'] = 'La fecha de pago no puede ser futura.';
        }
        if (! array_key_exists($datos['pago_forma'] ?? '', TesoreriaPago::FORMAS)) {
            $errores['pago_forma'] = 'Indica la forma de pago.';
        }
        if (($datos['pago_forma'] ?? null) === 'cheque' && blank($datos['pago_referencia'] ?? null)) {
            $errores['pago_referencia'] = 'Indica el número de cheque.';
        }
        $cuenta = filled($datos['cuenta_id'] ?? null) ? TesoreriaCuenta::find($datos['cuenta_id']) : null;
        if (! $cuenta || ! $cuenta->activo) {
            $errores['cuenta_id'] = TesoreriaCuenta::where('activo', true)->exists()
                ? 'Elige la cuenta bancaria del Ayuntamiento de la que sale el pago.'
                : 'No hay cuentas bancarias del Ayuntamiento: dalas de alta en Catálogos > Cuentas bancarias del Ayuntamiento.';
        }

        return $errores;
    }

    /** @param  Collection<int, OrdenCompra>  $ordenes  Todas del mismo beneficiario. */
    private function crearPago(Collection $ordenes, User $user, array $datos): TesoreriaPago
    {
        $primera = $ordenes->first();
        $beneficiario = self::beneficiario($primera);
        $fecha = $datos['pago_fecha'];
        $esCheque = $datos['pago_forma'] === 'cheque';
        foreach ($ordenes as $orden) {
            if ($orden->fecha && $fecha < $orden->fecha->toDateString()) {
                throw ValidationException::withMessages(['pago_fecha' => "La fecha de pago no puede ser anterior a la orden {$orden->folio_completo}."]);
            }
        }

        $año = (int) substr($fecha, 0, 4);
        // Los TRF que ya se dieron uno por CLC (antes de Tesorería) también cuentan, para no repetir números.
        $folio = max(
            TesoreriaPago::where('año', $año)->lockForUpdate()->max('folio') ?? 0,
            OrdenCompra::where('transferencia_año', $año)->max('transferencia_folio') ?? 0,
        ) + 1;
        $numero = sprintf('TRF-%d-%04d', $año, $folio);
        $cuentaDestino = $beneficiario['proveedor_id'] ? $primera->proveedor?->clabe ?: $primera->proveedor?->cuenta_bancaria : null;

        $pago = TesoreriaPago::create([
            'folio' => $folio,
            'año' => $año,
            'numero' => $numero,
            'cuenta_id' => $datos['cuenta_id'],
            'proveedor_id' => $beneficiario['proveedor_id'],
            'beneficiario' => mb_substr($beneficiario['nombre'] ?: 'SIN BENEFICIARIO', 0, 300),
            'forma' => $datos['pago_forma'],
            'fecha' => $fecha,
            'importe' => round($ordenes->sum(fn ($o) => $o->netoAPagar()), 2),
            'cuenta_destino' => $esCheque ? null : $cuentaDestino,
            'cheque_numero' => $esCheque ? mb_substr(trim((string) $datos['pago_referencia']), 0, 30) : null,
            'estado' => 'pagado',
            'observaciones' => filled($datos['observaciones'] ?? null) ? trim($datos['observaciones']) : null,
            'user_id' => $user->id,
        ]);

        foreach ($ordenes as $orden) {
            $pago->ordenes()->attach($orden->id, ['importe' => $orden->netoAPagar(), 'requisicion_estado_anterior' => $orden->requisicion?->id_cat_egreso_requisicion_estado]);
            $orden->update([
                'clc_estado' => 'pagada',
                'tesoreria_pago_id' => $pago->id,
                'pago_fecha' => $fecha,
                'pago_forma' => $datos['pago_forma'],
                'pago_referencia' => $esCheque ? $pago->cheque_numero : (filled($datos['pago_referencia'] ?? null) ? mb_substr(trim($datos['pago_referencia']), 0, 100) : null),
                'pago_user_id' => $user->id,
                'pago_registrado_at' => now(),
                // El número interno del pago, el mismo para todas sus CLC (antes era uno por CLC).
                ...($esCheque ? [] : ['transferencia_folio' => $folio, 'transferencia_año' => $año, 'transferencia_numero' => $numero]),
            ]);
            $this->tramite->movimiento($orden, 'pagar', 'recibida_egresos', 'pagada', null, "Pago {$numero} ({$ordenes->count()} CLC, $".number_format($pago->importe, 2).')', $user);
            $this->tramite->pagarRequisicion($orden->fresh('requisicion'), $user);
        }

        return $pago;
    }

    private function regresarRequisicion(OrdenCompra $orden, int $estado, string $observaciones): void
    {
        $requisicion = $orden->requisicion;
        if (! $requisicion || ! $estado) {
            return;
        }
        $anterior = (int) $requisicion->id_cat_egreso_requisicion_estado;
        $requisicion->update(['id_cat_egreso_requisicion_estado' => $estado]);
        EgresoRequisicionBitacora::create([
            'id_tb_egreso_requisicion_bitacora' => (EgresoRequisicionBitacora::max('id_tb_egreso_requisicion_bitacora') ?? 0) + 1,
            'id_tb_egreso_requisicion' => $requisicion->id_tb_egreso_requisicion,
            'id_cat_egreso_requisicion_estado_anterior' => $anterior,
            'id_cat_egreso_requisicion_estado_nuevo' => $estado,
            'id_tb_usuarios' => 1,
            'observaciones' => $observaciones,
        ]);
    }

    /** @param  string[]  $claves */
    private function traeNumero(TesoreriaMovimiento $m, array $claves): bool
    {
        $texto = mb_strtoupper(($m->referencia ?? '').' '.($m->clave_rastreo ?? '').' '.($m->concepto ?? ''));

        return collect($claves)->contains(fn ($c) => str_contains($texto, mb_strtoupper($c)));
    }
}
