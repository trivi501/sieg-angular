<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Cédula {{ $cedula->numero }}</title>
    <style>
        @page { margin: 16mm 16mm 18mm 16mm; }
        body { font-family: Arial, Helvetica, sans-serif; font-size: 10pt; color: #000; }

        table.encabezado { width: 100%; border-collapse: collapse; }
        table.encabezado td { vertical-align: middle; padding: 0; }
        .logo { height: 64px; }
        .separador { border-left: 1px solid #b9b9b9; height: 44px; margin: 0 12px; }
        .titulo { color: #6d6e70; font-weight: bold; line-height: 1.15; }
        .titulo .chico { font-size: 10pt; }
        .titulo .grande { font-size: 15pt; }
        .folio { text-align: right; font-size: 12pt; font-weight: bold; }

        .marco { border: 1.5px solid #7A1F3D; padding: 14px 18px; margin-top: 18px; }
        .nombre { font-size: 15pt; font-weight: bold; margin: 4px 0 10px; }
        table.datos { width: 100%; border-collapse: collapse; }
        table.datos td { padding: 4px 0; vertical-align: top; }
        .etiqueta { color: #555; width: 32%; }

        .vigencia { margin-top: 16px; text-align: center; font-size: 11pt; }
        .vigencia b { color: #7A1F3D; font-size: 13pt; }
        .leyenda { margin-top: 14px; font-size: 8.5pt; color: #333; text-align: justify; }

        .firma { width: 60%; margin: 60px auto 0; text-align: center; font-size: 9.5pt; }
        .linea { border-top: 1px solid #000; padding-top: 4px; }
        .pie { position: fixed; bottom: -10mm; left: 0; right: 0; font-size: 7.5pt; color: #444; }
    </style>
</head>
<body>
    @php
        $tz = 'America/Mexico_City';
        $persona = ['fisica' => 'Persona física', 'moral' => 'Persona moral'][$proveedor->tipo_persona] ?? '—';
    @endphp

    <div class="pie">{{ $cedula->numero }} · {{ $proveedor->rfc }} · Impreso {{ now($tz)->locale('es')->isoFormat('D/MM/YYYY h:mm a') }}</div>

    <table class="encabezado">
        <tr>
            <td style="width: 112px;"><img class="logo" src="{{ public_path('img/logo_guindo.png') }}" alt="Ayuntamiento de Guadalupe"></td>
            <td style="width: 14px;"><div class="separador"></div></td>
            <td class="titulo"><span class="chico">ÓRGANO INTERNO DE CONTROL</span><br><span class="grande">CÉDULA DE PADRÓN DE PROVEEDORES Y CONTRATISTAS</span></td>
            <td class="folio" style="width: 140px;">{{ $cedula->numero }}</td>
        </tr>
    </table>

    <div class="marco">
        <div class="etiqueta">Se hace constar que</div>
        <div class="nombre">{{ $proveedor->nombre }}</div>
        <table class="datos">
            <tr><td class="etiqueta">RFC</td><td><strong>{{ $proveedor->rfc }}</strong></td></tr>
            <tr><td class="etiqueta">Tipo de persona</td><td>{{ $persona }}</td></tr>
            @if ($proveedor->representante_legal)
                <tr><td class="etiqueta">Representante legal</td><td>{{ $proveedor->representante_legal }}</td></tr>
            @endif
            @if ($giro)
                <tr><td class="etiqueta">Giro o actividad</td><td>{{ $giro }}</td></tr>
            @endif
            @if ($proveedor->regimen_fiscal)
                <tr><td class="etiqueta">Régimen fiscal</td><td>{{ $proveedor->regimen_fiscal }}</td></tr>
            @endif
            @if ($proveedor->domicilio)
                <tr><td class="etiqueta">Domicilio</td><td>{{ $proveedor->domicilio }}</td></tr>
            @endif
        </table>
        <p style="margin: 12px 0 0;">
            está inscrito en el Padrón de Proveedores y Contratistas del Municipio de Guadalupe, Zacatecas, al haber integrado su
            expediente y acreditado su opinión de cumplimiento de obligaciones fiscales en sentido positivo.
        </p>
        <div class="vigencia">
            Fecha de emisión: {{ $cedula->fecha_emision->locale('es')->isoFormat('D [de] MMMM [de] YYYY') }}<br>
            Vigente hasta el <b>{{ $cedula->vigencia->locale('es')->isoFormat('D [de] MMMM [de] YYYY') }}</b>
        </div>
    </div>

    <p class="leyenda">
        La presente cédula tiene vigencia de un año a partir de su emisión. El Órgano Interno de Control podrá suspenderla si el proveedor
        deja de cumplir los requisitos del padrón. Con la cédula vencida o suspendida no se le asignan órdenes de compra.
        @if ($cedula->observaciones)
            <br>Observaciones: {{ $cedula->observaciones }}
        @endif
    </p>

    <div class="firma">
        <div class="linea">{{ $cedula->usuario?->name ?? 'Órgano Interno de Control' }}<br>Órgano Interno de Control</div>
    </div>
</body>
</html>
