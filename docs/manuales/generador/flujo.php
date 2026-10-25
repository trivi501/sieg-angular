<?php

/** Pasos del trámite de una requisición (los usan 02 y 04). */

use App\Models\EgresoRequisicion;
use App\Models\OrdenCompra;

/** Captura una requisición. $filas: [[descripción, cantidad, [partidas…]], …]. */
function requisicion(string $correo, string $clave, string $fecha, string $concepto, array $filas, array $extra = []): ?EgresoRequisicion
{
    dia($fecha);
    $datos = ['id_cat_egreso_unidad_administrativa' => unidad($clave), 'concepto' => $concepto, 'fecha_tramite' => substr($fecha, 0, 10), 'incluye_iva' => false, 'filas' => [], ...$extra];
    foreach ($filas as $fila) {
        [$desc, $cant, $partidas] = $fila;
        $l = linea($clave, $partidas);
        $datos['filas'][] = ['fuente' => $l['fuente'], 'proyecto' => $l['proyecto'], 'partida' => $l['partida'], 'cantidad' => $cant, 'descripcion' => $desc]
            + (isset($fila[3]) ? ['precio_unitario' => $fila[3], 'lleva_iva' => true] : []);
    }
    [$s, $r] = api($correo, 'POST', 'requisiciones', $datos);

    return $s < 300 ? EgresoRequisicion::orderByDesc('id_tb_egreso_requisicion')->first() : null;
}

/** Avanza la requisición por el trámite hasta $hasta. $precios: precio unitario de cada renglón (cotización). */
function avanzar(EgresoRequisicion $r, string $hasta, array $precios = [], ?int $proveedor = null, string $area = CARLOS, array $saltar = []): ?OrdenCompra
{
    $id = $r->id_tb_egreso_requisicion;
    $pasos = ['enviar', 'aprobar', 'recursos', 'recibir', 'cotizar', 'suficiencia', 'orden', 'factura', 'clc', 'tramite_rm', 'tramite_cp', 'tramite_sindicatura', 'tramite_egresos'];
    $f = fn (string $paso) => dia(now('America/Mexico_City')->addDays($paso === 'tramite_egresos' ? 3 : 1)->toDateString().' 10:00');
    $oc = null;
    foreach ($pasos as $paso) {
        if (in_array($paso, $saltar, true)) {
            continue;
        }
        $f($paso);
        match ($paso) {
            // Extemporánea: la autoriza la Subdirección Administrativa y con eso pasa sola a revisión.
            'enviar' => $r->fresh()->extemporaneaPendiente()
                ? api(LAURA, 'POST', "requisiciones/{$id}/autorizar-extemporanea")
                : api($area, 'POST', "requisiciones/{$id}/enviar"),
            'aprobar' => api(LAURA, 'POST', "requisiciones/{$id}/aprobar"),
            'recursos' => api(LAURA, 'POST', "requisiciones/{$id}/recursos-materiales"),
            'recibir' => api(MONICA, 'POST', "requisiciones/{$id}/recibido"),
            'cotizar' => api(MONICA, 'PUT', "requisiciones/{$id}", ['fecha_tramite' => now('America/Mexico_City')->toDateString(),
                'filas' => array_map(fn ($p) => ['precio_unitario' => $p, 'lleva_iva' => true], $precios)]),
            'suficiencia' => api(RAUL, 'POST', "requisiciones/{$id}/suficiencia"),
            'orden' => api(MONICA, 'POST', "requisiciones/{$id}/orden-compra", ['proveedor_id' => $proveedor, 'fecha' => now('America/Mexico_City')->toDateString(),
                'observaciones' => 'Entrega en el almacén general, de 9:00 a 14:00 h.']),
            'factura' => (function () use ($r, &$oc) {
                $oc = OrdenCompra::where('id_tb_egreso_requisicion', $r->id_tb_egreso_requisicion)->first();
                $p = $oc->proveedor;
                $conceptos = App\Models\EgresoRequisicionDetalle::where('id_tb_egreso_requisicion', $r->id_tb_egreso_requisicion)->orderBy('id_tb_egreso_requisicion_detalle')->get()
                    ->map(fn ($d) => [$d->descripcion, (float) $d->cantidad, (float) $d->precio_unitario, true])->all();
                [$xml] = cfdi($p->rfc, $p->nombre, 'F', (string) (1000 + $oc->id), now('America/Mexico_City')->toDateString(), $conceptos);
                api(MONICA, 'POST', "ordenes-compra/{$oc->id}/xml", [], ['xml' => $xml]);
                api(MONICA, 'POST', "ordenes-compra/{$oc->id}/factura-pdf", [], ['pdf' => pdfFalso("factura_{$oc->id}.pdf", "Factura F-".(1000 + $oc->id))]);
            })(),
            'clc' => api($area, 'POST', 'ordenes-compra/'.OrdenCompra::where('id_tb_egreso_requisicion', $id)->value('id').'/clc'),
            'tramite_rm' => tramite($id, [['enviar_rm', $area], ['recibir_rm', MONICA]]),
            'tramite_cp' => tramite($id, [['enviar_cp', MONICA], ['recibir_cp', RAUL]]),
            'tramite_sindicatura' => tramite($id, [['enviar_sindicatura', RAUL], ['recibir_sindicatura', SOFIA]]),
            'tramite_egresos' => tramite($id, [['enviar_cp_firmada', SOFIA], ['recibir_cp_firmada', RAUL], ['enviar_egresos', RAUL], ['recibir_egresos', HECTOR]]),
        };
        if ($paso === $hasta) {
            break;
        }
    }

    return OrdenCompra::where('id_tb_egreso_requisicion', $id)->first();
}

function tramite(int $requisicion, array $acciones): void
{
    $oc = OrdenCompra::where('id_tb_egreso_requisicion', $requisicion)->value('id');
    foreach ($acciones as [$accion, $quien]) {
        api($quien, 'POST', 'clc/accion', ['accion' => $accion, 'ordenes' => [$oc], 'observaciones' => null]);
    }
}

