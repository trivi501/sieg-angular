<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Acta de entrega {{ $documento->folio_completo }}</title>
    @include('almacen._estilos')
</head>
<body>
    @php
        $tz = 'America/Mexico_City';
        $req = $orden->requisicion;
        $fecha = \Carbon\Carbon::parse($documento->fecha)->locale('es');
        $cantidad = fn ($c) => fmod((float) $c, 1) == 0 ? number_format((float) $c, 0) : number_format((float) $c, 2);
        // Precio unitario de la cotización: hasta 4 decimales (al menos 2).
        $precio = fn ($v) => preg_replace('/(\.\d{2}\d*?)0+$/', '$1', number_format((float) $v, 4));
        $total = $documento->detalles->sum(fn ($d) => (float) $d->cantidad * (float) $d->precio_unitario);
        // Recibe por parte de Recursos Materiales: el usuario que registró la entrada.
        $recibe = $documento->usuario;
    @endphp

    <div class="pie">Acta {{ $documento->folio_completo }} · {{ $orden->folio_completo }} · Requisición {{ $req?->folio_completo }} · Impreso {{ now($tz)->isoFormat('D/MM/YYYY h:mm a') }}</div>

    <table class="encabezado">
        <tr>
            <td style="width: 118px;"><img class="logo" src="{{ public_path('img/logo_guindo.png') }}" alt="Ayuntamiento de Guadalupe"></td>
            <td style="width: 14px;"><div class="separador"></div></td>
            <td class="titulo">ACTA DE<br>ENTREGA</td>
            <td class="folio">{{ $orden->folio_completo }}<br>{{ $documento->folio_completo }}</td>
        </tr>
    </table>

    <div class="texto" style="margin-top: 16px;">
        En la ciudad de Guadalupe, Zacatecas, el día {{ $fecha->isoFormat('D [de] MMMM [de] YYYY') }}, el proveedor
        <strong>{{ mb_strtoupper($orden->proveedor?->nombre ?? '') }}</strong>{{ $orden->proveedor?->rfc ? ', con R.F.C. '.$orden->proveedor->rfc.',' : '' }}
        hace entrega al Departamento de Recursos Materiales del Municipio de Guadalupe, Zacatecas, de los bienes y/o servicios
        amparados por la orden de compra <strong>{{ $orden->folio_completo }}</strong> de fecha {{ $orden->fecha?->format('d/m/Y') }}
        (requisición {{ $req?->folio_completo }}), conforme a lo siguiente:
    </div>

    <table class="renglones">
        <thead>
            <tr>
                <th class="centro" style="width: 11%;">Cantidad</th>
                <th style="text-align: left;">Descripción</th>
                <th class="num" style="width: 14%;">P. unitario</th>
                <th class="num" style="width: 14%;">Importe</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($documento->detalles as $d)
                <tr class="{{ $loop->last ? 'ultimo' : '' }}">
                    <td class="centro">{{ $cantidad($d->cantidad) }}</td>
                    <td>{{ mb_strtoupper($d->descripcion) }}</td>
                    <td class="num">{{ $precio($d->precio_unitario) }}</td>
                    <td class="num">{{ number_format((float) $d->cantidad * (float) $d->precio_unitario, 2) }}</td>
                </tr>
            @endforeach
            <tr>
                <td colspan="3" class="num" style="font-weight: bold; padding-top: 4px;">TOTAL:</td>
                <td class="num" style="font-weight: bold; padding-top: 4px;">${{ number_format($total, 2) }}</td>
            </tr>
        </tbody>
    </table>

    <div class="texto">
        Quien recibe hace constar que los bienes y/o servicios descritos se reciben conforme a la orden de compra.
        @if ($documento->observaciones)
            Observaciones: {{ mb_strtoupper($documento->observaciones) }}.
        @endif
        Se firma de conformidad por las partes que en ella intervienen.
    </div>

    <table class="firmas">
        <tr>
            <td style="width: 50%;">Entrega (proveedor):<div class="espacio-firma"></div></td>
            <td style="width: 50%;">Recibe (Recursos Materiales):<div class="espacio-firma"></div></td>
        </tr>
        <tr>
            <td><div class="firma-linea">{{ mb_strtoupper($orden->proveedor?->nombre ?? '') }}<br>PROVEEDOR</div></td>
            <td><div class="firma-linea">{{ mb_strtoupper($recibe?->nombreConTitulo() ?? '') ?: ' ' }}<br>{{ mb_strtoupper($recibe?->cargo ?? 'DEPARTAMENTO DE RECURSOS MATERIALES') }}</div></td>
        </tr>
    </table>
</body>
</html>
