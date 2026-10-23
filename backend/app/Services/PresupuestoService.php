<?php

namespace App\Services;

use App\Models\EgresoRequisicion;
use App\Models\EgresoUnidadAdministrativa;
use App\Models\ModificacionPresupuestal;
use App\Models\PresupuestoEgreso;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Saldos del presupuesto de egresos por línea (unidad|fuente|proyecto|partida):
 *
 *   vigente      = asignado (IMPORTE_TOTAL importado) ± modificaciones presupuestales autorizadas
 *   en trámite   = requisiciones no rechazadas que aún no tienen suficiencia
 *   comprometido = requisiciones con suficiencia cuya orden de compra aún no tiene factura
 *   devengado    = requisiciones cuya orden de compra ya tiene factura XML y aún no se paga
 *   pagado       = requisiciones cuya orden de compra ya tiene pago registrado
 *   disponible   = vigente - comprometido - devengado - pagado
 *
 * Cada importe está en un solo momento: al cargar la factura pasa de comprometido a devengado y al
 * registrar el pago, de devengado a pagado.
 */
class PresupuestoService
{
    public static function clave(?string $unidad, ?string $fuente, ?string $proyecto, ?string $partida): string
    {
        return implode('|', array_map(fn ($v) => trim((string) $v), [$unidad, $fuente, $proyecto, $partida]));
    }

    /**
     * Claves de unidad administrativa (CLAVE del presupuesto) que el usuario puede ver;
     * null = todas (Admin o quien autoriza modificaciones presupuestales).
     */
    public static function clavesUnidadVisibles($user): ?array
    {
        if ($user->hasRole(['Super Admin', 'Admin']) || $user->can('modificaciones-autorizar')) {
            return null;
        }

        $clave = $user->id_cat_egreso_unidad_administrativa
            ? EgresoUnidadAdministrativa::whereKey($user->id_cat_egreso_unidad_administrativa)->value('clave')
            : null;

        return $clave === null ? [] : [trim((string) $clave)];
    }

    /**
     * @param  array<string>|null  $clavesUnidad  null = todas las unidades
     * @param  int|null  $excluirRequisicion  requisición que no se cuenta (la que se está validando)
     */
    public function lineas(?array $clavesUnidad = null, ?int $excluirRequisicion = null): Collection
    {
        $presupuesto = PresupuestoEgreso::query()
            ->when($clavesUnidad !== null, fn ($q) => $q->whereIn('CLAVE', $clavesUnidad === [] ? ['__ninguna__'] : $clavesUnidad))
            ->orderBy('CLAVE')->orderBy('CLAVE3')->orderBy('CLAVE6')->orderBy('PARTIDA')
            ->get(['id_presupuesto', 'CLAVE', 'UNIDAD_ADMINISTRATIVA', 'CLAVE3', 'FUENTE_DE_FINANCIAMIENTO', 'CLAVE6', 'PROYECTO', 'PARTIDA', 'NOMBRE_PARTIDA', 'IMPORTE_TOTAL']);

        $modificado = $this->modificadoPorClave();
        [$comprometido, $enTramite, $devengado, $pagado] = $this->ejercidoPorClave($excluirRequisicion);

        return $presupuesto->map(function ($p) use ($modificado, $comprometido, $enTramite, $devengado, $pagado) {
            $clave = self::clave($p->CLAVE, $p->CLAVE3, $p->CLAVE6, $p->PARTIDA);
            $asignado = (float) $p->IMPORTE_TOTAL;
            $mod = $modificado[$clave] ?? 0.0;
            $vigente = $asignado + $mod;
            // Momentos acumulativos: el comprometido incluye todo lo comprometido (también lo ya devengado y
            // pagado), el devengado todo el historial de devengado (también lo pagado) y el pagado lo pagado.
            // El disponible descuenta solo el comprometido acumulado.
            $pag = $pagado[$clave] ?? 0.0;
            $dev = ($devengado[$clave] ?? 0.0) + $pag;
            $comp = ($comprometido[$clave] ?? 0.0) + $dev;

            return [
                'clave' => $clave,
                'unidad_clave' => trim((string) $p->CLAVE),
                'unidad' => trim((string) $p->UNIDAD_ADMINISTRATIVA),
                'fuente' => trim((string) $p->CLAVE3),
                'nombre_fuente' => trim((string) $p->FUENTE_DE_FINANCIAMIENTO),
                'proyecto' => trim((string) $p->CLAVE6),
                'nombre_proyecto' => trim((string) $p->PROYECTO),
                'partida' => trim((string) $p->PARTIDA),
                'nombre_partida' => trim((string) $p->NOMBRE_PARTIDA),
                'asignado' => round($asignado, 2),
                'modificado' => round($mod, 2),
                'vigente' => round($vigente, 2),
                'comprometido' => round($comp, 2),
                'en_tramite' => round($enTramite[$clave] ?? 0.0, 2),
                'devengado' => round($dev, 2),
                'pagado' => round($pag, 2),
                'disponible' => round($vigente - $comp, 2),
            ];
        })->values();
    }

