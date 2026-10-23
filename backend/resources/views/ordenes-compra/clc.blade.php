<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>CLC {{ $orden->clc_folio_completo }}</title>
    <style>
        @page { margin: 10mm 10mm 30mm 10mm; }
        body { font-family: Arial, Helvetica, sans-serif; font-size: 7.5pt; color: #000; }

        table.encabezado { width: 100%; border-collapse: collapse; }
        table.encabezado td { vertical-align: middle; padding: 0; }
        .logo { height: 62px; }
        .separador { border-left: 1px solid #b9b9b9; height: 40px; margin: 0 10px; }
        .titulo { color: #a8876e; font-size: 13pt; font-weight: bold; line-height: 1.2; }
        .folio { text-align: right; font-size: 9pt; }

        table.datos { width: 100%; border-collapse: collapse; margin-top: 4px; }
        table.datos td { padding: 1px 3px; vertical-align: bottom; }
        .etiqueta { font-size: 7pt; }
        .caja { border-bottom: 1px solid #000; border-right: 1px solid #000; border-left: 1px solid #000; font-weight: bold; font-size: 8pt; text-align: center; padding: 2px 3px; }
        .hueco { width: 8px; }

        table.detalle { width: 100%; border-collapse: collapse; margin-top: 6px; }
        table.detalle th { border: 1px solid #000; font-size: 7pt; font-weight: normal; padding: 1px 2px; text-align: center; vertical-align: middle; }
        table.detalle td { font-size: 7pt; padding: 2px; text-align: center; vertical-align: top; }
        table.detalle td.num { text-align: right; white-space: nowrap; }
        table.detalle tr.ultimo td { border-bottom: 1px solid #000; }

        table.cierre { width: 100%; border-collapse: collapse; margin-top: 2px; }
        table.cierre td { font-size: 7.5pt; padding: 1px 2px; vertical-align: top; }
        .concepto { font-size: 8pt; }
        .total { font-size: 10pt; font-weight: bold; text-align: right; padding-top: 4px; }

        table.firmas { width: 100%; border-collapse: collapse; margin-top: 70px; }
        table.firmas td { width: 33.3%; text-align: center; vertical-align: top; font-size: 7.5pt; padding: 0 18px; }
        .espacio-firma { height: 46px; }
        .firma-linea { border-top: 1px solid #000; padding-top: 3px; line-height: 1.5; }
        .firma-linea .nombre { font-weight: bold; }

        .elaboro { margin-bottom: 2px; font-size: 6pt; color: #000; }
        .fundamento { margin-bottom: 4px; color: #000; font-size: 5.6pt; text-align: justify; line-height: 1.25; }

        .pie { position: fixed; bottom: -24mm; left: 0; right: 0; font-size: 7pt; color: #444; }
    </style>
</head>
<body>
    @php
        $tz = 'America/Mexico_City'; // la app corre en UTC; el formato se imprime en hora local
        $req = $orden->requisicion;
        $unidad = $req?->catEgresoUnidadAdministrativa;
        $proveedor = $orden->proveedor;
        $fechaClc = \Carbon\Carbon::parse($orden->clc_fecha);

        // Cuenta para el pago: la del catálogo de proveedores o, si no tiene, la principal de sus cuentas adicionales.
        $cuenta = $proveedor->clabe || $proveedor->cuenta_bancaria
            ? (object) ['banco' => $proveedor->banco, 'clabe' => $proveedor->clabe, 'cuenta' => $proveedor->cuenta_bancaria]
            : $proveedor->cuentas()->where('activo', true)->orderByDesc('principal')->with('banco')->first();

        // Clasificación presupuestal: un renglón por fuente / proyecto / partida, con el nombre de la partida del presupuesto.
        $renglones = ($req?->tbEgresoRequisicionDetalles ?? collect())
            ->groupBy(fn ($d) => trim((string) $d->fuente).'|'.trim((string) $d->proyecto).'|'.trim((string) $d->partida))
            ->map(function ($ds) use ($unidad) {
                $d = $ds->first();

                return [
                    'fuente' => $d->fuente,
                    'proyecto' => $d->proyecto,
                    'partida' => $d->partida,
                    'nombre' => \App\Models\PresupuestoEgreso::where('CLAVE', $unidad?->clave)->where('CLAVE3', $d->fuente)
                        ->where('CLAVE6', $d->proyecto)->where('PARTIDA', $d->partida)->value('NOMBRE_PARTIDA'),
                    'subtotal' => $ds->sum(fn ($x) => (float) $x->sub_total),
                    'iva' => $ds->sum(fn ($x) => (float) $x->iva),
                    'importe' => $ds->sum(fn ($x) => (float) ($x->total ?? $x->sub_total)),
                ];
            })->values();
        $totalImporte = $renglones->sum('importe') ?: (float) $orden->importe;

        // ISR retenido en la factura (del XML): no cambia el importe; se le paga al proveedor Importe − ISR.
        // Con varias partidas se reparte en proporción a su importe (el último renglón lleva el redondeo).
        $isr = (float) ($orden->xml_isr ?? 0);
        $repartido = 0.0;
        $renglones = $renglones->map(function ($r, $i) use ($isr, $totalImporte, $renglones, &$repartido) {
            $r['isr'] = $i === $renglones->count() - 1
                ? round($isr - $repartido, 2)
                : round($totalImporte > 0 ? $isr * $r['importe'] / $totalImporte : 0, 2);
            $repartido += $r['isr'];

            return $r;
        });

        // Firmas: Solicita = titular de la secretaría; Autoriza = Tesorería (rol "autoriza" de la CLC) y
        // Sindicatura (rol "visto bueno").
        $capturo = $req?->id_user_laravel ? \App\Models\User::with('regidor')->find($req->id_user_laravel) : null;
        $solicita = \App\Models\Firmante::titularDe($capturo, 'clc', $req?->id_cat_egreso_unidad_administrativa, $fechaClc);
        $tesoreria = \App\Models\Firmante::vigente('clc', 'autoriza', null, $fechaClc);
        $sindicatura = \App\Models\Firmante::vigente('clc', 'vobo', null, $fechaClc);
        // Elaboró: firmante "Elabora" de la CLC o quien la generó.
        $elaboro = \App\Models\Firmante::vigente('clc', 'elabora', $req?->id_cat_egreso_unidad_administrativa, $fechaClc)?->nombre
            ?? ($orden->clc_user_id ? \App\Models\User::find($orden->clc_user_id)?->nombreConTitulo() : null);
        $n = fn ($v) => number_format((float) $v, 2);
    @endphp

    <div class="pie">
        <div class="elaboro">Elaboró: &nbsp; {{ mb_strtoupper($elaboro ?? '') }}</div>
        <div class="fundamento">
        FUNDAMENTO LEGAL: LEY ORGÁNICA DEL MUNICIPIO EN SUS ARTÍCULOS 84 FRACCIÓN II, FACULTA AL SÍNDICO MUNICIPAL PARA AUTORIZAR LOS GASTOS DE LA ADMINISTRACIÓN PÚBLICA MUNICIPAL, ASÍ COMO VIGILAR EL MANEJO Y APLICACIÓN DE LOS RECURSOS, DE CONFORMIDAD CON EL PRESUPUESTO CORRESPONDIENTE; 103 FRACCIÓN V, FACULTA AL TESORERO MUNICIPAL A FORMULAR LOS PROYECTOS ANUALES DE LA LEY DE INGRESOS Y PRESUPUESTO DE EGRESOS MUNICIPALES Y EJERCER EL CONTROL Y VIGILAR SU APLICACIÓN; Y 211 DONDE SEÑALA... "LAS TESORERÍAS MUNICIPALES DEBERÁN VIGILAR QUE EL EJERCICIO DEL PRESUPUESTO DE EGRESOS SE HAGA EN FORMA ESTRICTA, PARA LO CUAL TENDRÁN FACULTADES PARA VERIFICAR QUE TODA EROGACIÓN CON CARGO AL PRESUPUESTO ESTE DEBIDAMENTE JUSTIFICADO, PUDIENDO RECHAZAR UNA EROGACIÓN, SI ÉSTA SE CONSIDERA LESIVA PARA LOS INTERESES DEL ERARIO MUNICIPAL, COMUNICÁNDOLO AL AYUNTAMIENTO."
        </div>
        {{ $orden->clc_folio_completo }} · {{ $orden->folio_completo }} · Requisición {{ $req?->folio_completo }} · Impreso {{ now($tz)->locale('es')->isoFormat('D/MM/YYYY h:mm a') }}
        {{-- "Página N de M" lo escribe el controlador sobre el PDF --}}
    </div>

    <table class="encabezado">
        <tr>
            <td style="width: 118px;"><img class="logo" src="{{ public_path('img/logo_guindo.png') }}" alt="Ayuntamiento de Guadalupe"></td>
            <td style="width: 12px;"><div class="separador"></div></td>
            <td class="titulo">Cuenta por liquidar certificada para el<br>registro del ejercicio presupuestario</td>
            <td class="folio" style="width: 140px; vertical-align: bottom;">{{ $orden->clc_folio_completo }}</td>
        </tr>
    </table>

    <table class="datos">
        <tr>
            <td class="etiqueta" style="width: 36%;">Unidad Administrativa:</td>
            <td class="hueco"></td>
            <td class="etiqueta" style="width: 13%;">Banco:</td>
            <td class="hueco"></td>
            <td class="etiqueta" style="width: 20%;">Cuenta:</td>
            <td class="hueco"></td>
            <td class="etiqueta">CLABE:</td>
        </tr>
        <tr>
            <td class="caja">{{ mb_strtoupper($unidad?->nombre ?? '—') }}</td>
            <td></td>
            <td class="caja">{{ mb_strtoupper($cuenta?->banco?->descripcion ?? '—') }}</td>
            <td></td>
            <td class="caja" style="text-align: center;">{{ $cuenta?->cuenta ?: '—' }}</td>
            <td></td>
            <td class="caja">{{ $cuenta?->clabe ?: '—' }}</td>
        </tr>
    </table>
    <table class="datos">
        <tr>
            <td class="etiqueta" style="width: 15%;">Clave de la unidad administrativa</td>
            <td class="hueco"></td>
            <td class="etiqueta">Proveedor:</td>
            <td class="hueco"></td>
            <td class="etiqueta" style="width: 21%;">R.F.C:</td>
        </tr>
        <tr>
            <td class="caja">{{ $unidad?->clave ?? '—' }}</td>
            <td></td>
            <td class="caja">{{ mb_strtoupper($proveedor->nombre) }}</td>
            <td></td>
            <td class="caja">{{ $proveedor->rfc ?? '—' }}</td>
        </tr>
    </table>

    <table class="detalle">
        <thead>
            <tr>
                <th rowspan="3" style="width: 8%;">O.C.</th>
                <th colspan="4">Clasificación presupuestal</th>
                <th rowspan="3" style="width: 19%;">Número de factura</th>
                <th rowspan="3" style="width: 6%;">Fecha de factura</th>
                <th rowspan="3" style="width: 7%;">Sub Total</th>
                <th rowspan="3" style="width: 5%;">Desc.</th>
                <th rowspan="3" style="width: 6%;">I.V.A.</th>
                <th rowspan="3" style="width: 5%;">I.S.R.</th>
                <th rowspan="3" style="width: 7%;">Importe</th>
            </tr>
            <tr>
                <th style="width: 4.5%;">Fuente</th>
                <th style="width: 5.5%;">Proyecto</th>
                <th colspan="2">Objeto del gasto</th>
            </tr>
            <tr>
                <th>Clave</th>
                <th>Clave</th>
                <th style="width: 4%;">Clave</th>
                <th>Nombre</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($renglones as $r)
                <tr class="{{ $loop->last ? 'ultimo' : '' }}">
                    <td>{{ $orden->folio_completo }}</td>
                    <td>{{ $r['fuente'] }}</td>
                    <td>{{ $r['proyecto'] }}</td>
                    <td>{{ $r['partida'] }}</td>
                    <td>{{ mb_strtoupper($r['nombre'] ?? '') }}</td>
                    <td style="font-size: 6.2pt; white-space: nowrap;">{{ $orden->xml_uuid }}</td>
                    <td>{{ $orden->xml_fecha?->format('d/m/Y') }}</td>
                    <td class="num">{{ $n($r['subtotal']) }}</td>
                    <td class="num">0.00</td>
                    <td class="num">{{ $n($r['iva']) }}</td>
                    <td class="num">{{ $n($r['isr']) }}</td>
                    <td class="num">{{ $n($r['importe']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="cierre">
        <tr>
            <td class="concepto">CONCEPTO: {{ mb_strtoupper($req?->observaciones ?: '—') }}</td>
            <td class="num" style="width: 5%; text-align: right;">{{ $n($isr) }}</td>
            <td class="num" style="width: 7%; text-align: right;">{{ $n($totalImporte) }}</td>
        </tr>
        <tr>
            <td colspan="3" class="total">Total(Imp - ISR): &nbsp;&nbsp;&nbsp;&nbsp; ${{ $n($totalImporte - $isr) }}</td>
        </tr>
    </table>

    <table class="firmas">
        <tr>
            <td>Solicita:</td>
            <td>Autoriza:</td>
            <td>Autoriza:</td>
        </tr>
        <tr>
            <td class="espacio-firma"></td>
            <td class="espacio-firma"></td>
            <td class="espacio-firma"></td>
        </tr>
        <tr>
            <td>
                <div class="firma-linea">
                    <span class="nombre">{{ mb_strtoupper($solicita['nombre'] ?? '') ?: "\u{00A0}" }}</span><br>
                    {{ mb_strtoupper($solicita['cargo'] ?? $unidad?->nombre ?? '') }}
                </div>
            </td>
            <td>
                <div class="firma-linea">
                    <span class="nombre">{{ mb_strtoupper($tesoreria?->nombre ?? '') ?: "\u{00A0}" }}</span><br>
                    {{ mb_strtoupper($tesoreria?->cargo ?? 'SECRETARIO DE LA TESORERÍA Y FINANZAS') }}
                </div>
            </td>
            <td>
                <div class="firma-linea">
                    <span class="nombre">{{ mb_strtoupper($sindicatura?->nombre ?? '') ?: "\u{00A0}" }}</span><br>
                    {{ mb_strtoupper($sindicatura?->cargo ?? 'SÍNDICO MUNICIPAL') }}
                </div>
            </td>
        </tr>
    </table>

</body>
</html>
