<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Salida de almacén {{ $documento->folio_completo }}</title>
    @include('almacen._estilos')
</head>
<body>
    @php
        $tz = 'America/Mexico_City';
        $req = $orden->requisicion;
        $unidad = $documento->unidadAdministrativa ?? $req?->catEgresoUnidadAdministrativa;
        $cantidad = fn ($c) => fmod((float) $c, 1) == 0 ? number_format((float) $c, 0) : number_format((float) $c, 2);
        $fecha = \Carbon\Carbon::parse($documento->fecha);
        // Entrega: el usuario que registra la salida; titular de Recursos Materiales; titular del área
        // (el mismo que autorizó la requisición).
        $entrega = $documento->usuario;
        $titularRm = \App\Models\Firmante::vigente('salida_almacen', 'autoriza', null, $fecha);
        $capturo = $req?->id_user_laravel ? \App\Models\User::with('regidor')->find($req->id_user_laravel) : null;
        $titularArea = \App\Models\Firmante::titularDe($capturo, 'requisicion', $req?->id_cat_egreso_unidad_administrativa, $fecha);
    @endphp

    <div class="pie">{{ $documento->folio_completo }} · {{ $orden->folio_completo }} · Requisición {{ $req?->folio_completo }} · Impreso {{ now($tz)->isoFormat('D/MM/YYYY h:mm a') }}</div>

    <table class="encabezado">
        <tr>
            <td style="width: 118px;"><img class="logo" src="{{ public_path('img/logo_guindo.png') }}" alt="Ayuntamiento de Guadalupe"></td>
            <td style="width: 14px;"><div class="separador"></div></td>
            <td class="titulo">SALIDA DE<br>ALMACÉN</td>
            <td class="folio">{{ $documento->folio_completo }}</td>
        </tr>
    </table>

    <table class="datos">
        <tr>
            <td class="etiqueta">Secretaría:</td><td class="valor">{{ mb_strtoupper(trim(($unidad?->clave ?? '').' '.($unidad?->nombre ?? ''))) }}</td>
            <td class="etiqueta" style="width: 8%;">Fecha:</td><td class="valor" style="width: 14%;">{{ $fecha->format('d/m/Y') }}</td>
        </tr>
        <tr>
            <td class="etiqueta">Orden de compra:</td><td class="valor">{{ $orden->folio_completo }} · Requisición {{ $req?->folio_completo }}</td>
            <td class="etiqueta">Recibe:</td><td class="valor">{{ mb_strtoupper($documento->recibe ?? '') }}</td>
        </tr>
    </table>

    <table class="renglones">
        <thead>
            <tr>
                <th class="centro" style="width: 14%;">Cantidad</th>
                <th style="text-align: left;">Descripción</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($documento->detalles as $d)
                <tr class="{{ $loop->last ? 'ultimo' : '' }}">
                    <td class="centro">{{ $cantidad($d->cantidad) }}</td>
                    <td>{{ mb_strtoupper($d->descripcion) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if ($documento->observaciones)
        <div class="texto">OBSERVACIONES: {{ mb_strtoupper($documento->observaciones) }}</div>
    @endif

    <table class="firmas">
        <tr>
            <td style="width: 33%;">Entrega:<div class="espacio-firma"></div></td>
            <td style="width: 34%;">Recursos Materiales:<div class="espacio-firma"></div></td>
            <td style="width: 33%;">Autoriza (titular del área):<div class="espacio-firma"></div></td>
        </tr>
        <tr>
            <td><div class="firma-linea">{{ mb_strtoupper($entrega?->nombreConTitulo() ?? '') ?: ' ' }}<br>{{ mb_strtoupper($entrega?->cargo ?? 'ALMACÉN') }}</div></td>
            <td><div class="firma-linea">{{ mb_strtoupper($titularRm?->nombre ?? 'CARLOS URIEL ESCOBEDO VENEGAS') }}<br>{{ mb_strtoupper($titularRm?->cargo ?? 'JEFE DEL DEPARTAMENTO DE RECURSOS MATERIALES') }}</div></td>
            <td><div class="firma-linea">{{ mb_strtoupper($titularArea['nombre'] ?? '') ?: ' ' }}<br>{{ mb_strtoupper($titularArea['cargo'] ?? $unidad?->nombre ?? 'TITULAR DEL ÁREA') }}</div></td>
        </tr>
    </table>
</body>
</html>
