<?php

namespace App\Services;

use App\Models\CorridaSaldo;
use App\Models\EgresoRequisicion;
use App\Models\ModificacionPresupuestal;
use App\Models\PresupuestoEgreso;
use App\Models\SaldoMensual;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Saldos mensuales del presupuesto por línea (unidad|fuente|proyecto|partida):
 *
 *   disponible del mes = saldo corrido + asignado del mes + modificado del mes − comprometido del mes
 *
 * - Asignado del mes: el calendario autorizado (columnas ENERO…DIC).
 * - Modificado del mes: modificaciones autorizadas, en el periodo (mes) que indica cada lado (origen /
 *   destino); las anteriores al periodo, en el mes en que se autorizaron.
 * - Comprometido del mes: requisiciones con suficiencia validada en el mes (fecha de suficiencia).
 *   Al cancelarse una requisición comprometida, su importe se libera en el mes de la cancelación
 *   (el mes vigente en ese momento), no en el mes en que se comprometió.
 * - Saldo corrido: lo que quedó disponible el mes anterior (puede ser negativo si se excedió).
 *   Al "correr saldos" (el día 1 de cada mes) se congela en saldos_mensuales; los meses sin
 *   corrida se calculan con los movimientos. Enero empieza en cero.
 */
final class SaldosMensuales
{
    public const ZONA = 'America/Mexico_City';

    public const MESES = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    /** @var array<int, array<string, float>>|null saldo_anterior congelado por mes y clave */
    private ?array $congelados = null;

    private ?int $añoCongelados = null;

    /**
     * Movimientos por clave y mes del ejercicio.
     *
     * @return array<string, array<int, array{asignado: float, modificado: float, comprometido: float}>>
     */
    public function movimientos(int $año): array
    {
        $movimientos = [];
        $sumar = function (string $clave, int $mes, string $concepto, float $importe) use (&$movimientos) {
            $movimientos[$clave][$mes] ??= ['asignado' => 0.0, 'modificado' => 0.0, 'comprometido' => 0.0];
            $movimientos[$clave][$mes][$concepto] += $importe;
        };

        $columnas = array_keys(DistribucionMensual::MESES);

        foreach (PresupuestoEgreso::get(['CLAVE', 'CLAVE3', 'CLAVE6', 'PARTIDA', ...$columnas]) as $linea) {
            $clave = PresupuestoService::clave($linea->CLAVE, $linea->CLAVE3, $linea->CLAVE6, $linea->PARTIDA);
            $movimientos[$clave] ??= [];

            foreach ($columnas as $i => $columna) {
                $sumar($clave, $i + 1, 'asignado', (float) $linea->{$columna});
            }
        }

        foreach (ModificacionPresupuestal::where('estado', 'autorizada')->whereNotNull('resuelto_at')->get() as $m) {
            $fecha = $m->resuelto_at->setTimezone(self::ZONA);

            foreach (['origen' => -1, 'destino' => 1] as $lado => $signo) {
                if (! $clave = $m->claveLinea($lado)) {
                    continue;
                }

                // Con periodo, el mes es el elegido en el ejercicio de la modificación.
                [$añoMov, $mes] = $m->{"{$lado}_mes"} ? [(int) $m->año, $m->{"{$lado}_mes"}] : [$fecha->year, $fecha->month];

                if ($añoMov === $año) {
                    $sumar($clave, $mes, 'modificado', $signo * (float) $m->importe);
                }
            }
        }

        if (Schema::hasTable('tb_egreso_requisicion')) {
            $conceptos = DB::table('tb_egreso_requisicion_detalle as d')
                ->join('tb_egreso_requisicion as r', 'r.id_tb_egreso_requisicion', '=', 'd.id_tb_egreso_requisicion')
                ->join('cat_egreso_unidad_administrativa as u', 'u.id_cat_egreso_unidad_administrativa', '=', 'r.id_cat_egreso_unidad_administrativa')
                ->where('r.suficiencia', 1)
                ->where('r.id_cat_egreso_requisicion_estado', '!=', 4)
                ->whereNotNull('r.suficiencia_fecha')
                ->get(['u.clave', 'd.fuente', 'd.proyecto', 'd.partida', 'r.suficiencia_fecha', 'r.id_cat_egreso_requisicion_estado as estado', 'r.cancelada_at', DB::raw('COALESCE(d.total, d.sub_total, 0) as monto')]);

            foreach ($conceptos as $c) {
                $clave = PresupuestoService::clave($c->clave, $c->fuente, $c->proyecto, $c->partida);
                // suficiencia_fecha y cancelada_at las escribe Laravel (UTC); el mes se toma en hora local.
                $fecha = Carbon::parse($c->suficiencia_fecha, 'UTC')->setTimezone(self::ZONA);

                if ($fecha->year === $año) {
                    $sumar($clave, $fecha->month, 'comprometido', (float) $c->monto);
                }

                // Cancelada: lo comprometido regresa al disponible del mes en que se canceló.
                if ((int) $c->estado === EgresoRequisicion::CANCELADA) {
                    $cancelada = $c->cancelada_at ? Carbon::parse($c->cancelada_at, 'UTC')->setTimezone(self::ZONA) : $fecha;

                    if ($cancelada->year === $año) {
                        $sumar($clave, $cancelada->month, 'comprometido', -(float) $c->monto);
                    }
                }
            }
        }

        return $movimientos;
    }

    /** Lo que deja un mes: asignado + modificado − comprometido. */
    private function neto(array $movimientos, string $clave, int $mes): float
    {
        $m = $movimientos[$clave][$mes] ?? null;

        return $m ? $m['asignado'] + $m['modificado'] - $m['comprometido'] : 0.0;
    }

