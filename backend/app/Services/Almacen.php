<?php

namespace App\Services;

use App\Models\AlmacenEntrada;
use App\Models\AlmacenSalida;
use App\Models\OrdenCompra;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Almacén conforme a la orden de compra: Recursos Materiales la asigna; el almacén registra a mano lo que
 * recibe (entradas) y lo que entrega al área (salidas), las dos por partes. Por cada concepto de la
 * requisición: pedido (cotizado), recibido, entregado, por recibir y existencia.
 */
class Almacen
{
    private const EPSILON = 0.000001;

    /**
     * Situación de cada concepto de la orden de compra.
     *
     * @return Collection<int, array{detalle_id: int, descripcion: string, pedido: float, precio_unitario: float, recibido: float, entregado: float, por_recibir: float, existencia: float}>
     */
    public function conceptos(OrdenCompra $orden): Collection
    {
        $detalles = $orden->requisicion?->tbEgresoRequisicionDetalles ?? collect();
        $ids = $detalles->pluck('id_tb_egreso_requisicion_detalle');

        $recibido = DB::table('almacen_entrada_detalles as d')->join('almacen_entradas as e', 'e.id', '=', 'd.almacen_entrada_id')
            ->where('e.orden_compra_id', $orden->id)->whereIn('d.id_tb_egreso_requisicion_detalle', $ids)
            ->groupBy('d.id_tb_egreso_requisicion_detalle')->selectRaw('d.id_tb_egreso_requisicion_detalle as id, SUM(d.cantidad) as total')->pluck('total', 'id');
        $entregado = DB::table('almacen_salida_detalles as d')->join('almacen_salidas as s', 's.id', '=', 'd.almacen_salida_id')
            ->where('s.orden_compra_id', $orden->id)->whereIn('d.id_tb_egreso_requisicion_detalle', $ids)
            ->groupBy('d.id_tb_egreso_requisicion_detalle')->selectRaw('d.id_tb_egreso_requisicion_detalle as id, SUM(d.cantidad) as total')->pluck('total', 'id');

        return $detalles->map(function ($d) use ($recibido, $entregado) {
            $id = (int) $d->id_tb_egreso_requisicion_detalle;
            $pedido = (float) $d->cantidad;
            $rec = (float) ($recibido[$id] ?? 0);
            $ent = (float) ($entregado[$id] ?? 0);

            return [
                'detalle_id' => $id,
                'descripcion' => (string) ($d->descripcion ?: $d->articulo),
                'pedido' => $pedido,
                'precio_unitario' => (float) $d->precio_unitario,
                'recibido' => $rec,
                'entregado' => $ent,
                'por_recibir' => max(0, round($pedido - $rec, 2)),
                'existencia' => round($rec - $ent, 2),
            ];
        })->values();
    }

    /** Recursos Materiales asigna la orden de compra al almacén para que reciba los bienes. */
    public function asignar(OrdenCompra $orden, User $user): void
    {
        if ($orden->cancelada_at) {
            throw ValidationException::withMessages(['orden' => 'La orden de compra está cancelada.']);
        }
        if ($orden->almacen_asignada_at) {
            throw ValidationException::withMessages(['orden' => 'La orden de compra ya está asignada al almacén.']);
        }

        $orden->update(['almacen_asignada_at' => now(), 'almacen_asignada_user_id' => $user->id]);
    }

    /**
     * Entrada manual: lo que se recibe de cada concepto (hasta lo que falta por recibir).
     *
     * @param  array<int|string, mixed>  $cantidades  id de concepto => cantidad
     */
    public function registrarEntrada(OrdenCompra $orden, User $user, string $fecha, ?string $observaciones, array $cantidades): AlmacenEntrada
    {
        $this->exigirAsignada($orden);

        return DB::transaction(function () use ($orden, $user, $fecha, $observaciones, $cantidades) {
            OrdenCompra::whereKey($orden->id)->lockForUpdate()->first();
            $renglones = $this->renglones($this->conceptos($orden), $cantidades, 'por_recibir', 'Por recibir');

            $año = (int) substr($fecha, 0, 4);
            $folio = (AlmacenEntrada::where('año', $año)->lockForUpdate()->max('folio') ?? 0) + 1;
            $entrada = AlmacenEntrada::create([
                'orden_compra_id' => $orden->id, 'folio' => $folio, 'año' => $año, 'folio_completo' => sprintf('EA-%d-%04d', $año, $folio),
                'fecha' => $fecha, 'observaciones' => $observaciones, 'user_id' => $user->id,
            ]);
            foreach ($renglones as $r) {
                $entrada->detalles()->create([
                    'id_tb_egreso_requisicion_detalle' => $r['detalle_id'], 'descripcion' => $r['descripcion'],
                    'cantidad' => $r['cantidad'], 'precio_unitario' => $r['precio_unitario'],
                ]);
            }

            return $entrada;
        });
    }

