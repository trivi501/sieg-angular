<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Entrada de almacén {{ $documento->folio_completo }}</title>
    @include('almacen._estilos')
</head>
<body>
    @php
        $tz = 'America/Mexico_City';
        $req = $orden->requisicion;
        $unidad = $req?->catEgresoUnidadAdministrativa;
        $cantidad = fn ($c) => fmod((float) $c, 1) == 0 ? number_format((float) $c, 0) : number_format((float) $c, 2);
        // Precio unitario de la cotización: hasta 4 decimales (al menos 2).
        $precio = fn ($v) => preg_replace('/(\.\d{2}\d*?)0+$/', '$1', number_format((float) $v, 4));
        $total = $documento->detalles->sum(fn ($d) => (float) $d->cantidad * (float) $d->precio_unitario);
        // Recibe: la receptora de insumos (rol "Elabora"); autoriza: el jefe de Recursos Materiales.
        $recibe = \App\Models\Firmante::vigente('entrada_almacen', 'elabora', null, $documento->fecha);
        $autoriza = \App\Models\Firmante::vigente('entrada_almacen', 'autoriza', null, $documento->fecha);
        $factura = $orden->xml_uuid ? (trim(($orden->xml_serie ?? '').' '.($orden->xml_folio ?? '')) ?: $orden->xml_uuid) : null;
    @endphp

    <div class="pie">{{ $documento->folio_completo }} · {{ $orden->folio_completo }} · Requisición {{ $req?->folio_completo }} · Impreso {{ now($tz)->isoFormat('D/MM/YYYY h:mm a') }}</div>

    <table class="encabezado">
        <tr>
            <td style="width: 118px;"><img class="logo" src="{{ public_path('img/logo_guindo.png') }}" alt="Ayuntamiento de Guadalupe"></td>
            <td style="width: 14px;"><div class="separador"></div></td>
            <td class="titulo">ENTRADA DE<br>ALMACÉN</td>
            <td class="folio">{{ $documento->folio_completo }}</td>
        </tr>
    </table>

    <table class="datos">
        <tr>
            <td class="etiqueta">Fecha:</td><td class="valor">{{ $documento->fecha?->format('d/m/Y') }}</td>
            <td class="etiqueta">Orden de compra:</td><td class="valor">{{ $orden->folio_completo }} ({{ $orden->fecha?->format('d/m/Y') }})</td>
        </tr>
        <tr>
            <td class="etiqueta">Proveedor:</td><td class="valor">{{ mb_strtoupper($orden->proveedor?->nombre ?? '') }}</td>
            <td class="etiqueta">Requisición:</td><td class="valor">{{ $req?->folio_completo }}</td>
        </tr>
        <tr>
            <td class="etiqueta">R.F.C:</td><td class="valor">{{ $orden->proveedor?->rfc }}</td>
            <td class="etiqueta">Factura:</td><td class="valor">{{ $factura ?? 'PENDIENTE' }}</td>
        </tr>
        <tr>
            <td class="etiqueta">Área solicitante:</td><td class="valor" colspan="3">{{ mb_strtoupper(trim(($unidad?->clave ?? '').' '.($unidad?->nombre ?? ''))) }}</td>
        </tr>
    </table>

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

    @if ($documento->observaciones)
        <div class="texto">OBSERVACIONES: {{ mb_strtoupper($documento->observaciones) }}</div>
    @endif

    <table class="firmas">
        <tr>
            <td style="width: 50%;">Recibe:<div class="espacio-firma"></div></td>
            <td style="width: 50%;">Autoriza:<div class="espacio-firma"></div></td>
        </tr>
        <tr>
            <td><div class="firma-linea">{{ mb_strtoupper($recibe?->nombre ?? 'DELIA ROMÁN GARCÍA') }}<br>{{ mb_strtoupper($recibe?->cargo ?? 'RECEPTORA DE INSUMOS') }}</div></td>
            <td><div class="firma-linea">{{ mb_strtoupper($autoriza?->nombre ?? 'CARLOS URIEL ESCOBEDO VENEGAS') }}<br>{{ mb_strtoupper($autoriza?->cargo ?? 'JEFE DEL DEPARTAMENTO DE RECURSOS MATERIALES') }}</div></td>
        </tr>
    </table>
</body>
</html>