    /**
     * Saldo corrido con que empieza el mes: el congelado si ese mes ya se corrió; si no, el del mes
     * anterior más lo que dejó ese mes.
     */
    public function saldoCorrido(int $año, int $mes, string $clave, array $movimientos): float
    {
        $congelados = $this->congelados($año);

        if (isset($congelados[$mes])) {
            return $congelados[$mes][$clave] ?? 0.0;
        }

        if ($mes <= 1) {
            return 0.0;
        }

        return $this->saldoCorrido($año, $mes - 1, $clave, $movimientos) + $this->neto($movimientos, $clave, $mes - 1);
    }

    /** @return array<int, array<string, float>> */
    private function congelados(int $año): array
    {
        if ($this->añoCongelados !== $año) {
            $this->añoCongelados = $año;
            $this->congelados = [];

            foreach (CorridaSaldo::where('ejercicio', $año)->pluck('mes') as $mes) {
                $this->congelados[$mes] = [];
            }

            foreach (SaldoMensual::where('ejercicio', $año)->get(['mes', 'clave', 'saldo_anterior']) as $s) {
                $this->congelados[$s->mes][$s->clave] = $s->saldo_anterior;
            }
        }

        return $this->congelados;
    }

    /**
     * Corre los saldos al mes indicado: congela, por línea, lo que quedó disponible en el mes anterior.
     * Una sola vez por mes salvo que se fuerce (vuelve a calcularla con los movimientos actuales).
     */
    public function correr(int $año, int $mes, string $origen, ?int $userId = null, bool $forzar = false): CorridaSaldo
    {
        $existente = CorridaSaldo::where('ejercicio', $año)->where('mes', $mes)->first();

        if ($existente && ! $forzar) {
            throw new RuntimeException('Los saldos de '.self::MESES[$mes]." {$año} ya se corrieron el ".$existente->created_at->setTimezone(self::ZONA)->format('d/m/Y H:i').'.');
        }

        return DB::transaction(function () use ($año, $mes, $origen, $userId, $existente) {
            if ($existente) {
                $existente->saldos()->delete();
            }

            // Sin el mes que se recalcula: se corre desde lo congelado de los meses anteriores.
            $this->añoCongelados = null;
            $this->congelados($año);
            unset($this->congelados[$mes]);

            $movimientos = $this->movimientos($año);
            $filas = [];
            $ahora = now();

            foreach (array_keys($movimientos) as $clave) {
                $anterior = $movimientos[$clave][$mes - 1] ?? ['asignado' => 0.0, 'modificado' => 0.0, 'comprometido' => 0.0];

                $filas[] = [
                    'ejercicio' => $año,
                    'mes' => $mes,
                    'clave' => mb_substr($clave, 0, 150),
                    'saldo_anterior' => round($this->saldoCorrido($año, $mes, $clave, $movimientos), 2),
                    'asignado_mes_anterior' => $mes > 1 ? round($anterior['asignado'], 2) : 0,
                    'modificado_mes_anterior' => $mes > 1 ? round($anterior['modificado'], 2) : 0,
                    'comprometido_mes_anterior' => $mes > 1 ? round($anterior['comprometido'], 2) : 0,
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ];
            }

            $datos = [
                'origen' => $origen,
                'user_id' => $userId,
                'lineas' => count($filas),
                'total_corrido' => round(array_sum(array_column($filas, 'saldo_anterior')), 2),
            ];

            $corrida = $existente
                ? tap($existente)->update($datos)
                : CorridaSaldo::create(['ejercicio' => $año, 'mes' => $mes, ...$datos]);

            foreach (array_chunk($filas, 500) as $bloque) {
                SaldoMensual::insert(array_map(fn ($f) => ['corrida_saldo_id' => $corrida->id, ...$f], $bloque));
            }

            $this->añoCongelados = null;

            return $corrida;
        });
    }

    /**
     * Disponible de cada mes ($desde…diciembre) de las líneas indicadas.
     *
     * @param  array<string>  $claves
     * @return array<string, array<int, float>>
     */
    public function disponiblesPorMes(int $año, array $claves, int $desde = 1): array
    {
        $movimientos = $this->movimientos($año);
        $disponibles = [];

        foreach ($claves as $clave) {
            for ($mes = $desde; $mes <= 12; $mes++) {
                $disponibles[$clave][$mes] = round($this->saldoCorrido($año, $mes, $clave, $movimientos) + $this->neto($movimientos, $clave, $mes), 2);
            }
        }

        return $disponibles;
    }

    /**
     * Situación de cada línea en el mes: saldo corrido, movimientos del mes y disponible del mes.
     *
     * @return array<string, array{saldo_corrido: float, asignado_mes: float, modificado_mes: float, comprometido_mes: float, disponible_mes: float}>
     */
    public function situacion(int $año, int $mes): array
    {
        $movimientos = $this->movimientos($año);
        $situacion = [];

        foreach (array_keys($movimientos) as $clave) {
            $delMes = $movimientos[$clave][$mes] ?? ['asignado' => 0.0, 'modificado' => 0.0, 'comprometido' => 0.0];
            $saldo = $this->saldoCorrido($año, $mes, $clave, $movimientos);

            $situacion[$clave] = [
                'saldo_corrido' => round($saldo, 2),
                'asignado_mes' => round($delMes['asignado'], 2),
                'modificado_mes' => round($delMes['modificado'], 2),
                'comprometido_mes' => round($delMes['comprometido'], 2),
                'disponible_mes' => round($saldo + $delMes['asignado'] + $delMes['modificado'] - $delMes['comprometido'], 2),
            ];
        }

        return $situacion;
    }
}
