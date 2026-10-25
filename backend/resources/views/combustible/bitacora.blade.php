<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Bitácora de combustible {{ $tramite->folio_completo }}</title>
    <style>
        @page { margin: 10mm 10mm 14mm 10mm; }
        body { font-family: Arial, Helvetica, sans-serif; font-size: 7.5pt; color: #000; }

        table.encabezado { width: 100%; border-collapse: collapse; }
        table.encabezado td { vertical-align: middle; padding: 0; }
        .logo { height: 52px; }
        .separador { border-left: 1px solid #b9b9b9; height: 36px; margin: 0 12px; }
        .titulo { color: #6d6e70; font-weight: bold; line-height: 1.15; }
        .titulo .chico { font-size: 9pt; }
        .titulo .grande { font-size: 14pt; }
        .folio { text-align: right; font-size: 11pt; font-weight: bold; }

        table.datos { width: 100%; border-collapse: collapse; margin-top: 8px; }
        table.datos td { padding: 2px 0; font-size: 8.5pt; }
        .etiqueta { color: #555; }

        table.lista { width: 100%; border-collapse: collapse; margin-top: 8px; }
        table.lista th { font-size: 7pt; border-top: 1.5px solid #000; border-bottom: 1.5px solid #000; padding: 2px 3px; text-align: left; }
        table.lista td { font-size: 7pt; padding: 2px 3px; border-bottom: 0.5px solid #ccc; vertical-align: top; }
        .num { text-align: right; white-space: nowrap; }
        tr.total td { border-bottom: none; border-top: 1.5px solid #000; font-weight: bold; }

        table.resumen { border-collapse: collapse; margin-top: 10px; }
        table.resumen th, table.resumen td { font-size: 7.5pt; padding: 2px 6px; border-bottom: 0.5px solid #ccc; text-align: left; }
        table.resumen th { border-top: 1.5px solid #000; border-bottom: 1.5px solid #000; }

        .firmas { width: 100%; margin-top: 36px; border-collapse: collapse; }
        .firmas td { width: 33%; text-align: center; font-size: 8pt; padding: 0 20px; }
        .linea { border-top: 1px solid #000; padding-top: 3px; }

        .pie { position: fixed; bottom: -8mm; left: 0; right: 0; font-size: 7pt; color: #444; }
    </style>
</head>
<body>
    @php
        $tz = 'America/Mexico_City';
        $unidad = trim(($tramite->unidadAdministrativa?->clave ?? '').' '.($tramite->unidadAdministrativa?->nombre ?? ''));
        $fmt = fn ($n, $d = 2) => number_format((float) $n, $d);
    @endphp

    <div class="pie">{{ $tramite->folio_completo }} · {{ $unidad }} · {{ $tramite->lote?->periodo }} · Impreso {{ now($tz)->locale('es')->isoFormat('D/MM/YYYY h:mm a') }}</div>

    <table class="encabezado">
        <tr>
            <td style="width: 100px;"><img class="logo" src="{{ public_path('img/logo_guindo.png') }}" alt="Ayuntamiento de Guadalupe"></td>
            <td style="width: 14px;"><div class="separador"></div></td>
            <td class="titulo"><span class="chico">CONTROL DE COMBUSTIBLE</span><br><span class="grande">BITÁCORA DE CONSUMO POR SECRETARÍA</span></td>
            <td class="folio" style="width: 140px;">{{ $tramite->folio_completo }}</td>
        </tr>
    </table>

    <table class="datos">
        <tr>
            <td style="width: 50%;"><span class="etiqueta">Secretaría:</span> <strong>{{ $unidad }}</strong></td>
            <td><span class="etiqueta">Periodo:</span> <strong>{{ $tramite->lote?->periodo }}</strong></td>
        </tr>
        <tr>
            <td><span class="etiqueta">Proveedor:</span> {{ $tramite->proveedor_nombre ?? '—' }} {{ $tramite->proveedor_rfc ? '('.$tramite->proveedor_rfc.')' : '' }}</td>
            <td>
                <span class="etiqueta">Estado:</span> {{ \App\Models\TramiteServicio::ESTADOS[$tramite->estado] ?? $tramite->estado }}
                @if ($tramite->requisicion)
                    · <span class="etiqueta">Requisición:</span> {{ $tramite->requisicion->folio_completo }}
                @endif
                @if ($tramite->ordenCompra?->clc_folio_completo)
                    · <span class="etiqueta">CLC:</span> {{ $tramite->ordenCompra->clc_folio_completo }}
                @endif
            </td>
        </tr>
    </table>

    <table class="lista">
        <thead>
            <tr>
                <th style="width: 3%;">#</th>
                <th style="width: 7%;">Fecha</th>
                <th style="width: 7%;">Ticket</th>
                <th style="width: 6%;">Vale</th>
                <th style="width: 8%;">No. económico</th>
                <th style="width: 7%;">Placas</th>
                <th>Departamento</th>
                <th>Nombre</th>
                <th style="width: 7%;">Producto</th>
                <th class="num" style="width: 6%;">Litros</th>
                <th class="num" style="width: 6%;">Precio</th>
                <th class="num" style="width: 8%;">Importe</th>
                <th style="width: 8%;">Factura</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($tickets as $i => $t)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $t['fecha_ticket'] ? \Carbon\Carbon::parse($t['fecha_ticket'])->format('d/m/Y') : '' }}</td>
                    <td>{{ $t['ticket'] }}</td>
                    <td>{{ $t['vale'] }}</td>
                    <td>{{ $t['numero_economico'] }}</td>
                    <td>{{ $t['placas'] }}</td>
                    <td>{{ $t['departamento'] }}</td>
                    <td>{{ $t['conductor'] }}</td>
                    <td>{{ $t['producto'] }}</td>
                    <td class="num">{{ $fmt($t['litros'], 3) }}</td>
                    <td class="num">{{ $fmt($t['precio'], 4) }}</td>
                    <td class="num">${{ $fmt($t['importe']) }}</td>
                    <td>{{ $t['factura_folio'] }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td colspan="9">{{ $tickets->count() }} tickets</td>
                <td class="num">{{ $fmt($tickets->sum('litros'), 3) }}</td>
                <td></td>
                <td class="num">${{ $fmt($tickets->sum('importe')) }}</td>
                <td></td>
            </tr>
        </tbody>
    </table>

    <table style="width: 100%; border-collapse: collapse;">
        <tr>
            <td style="vertical-align: top; width: 55%;">
                <table class="resumen">
                    <thead><tr><th>Factura</th><th class="num">Tickets</th><th class="num">Litros</th><th class="num">Importe</th></tr></thead>
                    <tbody>
                        @foreach ($porFactura as $f)
                            <tr><td>{{ $f['folio'] }}</td><td class="num">{{ $f['tickets'] }}</td><td class="num">{{ $fmt($f['litros'], 3) }}</td><td class="num">${{ $fmt($f['importe']) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </td>
            <td style="vertical-align: top;">
                <table class="resumen">
                    <thead><tr><th>Producto</th><th class="num">Litros</th><th class="num">Importe</th></tr></thead>
                    <tbody>
                        @foreach ($porProducto as $p)
                            <tr><td>{{ $p['producto'] }}</td><td class="num">{{ $fmt($p['litros'], 3) }}</td><td class="num">${{ $fmt($p['importe']) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </td>
        </tr>
    </table>

    <table class="firmas">
        <tr>
            <td><div class="linea">Elaboró<br>Unidad de combustibles</div></td>
            <td><div class="linea">Revisó<br>Recursos Materiales</div></td>
            <td><div class="linea">Vo. Bo.<br>{{ $tramite->unidadAdministrativa?->nombre }}</div></td>
        </tr>
    </table>
</body>
</html>
