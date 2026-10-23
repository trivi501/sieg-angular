<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Orden de Compra {{ $orden->folio_completo }}</title>
    <style>
        @page { margin: 10mm 14mm 16mm 14mm; }
        body { font-family: Arial, Helvetica, sans-serif; font-size: 8.5pt; color: #000; }

        table.encabezado { width: 100%; border-collapse: collapse; }
        table.encabezado td { vertical-align: middle; padding: 0; }
        .logo { height: 68px; }
        .separador { border-left: 1px solid #b9b9b9; height: 44px; margin: 0 12px; }
        .titulo { color: #6d6e70; font-weight: bold; font-size: 11pt; line-height: 1.2; letter-spacing: 0.3px; }
        .folio { text-align: right; font-size: 9pt; vertical-align: bottom !important; }

        table.datos { width: 100%; border-collapse: collapse; margin-top: 10px; }
        table.datos td { padding: 1.5px 3px; vertical-align: top; }
        .etiqueta { text-align: right; white-space: nowrap; width: 17%; }
        .valor { font-weight: bold; }
        .etiqueta-prov { white-space: nowrap; width: 9%; }

        table.renglones { width: 100%; border-collapse: collapse; margin-top: 6px; }
        table.renglones th { font-size: 8pt; font-weight: bold; border-top: 1.5px solid #000; border-bottom: 1.5px solid #000; padding: 2px 4px; }
        table.renglones td { padding: 2px 4px; vertical-align: top; }
        table.renglones tr.ultimo td { border-bottom: 1.5px solid #000; padding-bottom: 3px; }
        table.renglones td.total-etq { text-align: right; font-weight: bold; padding-top: 4px; }
        table.renglones td.total-val { text-align: right; font-weight: bold; padding-top: 4px; }
        .num { text-align: right; white-space: nowrap; }
        .centro { text-align: center; }

        .concepto { margin-top: 10px; }

        table.firmas { width: 100%; border-collapse: collapse; margin-top: 28px; }
        table.firmas td { width: 50%; text-align: center; vertical-align: top; padding: 0 22px; }
        .espacio-firma { height: 52px; }
        .firma-linea { border-top: 1px solid #000; padding-top: 3px; line-height: 1.3; }

        .pie { position: fixed; bottom: -9mm; left: 0; right: 0; font-size: 7pt; color: #444; }
    </style>
</head>
<body>
    @php
        $tz = 'America/Mexico_City'; // la app corre en UTC; el formato se imprime en hora local
        $req = $orden->requisicion;
        $detalles = $req?->tbEgresoRequisicionDetalles ?? collect();
        $proveedor = $orden->proveedor;
        $cantidad = fn ($c) => is_numeric($c) ? (fmod((float) $c, 1) == 0 ? number_format((float) $c, 0) : number_format((float) $c, 2)) : $c;
        $folioFactura = trim(($orden->xml_serie ?? '').' '.($orden->xml_folio ?? ''));

        // Firman por Recursos Materiales (firmantes generales del documento "orden_compra"):
        // solicita el Jefe del Departamento de Recursos Materiales y autoriza el Subsecretario de Administración.
        $solicita = \App\Models\Firmante::vigente('orden_compra', 'elabora', null, $orden->fecha);
        $autoriza = \App\Models\Firmante::vigente('orden_compra', 'autoriza', null, $orden->fecha);
    @endphp

    <div class="pie">
        {{ $orden->folio_completo }} · Requisición {{ $req?->folio_completo }} · Impreso {{ now($tz)->isoFormat('D/MM/YYYY h:mm a') }}
        {{-- "Página N de M" y el folio los escribe el controlador sobre el PDF --}}
    </div>

    <table class="encabezado">
        <tr>
            <td style="width: 118px;"><img class="logo" src="{{ public_path('img/logo_guindo.png') }}" alt="Ayuntamiento de Guadalupe"></td>
            <td style="width: 14px;"><div class="separador"></div></td>
            {{-- Provisional mientras no tiene factura (CFDI) --}}
            <td class="titulo">ORDEN DE<br>COMPRA{{ $orden->xml_uuid ? '' : ' PROVISIONAL' }}</td>
            <td class="folio">{{ $orden->folio_completo }}</td>
        </tr>
    </table>

    <table class="datos">
        <tr>
            <td class="etiqueta">Fecha:</td>
            <td class="valor" style="width: 17%;">{{ $orden->fecha ? \Carbon\Carbon::parse($orden->fecha)->format('d/m/Y') : '' }}</td>
            <td class="etiqueta-prov">Proveedor:</td>
            <td colspan="3"></td>
        </tr>
        <tr>
            <td class="etiqueta">Requisición:</td>
            <td class="valor">{{ $req?->folio_completo }}</td>
            <td colspan="4" class="valor">{{ mb_strtoupper($proveedor?->nombre ?? '') }}</td>
        </tr>
        <tr>
            <td class="etiqueta">Fecha de factura:</td>
            <td class="valor">{{ $orden->xml_fecha?->setTimezone($tz)->format('d/m/Y') }}</td>
            <td class="etiqueta-prov">R.F.C:</td>
            <td class="valor" style="width: 20%;">{{ $proveedor?->rfc }}</td>
            <td style="width: 8%; white-space: nowrap;">Correo E:</td>
            <td class="valor">{{ $proveedor?->correo }}</td>
        </tr>
        <tr>
            <td class="etiqueta">Folio de factura:</td>
            <td class="valor">{{ $folioFactura }}</td>
            <td colspan="4"></td>
        </tr>
        <tr>
            <td class="etiqueta">Validación CFDI:</td>
            <td class="valor" colspan="5" style="font-size: 7.5pt;">{{ $orden->xml_uuid }}</td>
        </tr>
    </table>

    <table class="renglones">
        <thead>
            <tr>
                <th class="centro" style="width: 12%;">Cantidad</th>
                <th style="text-align: left;">Descripción</th>
                <th class="num" style="width: 16%;">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach($detalles as $i => $d)
                <tr class="{{ $loop->last ? 'ultimo' : '' }}">
                    <td class="centro">{{ $cantidad($d->cantidad) }}</td>
                    <td>{{ mb_strtoupper((string) $d->descripcion) }}</td>
                    <td class="num">{{ number_format((float) ($d->total ?? $d->sub_total), 2) }}</td>
                </tr>
            @endforeach
            <tr>
                <td></td>
                <td class="total-etq">TOTAL:</td>
                <td class="total-val">${{ number_format((float) $orden->importe, 2) }}</td>
            </tr>
        </tbody>
    </table>

    <div class="concepto">CONCEPTO: {{ mb_strtoupper((string) ($req?->observaciones ?? '')) }}</div>
    @if($orden->observaciones)
        <div class="concepto" style="margin-top: 4px;">OBSERVACIONES: {{ $orden->observaciones }}</div>
    @endif

    <table class="firmas">
        <tr>
            <td>Solicita:<div class="espacio-firma"></div></td>
            <td>Autoriza:<div class="espacio-firma"></div></td>
        </tr>
        <tr>
            <td>
                <div class="firma-linea">
                    {{ $solicita?->nombre }}<br>
                    {{ mb_strtoupper($solicita?->cargo ?? 'JEFE DEL DEPARTAMENTO DE RECURSOS MATERIALES') }}
                </div>
            </td>
            <td>
                <div class="firma-linea">
                    {{ $autoriza?->nombre }}<br>
                    {{ mb_strtoupper($autoriza?->cargo ?? 'SUBSECRETARIO DE ADMINISTRACIÓN') }}
                </div>
            </td>
        </tr>
    </table>
</body>
</html>