    public function linea(string $clave, ?int $excluirRequisicion = null): ?array
    {
        $unidad = explode('|', $clave)[0];

        return $this->lineas([$unidad], $excluirRequisicion)->firstWhere('clave', $clave);
    }

    /**
     * Compara lo cotizado en la requisición contra el disponible de cada línea de presupuesto.
     *
     * @return array{suficiente: bool, total: float, partidas: array<int, array<string, mixed>>}
     */
    public function validarRequisicion(EgresoRequisicion $requisicion): array
    {
        $requisicion->loadMissing('tbEgresoRequisicionDetalles', 'catEgresoUnidadAdministrativa');
        $unidadClave = (string) $requisicion->catEgresoUnidadAdministrativa?->clave;

        $lineas = $this->lineas([$unidadClave], $requisicion->id_tb_egreso_requisicion)->keyBy('clave');

        $requerido = [];
        foreach ($requisicion->tbEgresoRequisicionDetalles as $d) {
            $clave = self::clave($unidadClave, $d->fuente, $d->proyecto, $d->partida);
            $requerido[$clave] = ($requerido[$clave] ?? 0) + (float) ($d->total ?? $d->sub_total ?? 0);
        }

        $partidas = [];
        $suficiente = $requerido !== [];
        foreach ($requerido as $clave => $monto) {
            $linea = $lineas[$clave] ?? null;
            [, $fuente, $proyecto, $partida] = explode('|', $clave);
            $ok = $linea !== null && round($monto, 2) <= $linea['disponible'];
            $suficiente = $suficiente && $ok;
            // Ya con suficiencia, su propio importe se muestra comprometido y descontado del disponible.
            $propio = $requisicion->suficiencia === true ? $monto : 0.0;

            $partidas[] = [
                'clave' => $clave,
                'fuente' => $fuente,
                'proyecto' => $proyecto,
                'partida' => $partida,
                'nombre_partida' => $linea['nombre_partida'] ?? null,
                'existe' => $linea !== null,
                'requerido' => round($monto, 2),
                'vigente' => $linea['vigente'] ?? 0,
                // Comprometido acumulado de la línea (ya incluye lo devengado y lo pagado).
                'comprometido' => round(($linea['comprometido'] ?? 0) + $propio, 2),
                'disponible' => round(($linea['disponible'] ?? 0) - $propio, 2),
                'faltante' => $ok ? 0 : round($monto - ($linea['disponible'] ?? 0), 2),
                'suficiente' => $ok,
            ];
        }

        return [
            'suficiente' => $suficiente,
            'total' => round(array_sum($requerido), 2),
            'partidas' => $partidas,
        ];
    }

