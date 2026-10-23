<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Relación {{ $relacion->folio_completo }}</title>
    <style>
        @page { margin: 12mm 12mm 16mm 12mm; }
        body { font-family: Arial, Helvetica, sans-serif; font-size: 8.5pt; color: #000; }

        table.encabezado { width: 100%; border-collapse: collapse; }
        table.encabezado td { vertical-align: middle; padding: 0; }
        .logo { height: 60px; }
        .separador { border-left: 1px solid #b9b9b9; height: 40px; margin: 0 12px; }
        .titulo { color: #6d6e70; font-weight: bold; line-height: 1.15; }
        .titulo .chico { font-size: 9.5pt; }
        .titulo .grande { font-size: 15pt; }
        .folio { text-align: right; font-size: 11pt; font-weight: bold; }

        table.datos { width: 100%; border-collapse: collapse; margin-top: 10px; }
        table.datos td { padding: 2px 0; font-size: 9pt; }
        .etiqueta { color: #555; }

        table.lista { width: 100%; border-collapse: collapse; margin-top: 10px; }
        table.lista th { font-size: 8pt; border-top: 1.5px solid #000; border-bottom: 1.5px solid #000; padding: 3px; text-align: left; }
        table.lista td { font-size: 8pt; padding: 3px; border-bottom: 0.5px solid #bbb; vertical-align: top; }
        .num { text-align: right; white-space: nowrap; }
        .casilla { width: 10px; height: 10px; border: 1px solid #000; display: inline-block; }
        tr.total td { border-bottom: none; border-top: 1.5px solid #000; font-weight: bold; }

        .observaciones { margin-top: 10px; font-size: 8.5pt; }
        table.firmas { width: 100%; border-collapse: collapse; margin-top: 60px; }
        table.firmas td { width: 50%; text-align: center; font-size: 8.5pt; padding: 0 30px; vertical-align: top; }
        .firma-linea { border-top: 1px solid #000; padding-top: 3px; line-height: 1.4; }

        .pie { position: fixed; bottom: -9mm; left: 0; right: 0; font-size: 7pt; color: #444; }
    </style>
</head>
<body>
    @php
        $tz = 'America/Mexico_City';
        $fecha = $relacion->created_at->setTimezone($tz)->locale('es');
        $de = $areas[$relacion->origen] ?? $relacion->origen;
        $para = $areas[$relacion->destino] ?? $relacion->destino;
    @endphp

    <div class="pie">{{ $relacion->folio_completo }} · {{ $de }} → {{ $para }} · Impreso {{ now($tz)->locale('es')->isoFormat('D/MM/YYYY h:mm a') }}</div>

    <table class="encabezado">
        <tr>
            <td style="width: 112px;"><img class="logo" src="{{ public_path('img/logo_guindo.png') }}" alt="Ayuntamiento de Guadalupe"></td>
            <td style="width: 14px;"><div class="separador"></div></td>
            <td class="titulo"><span class="chico">RELACIÓN DE ENVÍO DE</span><br><span class="grande">CUENTAS POR LIQUIDAR CERTIFICADAS</span></td>
            <td class="folio" style="width: 130px;">{{ $relacion->folio_completo }}</td>
        </tr>
    </table>

    <table class="datos">
        <tr>
            <td style="width: 50%;"><span class="etiqueta">Entrega:</span> <strong>{{ mb_strtoupper($de) }}</strong></td>
            <td><span class="etiqueta">Recibe:</span> <strong>{{ mb_strtoupper($para) }}</strong></td>
        </tr>
        <tr>
            <td><span class="etiqueta">Fecha:</span> {{ $fecha->isoFormat('D [de] MMMM [de] YYYY, h:mm a') }}</td>
            <td><span class="etiqueta">CLC en la relación:</span> {{ $relacion->ordenes->count() }}</td>
        </tr>
    </table>

    <table class="lista">
        <thead>
            <tr>
                <th style="width: 4%;">#</th>
                <th style="width: 13%;">CLC</th>
                <th style="width: 12%;">O.C.</th>
                <th style="width: 11%;">Requisición</th>
                <th>Unidad administrativa</th>
                <th>Proveedor</th>
                <th class="num" style="width: 12%;">Importe</th>
                <th style="width: 7%; text-align: center;">Recibida</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($relacion->ordenes as $i => $o)
                @php $u = $o->requisicion?->catEgresoUnidadAdministrativa; @endphp
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $o->clc_folio_completo }}</td>
                    <td>{{ $o->folio_completo }}</td>
                    <td>{{ $o->requisicion?->folio_completo }}</td>
                    <td>{{ $u ? trim($u->clave.' '.$u->nombre) : '—' }}</td>
                    <td>{{ $o->proveedor?->nombre }}</td>
                    <td class="num">${{ number_format((float) $o->importe, 2) }}</td>
                    <td style="text-align: center;">
                        @if ($o->pivot->recibida_at)
                            SÍ
                        @else
                            <span class="casilla"></span>
                        @endif
                    </td>
                </tr>
            @endforeach
            <tr class="total">
                <td colspan="6" class="num">TOTAL:</td>
                <td class="num">${{ number_format($relacion->ordenes->sum(fn ($o) => (float) $o->importe), 2) }}</td>
                <td></td>
            </tr>
        </tbody>
    </table>

    @if ($relacion->observaciones)
        <div class="observaciones"><strong>Observaciones:</strong> {{ $relacion->observaciones }}</div>
    @endif

    <table class="firmas">
        <tr>
            <td>
                <div class="firma-linea">
                    ENTREGA<br>{{ mb_strtoupper($relacion->usuario?->nombreConTitulo() ?? '') }}<br>{{ mb_strtoupper($de) }}
                </div>
            </td>
            <td>
                <div class="firma-linea">
                    RECIBE<br>&nbsp;<br>{{ mb_strtoupper($para) }}
                </div>
            </td>
        </tr>
    </table>
</body>
</html>
