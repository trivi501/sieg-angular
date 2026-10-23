<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Modificación Presupuestal {{ $modificacion->folio_completo }}</title>
    <style>
        @page { margin: 12mm 12mm 16mm 12mm; }
        body { font-family: Arial, Helvetica, sans-serif; font-size: 8pt; color: #000; }

        table.encabezado { width: 100%; border-collapse: collapse; }
        table.encabezado td { vertical-align: middle; padding: 0; }
        .logo { height: 64px; }
        .separador { border-left: 1px solid #b9b9b9; height: 40px; margin: 0 12px; }
        .titulo { font-size: 17pt; font-weight: bold; color: #6d6e70; letter-spacing: 0.5px; }
        .folio { font-size: 10pt; font-weight: bold; text-align: right; }
        .estado { font-size: 7pt; font-weight: bold; color: #7a2e43; text-align: right; }

        table.datos { width: 100%; border-collapse: collapse; margin-top: 4px; }
        table.datos td { padding: 1px 0; vertical-align: bottom; }
        .etiqueta { font-size: 8.5pt; }
        .caja { border-bottom: 1.5px solid #000; border-right: 1.5px solid #000; font-weight: bold; font-size: 9pt; padding: 2px 4px; }
        .caja-fecha { border: 1.5px solid #000; border-top: none; font-weight: bold; font-size: 9pt; padding: 3px 4px; text-align: center; }

        table.movimiento { width: 100%; border-collapse: collapse; margin-top: 10px; }
        table.movimiento th, table.movimiento td { border: 1px solid #000; text-align: center; vertical-align: middle; }
        table.movimiento th { font-size: 7pt; font-weight: bold; padding: 3px 2px; }
        table.movimiento td { font-size: 7.5pt; padding: 4px 2px; border-top: none; border-left: none; border-right: none; }
        table.movimiento td.justificacion { text-align: left; font-size: 7pt; padding-left: 4px; }
        table.movimiento td.importe { text-align: right; padding-right: 4px; }
        table.movimiento tr.cuerpo td { border-bottom: 1.5px solid #000; }
        .sin-borde { border: none !important; }
        table.movimiento td.total { font-weight: bold; font-size: 8.5pt; text-align: right; padding-top: 3px; }

        .firma { width: 320px; margin: 24px 40px 0 auto; text-align: center; font-size: 8.5pt; }
        .firma-linea { border-top: 1px solid #000; padding-top: 6px; line-height: 2; }

        .pie { position: fixed; bottom: -8mm; left: 0; right: 0; font-size: 7pt; font-weight: bold; }
        .pie table { width: 100%; border-collapse: collapse; }
    </style>
</head>
<body>
    @php
        $tz = 'America/Mexico_City'; // la app corre en UTC; el formato se imprime en hora local
        $meses = \App\Services\SaldosMensuales::MESES;
        $solicitud = $modificacion->created_at->setTimezone($tz)->locale('es');
        $estados = ['pendiente' => 'PENDIENTE DE AUTORIZACIÓN', 'rechazada' => 'NO PROCEDE (RECHAZADA)'];
        $lado = fn (string $l) => $modificacion->{$l.'_clave'} === null ? null : [
            'fuente' => $modificacion->{$l.'_fuente'},
            'proyecto' => $modificacion->{$l.'_proyecto'},
            'unidad' => $modificacion->{$l.'_clave'},
            'partida' => $modificacion->{$l.'_partida'},
            'tipo' => $tipoGasto[$l] ?? '',
            'mes' => $modificacion->{$l.'_mes'} ? mb_strtoupper(mb_substr($meses[$modificacion->{$l.'_mes'}], 0, 3)) : '',
        ];
        $reduccion = $lado('origen');
        $ampliacion = $lado('destino');
        $celdas = fn (?array $l) => $l ?? ['fuente' => '', 'proyecto' => '', 'unidad' => '', 'partida' => '', 'tipo' => '', 'mes' => ''];
    @endphp

    <div class="pie">
        <table>
            <tr>
                <td>{{ now($tz)->locale('es')->isoFormat('D [de] MMMM [de] YYYY HH:mm:ss') }}</td>
                <td style="width: 70px;"></td>{{-- "Página N de M" lo escribe el controlador sobre el PDF --}}
                <td style="text-align: right; width: 90px; font-weight: normal;">{{ $modificacion->folio_completo }}</td>
            </tr>
        </table>
    </div>

    <table class="encabezado">
        <tr>
            <td style="width: 120px;"><img class="logo" src="{{ public_path('img/logo_guindo.png') }}" alt="Ayuntamiento de Guadalupe"></td>
            <td style="width: 14px;"><div class="separador"></div></td>
            <td class="titulo">MODIFICACIÓN PRESUPUESTAL</td>
            <td style="width: 110px; text-align: right;">
                <div class="folio">{{ $modificacion->folio_completo }}</div>
                @isset($estados[$modificacion->estado])
                    <div class="estado">{{ $estados[$modificacion->estado] }}</div>
                @endisset
            </td>
        </tr>
    </table>

    <table class="datos">
        <tr>
            <td class="etiqueta">Unidad administrativa:</td>
            <td style="width: 20px;"></td>
            <td class="etiqueta" style="width: 200px;">Fecha de solicitud:</td>
        </tr>
        <tr>
            <td class="caja">{{ $unidad?->nombre ?? '—' }}</td>
            <td></td>
            <td class="caja-fecha" style="border-top: 1.5px solid #000;">{{ $solicitud->isoFormat('D [de] MMMM [de] YYYY') }}</td>
        </tr>
    </table>

    <table class="movimiento">
        <thead>
            <tr>
                <th colspan="6">REDUCCIÓN</th>
                <th colspan="6">AMPLIACIÓN</th>
                <th rowspan="2" style="width: 9%;">Importe</th>
                <th rowspan="2">Justificación</th>
            </tr>
            <tr>
                @foreach (['REDUCCIÓN', 'AMPLIACIÓN'] as $_)
                    <th style="width: 6%;">Fuente</th>
                    <th style="width: 6.5%;">Proyecto</th>
                    <th style="width: 5.5%;">Unidad</th>
                    <th style="width: 5.5%;">Partida</th>
                    <th style="width: 5%;">Tipo de gasto</th>
                    <th style="width: 4.5%;">Mes</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            <tr class="cuerpo">
                @foreach ([$celdas($reduccion), $celdas($ampliacion)] as $l)
                    <td>{{ $l['fuente'] }}</td>
                    <td>{{ $l['proyecto'] }}</td>
                    <td>{{ $l['unidad'] }}</td>
                    <td>{{ $l['partida'] }}</td>
                    <td>{{ $l['tipo'] }}</td>
                    <td>{{ $l['mes'] }}</td>
                @endforeach
                <td class="importe">{{ number_format($modificacion->importe, 2) }}</td>
                <td class="justificacion">{{ mb_strtoupper($modificacion->justificacion) }}</td>
            </tr>
            <tr>
                <td colspan="12" class="sin-borde total">TOTAL:</td>
                <td class="sin-borde total">${{ number_format($modificacion->importe, 2) }}</td>
                <td class="sin-borde"></td>
            </tr>
        </tbody>
    </table>

    @if ($modificacion->estado === 'rechazada' && $modificacion->observaciones_resolucion)
        <p style="margin-top: 8px; font-size: 7.5pt;"><strong>Motivo por el que no procede:</strong> {{ $modificacion->observaciones_resolucion }}</p>
    @endif

    <div class="firma">
        <div style="margin-bottom: 52px;">Solicita:</div>
        <div class="firma-linea">
            {{ $firmante['nombre'] ?? '' }}<br>
            {{ mb_strtoupper($firmante['cargo'] ?? $unidad?->nombre ?? 'LA UNIDAD ADMINISTRATIVA') }}
        </div>
    </div>
</body>
</html>