    /** Suma neta de modificaciones autorizadas por línea (destino suma, origen resta). */
    private function modificadoPorClave(): array
    {
        $neto = [];
        foreach (ModificacionPresupuestal::where('estado', 'autorizada')->get() as $m) {
            if ($origen = $m->claveLinea('origen')) {
                $neto[$origen] = ($neto[$origen] ?? 0) - $m->importe;
            }
            if ($destino = $m->claveLinea('destino')) {
                $neto[$destino] = ($neto[$destino] ?? 0) + $m->importe;
            }
        }

        return $neto;
    }

    /**
     * Momentos del gasto por clave de línea, con los importes de los conceptos de cada requisición:
     * en trámite (sin suficiencia), comprometido (con suficiencia, sin factura), devengado (su orden
     * de compra ya tiene factura XML, sin pago) y pagado (su orden de compra ya tiene pago registrado).
     *
     * @return array{0: array<string, float>, 1: array<string, float>, 2: array<string, float>, 3: array<string, float>}
     */
    private function ejercidoPorClave(?int $excluirRequisicion): array
    {
        $conPago = Schema::hasColumn('ordenes_compra', 'pago_fecha');
        // Una orden de compra cancelada ya no devenga y una CLC cancelada ya no cuenta como pagada: el
        // importe regresa a comprometido hasta que se cancela la requisición.
        [$ocVigente, $clcVigente] = Schema::hasColumn('ordenes_compra', 'cancelada_at')
            ? [' AND oc.cancelada_at IS NULL', ' AND oc.clc_cancelada_at IS NULL'] : ['', ''];

        $filas = DB::table('tb_egreso_requisicion_detalle as d')
            ->join('tb_egreso_requisicion as r', 'r.id_tb_egreso_requisicion', '=', 'd.id_tb_egreso_requisicion')
            ->join('cat_egreso_unidad_administrativa as u', 'u.id_cat_egreso_unidad_administrativa', '=', 'r.id_cat_egreso_unidad_administrativa')
            ->leftJoin('ordenes_compra as oc', 'oc.id_tb_egreso_requisicion', '=', 'r.id_tb_egreso_requisicion')
            ->whereNotIn('r.id_cat_egreso_requisicion_estado', [4, EgresoRequisicion::CANCELADA]) // rechazada o cancelada no compromete presupuesto
            ->when($excluirRequisicion, fn ($q) => $q->where('r.id_tb_egreso_requisicion', '!=', $excluirRequisicion))
            ->selectRaw('u.clave, d.fuente, d.proyecto, d.partida, r.suficiencia,
                CASE WHEN oc.xml_uuid IS NOT NULL'.$ocVigente.' THEN 1 ELSE 0 END as devengado,
                '.($conPago ? 'CASE WHEN oc.pago_fecha IS NOT NULL'.$ocVigente.$clcVigente.' THEN 1 ELSE 0 END' : '0').' as pagado,
                SUM(COALESCE(d.total, d.sub_total, 0)) as monto')
            ->groupBy('u.clave', 'd.fuente', 'd.proyecto', 'd.partida', 'r.suficiencia', 'devengado', 'pagado')
            ->get();

        $comprometido = $enTramite = $devengado = $pagado = [];
        foreach ($filas as $f) {
            $clave = self::clave($f->clave, $f->fuente, $f->proyecto, $f->partida);
            $monto = (float) $f->monto;

            if ((int) $f->pagado === 1) {
                $pagado[$clave] = ($pagado[$clave] ?? 0) + $monto;
            } elseif ((int) $f->devengado === 1) {
                $devengado[$clave] = ($devengado[$clave] ?? 0) + $monto;
            } elseif ((int) $f->suficiencia === 1) {
                $comprometido[$clave] = ($comprometido[$clave] ?? 0) + $monto;
            } else {
                $enTramite[$clave] = ($enTramite[$clave] ?? 0) + $monto;
            }
        }

        return [$comprometido, $enTramite, $devengado, $pagado];
    }
}