    /**
     * Salida al área de la requisición: lo que se entrega de cada concepto (hasta su existencia).
     *
     * @param  array<int|string, mixed>  $cantidades  id de concepto => cantidad
     */
    public function registrarSalida(OrdenCompra $orden, User $user, string $fecha, ?string $recibe, ?string $observaciones, array $cantidades): AlmacenSalida
    {
        $this->exigirAsignada($orden);

        return DB::transaction(function () use ($orden, $user, $fecha, $recibe, $observaciones, $cantidades) {
            OrdenCompra::whereKey($orden->id)->lockForUpdate()->first();
            $renglones = $this->renglones($this->conceptos($orden), $cantidades, 'existencia', 'En existencia');

            $año = (int) substr($fecha, 0, 4);
            $folio = (AlmacenSalida::where('año', $año)->lockForUpdate()->max('folio') ?? 0) + 1;
            $salida = AlmacenSalida::create([
                'orden_compra_id' => $orden->id, 'folio' => $folio, 'año' => $año, 'folio_completo' => sprintf('SA-%d-%04d', $año, $folio),
                'fecha' => $fecha, 'id_cat_egreso_unidad_administrativa' => $orden->requisicion?->id_cat_egreso_unidad_administrativa,
                'recibe' => $recibe, 'observaciones' => $observaciones, 'user_id' => $user->id,
            ]);
            foreach ($renglones as $r) {
                $salida->detalles()->create([
                    'id_tb_egreso_requisicion_detalle' => $r['detalle_id'], 'descripcion' => $r['descripcion'], 'cantidad' => $r['cantidad'],
                ]);
            }

            return $salida;
        });
    }

    private function exigirAsignada(OrdenCompra $orden): void
    {
        if ($orden->cancelada_at) {
            throw ValidationException::withMessages(['cantidades' => 'La orden de compra está cancelada.']);
        }
        if (! $orden->almacen_asignada_at) {
            throw ValidationException::withMessages(['cantidades' => 'Recursos Materiales aún no asigna esta orden de compra al almacén.']);
        }
    }

    /**
     * Renglones con cantidad > 0, sin pasar del límite de cada concepto ($campo: por_recibir o existencia).
     *
     * @return list<array{detalle_id: int, descripcion: string, precio_unitario: float, cantidad: float}>
     */
    private function renglones(Collection $conceptos, array $cantidades, string $campo, string $etiqueta): array
    {
        $renglones = [];
        $errores = [];

        foreach ($conceptos as $c) {
            $valor = $cantidades[$c['detalle_id']] ?? null;
            if ($valor === null || $valor === '') {
                continue;
            }
            if (! is_numeric($valor) || (float) $valor < 0) {
                $errores[] = "{$c['descripcion']}: cantidad no válida.";

                continue;
            }

            $cantidad = round((float) $valor, 2);
            if ($cantidad <= 0) {
                continue;
            }
            if ($cantidad - $c[$campo] > self::EPSILON) {
                $errores[] = sprintf('%s: %s %s, pero %s solo %s.', $c['descripcion'], $campo === 'existencia' ? 'se entregan' : 'se reciben',
                    rtrim(rtrim(number_format($cantidad, 2, '.', ''), '0'), '.'), mb_strtolower($etiqueta), rtrim(rtrim(number_format($c[$campo], 2, '.', ''), '0'), '.'));

                continue;
            }

            $renglones[] = ['detalle_id' => $c['detalle_id'], 'descripcion' => $c['descripcion'], 'precio_unitario' => $c['precio_unitario'], 'cantidad' => $cantidad];
        }

        if ($errores) {
            throw ValidationException::withMessages(['cantidades' => implode(' ', $errores)]);
        }
        if ($renglones === []) {
            throw ValidationException::withMessages(['cantidades' => 'Captura al menos una cantidad.']);
        }

        return $renglones;
    }
}
