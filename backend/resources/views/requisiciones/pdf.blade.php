<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>FUR {{ $requisicion->folio_completo }}</title>
    <style>
        @page { margin: 10mm 12mm 16mm 12mm; }
        body { font-family: Arial, Helvetica, sans-serif; font-size: 9pt; color: #000; }

        table.encabezado { width: 100%; border-collapse: collapse; }
        table.encabezado td { vertical-align: middle; padding: 0; }
        .logo { height: 68px; }
        .separador { border-left: 1px solid #b9b9b9; height: 44px; margin: 0 12px; }
        .titulo { color: #6d6e70; font-weight: bold; line-height: 1.15; }
        .titulo .chico { font-size: 10pt; letter-spacing: 0.3px; }
        .titulo .grande { font-size: 17pt; letter-spacing: 0.5px; }

        table.datos { width: 100%; border-collapse: collapse; margin-top: 6px; }
        table.datos td { padding: 1px 0; vertical-align: bottom; }
        .etiqueta { font-size: 8.5pt; }
        .valor { font-weight: bold; font-size: 9pt; }
        .folio { font-size: 10pt; text-align: right; }

        table.renglones { width: 100%; border-collapse: collapse; margin-top: 8px; }
        table.renglones th { font-size: 8.5pt; font-weight: bold; border-top: 1.5px solid #000; border-bottom: 1.5px solid #000; padding: 2px 3px; text-align: center; }
        table.renglones th.texto { text-align: left; }
        table.renglones td { font-size: 8.5pt; padding: 1px 3px; text-align: center; vertical-align: middle; }
        table.renglones td.texto { text-align: left; }
        table.renglones tr.ultimo td { border-bottom: 1.5px solid #000; padding-bottom: 2px; }

        .concepto { margin-top: 10px; font-size: 8.5pt; }

        table.firmas { width: 100%; border-collapse: collapse; margin-top: 18px; }
        table.firmas td { width: 50%; text-align: center; vertical-align: top; font-size: 8.5pt; padding: 0 22px; }
        .espacio-firma { height: 62px; }
        .firma-linea { border-top: 1px solid #000; padding-top: 3px; line-height: 1.3; }

        .estado { margin-top: 4px; font-size: 7pt; font-weight: bold; color: #7a2e43; text-align: right; }
        .pie { position: fixed; bottom: -9mm; left: 0; right: 0; font-size: 7.5pt; }
    </style>
</head>
<body>
    @php
        $tz = 'America/Mexico_City'; // la app corre en UTC; el formato se imprime en hora local
        $unidad = $requisicion->catEgresoUnidadAdministrativa;
        $detalles = $requisicion->tbEgresoRequisicionDetalles ?? collect();
        $fecha = \Carbon\Carbon::parse($requisicion->solicitado ?? $requisicion->registro ?? now($tz))->locale('es');
        $cantidad = fn ($c) => is_numeric($c) ? (fmod((float) $c, 1) == 0 ? number_format((float) $c, 0) : number_format((float) $c, 2)) : $c;
    @endphp

    <div class="pie">
        {{ now($tz)->locale('es')->isoFormat('DD/MM/YYYY h:mm a') }}
        {{-- "Página N de M" y el folio los escribe el controlador sobre el PDF --}}
    </div>

    <table class="encabezado">
        <tr>
            <td style="width: 118px;"><img class="logo" src="{{ public_path('img/logo_guindo.png') }}" alt="Ayuntamiento de Guadalupe"></td>
            <td style="width: 14px;"><div class="separador"></div></td>
            <td class="titulo"><span class="chico">FORMATO ÚNICO DE</span><br><span class="grande">REQUISICIÓN</span></td>
        </tr>
    </table>

    <table class="datos">
        <tr>
            <td class="etiqueta" style="width: 25%;">Fecha:</td>
            <td class="etiqueta">Dirección o Unidad administrativa:</td>
            <td style="width: 22%;"></td>
        </tr>
        <tr>
            <td class="valor">{{ $fecha->isoFormat('D [de] MMMM [de] YYYY') }}</td>
            <td class="valor">{{ mb_strtoupper($unidad?->nombre ?? '—') }}</td>
            <td class="folio">FUR {{ $requisicion->folio_completo }}</td>
        </tr>
    </table>

    <table class="renglones">
        <thead>
            <tr>
                <th style="width: 8%;">Fuente</th>
                <th style="width: 9%;">Proy</th>
                <th style="width: 8%;">Partida</th>
                <th style="width: 8%;">Cantidad</th>
                <th class="texto">Artículo / material / Servicio</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($detalles as $d)
                <tr class="{{ $loop->last ? 'ultimo' : '' }}">
                    <td>{{ $d->fuente }}</td>
                    <td>{{ $d->proyecto }}</td>
                    <td>{{ $d->partida }}</td>
                    <td>{{ $cantidad($d->cantidad) }}</td>
                    <td class="texto">{!! nl2br(e($d->descripcion)) !!}</td>
                </tr>
            @empty
                <tr class="ultimo"><td colspan="5">Sin conceptos.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="concepto">CONCEPTO: {{ mb_strtoupper($requisicion->observaciones ?: '—') }}</div>

    @php
        // registro lo guarda MySQL en hora local.
        $fechaFirma = $requisicion->registro ? \Carbon\Carbon::parse($requisicion->registro, 'America/Mexico_City') : null;
        $unidadId = $requisicion->id_cat_egreso_unidad_administrativa;
        // Solicitante: quien capturó (título, nombre y cargo) o, si no se sabe, el firmante "Elabora".
        $elabora = \App\Models\Firmante::vigente('requisicion', 'elabora', $unidadId, $fechaFirma);
        $capturo = $requisicion->id_user_laravel ? \App\Models\User::with('regidor')->find($requisicion->id_user_laravel) : null;
        // Autorizó: el regidor del que es enlace quien capturó o, si no, el titular de la secretaría.
        $autoriza = \App\Models\Firmante::titularDe($capturo, 'requisicion', $unidadId, $fechaFirma);
    @endphp
    <table class="firmas">
        <tr>
            <td>Solicitante:</td>
            <td>Autorizó:</td>
        </tr>
        <tr>
            <td class="espacio-firma"></td>
            <td class="espacio-firma"></td>
        </tr>
        <tr>
            <td>
                <div class="firma-linea">
                    {{ mb_strtoupper($capturo?->nombreConTitulo() ?? $elabora?->nombre ?? '') ?: " " }}<br>
                    {{ mb_strtoupper($capturo ? ($capturo->cargo ?? '') : ($elabora?->cargo ?? '')) ?: " " }}
                </div>
            </td>
            <td>
                <div class="firma-linea">
                    {{ mb_strtoupper($autoriza['nombre'] ?? '') ?: " " }}<br>
                    {{ mb_strtoupper($autoriza['cargo'] ?? $unidad?->nombre ?? 'LA UNIDAD ADMINISTRATIVA') }}
                </div>
            </td>
        </tr>
    </table>
</body>
</html>
