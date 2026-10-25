<?php

/**
 * Base común de los scripts que llenan la base de DEMOSTRACIÓN (sieg_demo) con datos ficticios para las capturas del
 * manual. Nunca toca la base sieg: fuerza DB_DATABASE=sieg_demo y un storage aparte.
 */

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

const DEMO = __DIR__;
/** Raíz del backend (este generador vive en docs/manuales/generador). */
define('BACKEND', dirname(__DIR__, 3).'/backend');
foreach (['storage/app/private', 'storage/app/public', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'archivos'] as $carpeta) {
    @mkdir(DEMO.'/'.$carpeta, 0775, true);
}
foreach (['DB_DATABASE' => 'sieg_demo', 'LARAVEL_STORAGE_PATH' => DEMO.'/storage', 'APP_ENV' => 'local', 'CACHE_STORE' => 'array', 'SAT_USOS_CFDI' => 'G01,G03,I01,I02,I03,I04,I05,I06,I07,I08,S01'] as $k => $v) {
    putenv("{$k}={$v}");
    $_ENV[$k] = $v;
    $_SERVER[$k] = $v;
}

require BACKEND.'/vendor/autoload.php';
$app = require BACKEND.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();
$app->instance('request', Illuminate\Http\Request::create('/'));
if (config('database.connections.mysql.database') !== 'sieg_demo') {
    exit("No es la base de demostración.\n");
}

const CLAVE = 'Demo-SIEG-2026';

/** Correos del elenco ficticio. */
const LAURA = 'laura.mendez@sieg.demo';      // Super Admin (Subsecretaría de Egresos)
const CARLOS = 'carlos.ibarra@sieg.demo';    // Enlace administrativo de la Secretaría de Gobierno (210)
const ELENA = 'elena.zamora@sieg.demo';      // Enlace de Servicios Públicos (530)
const MONICA = 'monica.salas@sieg.demo';     // Recursos Materiales
const RAUL = 'raul.cardenas@sieg.demo';      // Control Presupuestal
const SOFIA = 'sofia.delgado@sieg.demo';     // Sindicatura
const HECTOR = 'hector.valdez@sieg.demo';    // Egresos / Tesorería
const ROCIO = 'rocio.esparza@sieg.demo';     // Control de combustible
const ANDRES = 'andres.robles@sieg.demo';    // Órgano Interno de Control
const JAVIER = 'javier.nunez@sieg.demo';     // Taller (mantenimiento vehicular)
const PATRICIA = 'patricia.lugo@sieg.demo';  // Contabilidad
const ANA = 'ana.torres@sieg.demo';          // Área solicitante con permisos mínimos (para la captura del menú reducido)

$errores = 0;

/** Llama a la API como ese usuario. Si falla y $exigir, lo dice (y cuenta el error). */
function api(string $correo, string $metodo, string $uri, array $datos = [], array $archivos = [], bool $exigir = true): array
{
    global $app, $kernel, $errores;
    $req = Illuminate\Http\Request::create('/api/'.$uri, $metodo, $datos, [], $archivos, ['HTTP_ACCEPT' => 'application/json']);
    $app->instance('request', $req);
    $app['auth']->forgetGuards();
    Laravel\Sanctum\Sanctum::actingAs(User::where('email', $correo)->firstOrFail(), ['*']);
    $res = $kernel->handle($req);
    $cuerpo = $res instanceof Symfony\Component\HttpFoundation\StreamedResponse || $res instanceof Symfony\Component\HttpFoundation\BinaryFileResponse ? null : json_decode((string) $res->getContent(), true);
    $s = $res->getStatusCode();
    if ($exigir && $s >= 400) {
        $errores++;
        $m = collect($cuerpo['errors'] ?? [])->flatten()->first() ?? ($cuerpo['message'] ?? '');
        echo "  ✘ {$metodo} {$uri} → {$s}: {$m}\n";
    }

    return [$s, $cuerpo];
}

/** Fecha simulada (hora de México). */
function dia(string $fecha): void
{
    Carbon::setTestNow(Carbon::parse($fecha, 'America/Mexico_City'));
    // Lo que la base llena sola (DEFAULT CURRENT_TIMESTAMP, p. ej. la bitácora de la requisición) también va con la fecha simulada.
    Illuminate\Support\Facades\DB::statement('SET TIMESTAMP = '.Carbon::now()->timestamp);
}

function archivo(string $nombre, string $contenido, ?string $tipo = null): UploadedFile
{
    $ruta = DEMO.'/archivos/'.$nombre;
    file_put_contents($ruta, $contenido);

    return new UploadedFile($ruta, $nombre, $tipo, null, true);
}

function pdfFalso(string $nombre, string $titulo = 'Documento de demostración'): UploadedFile
{
    $texto = str_replace(['(', ')'], '', $titulo);
    $flujo = "BT /F1 18 Tf 72 720 Td ({$texto}) Tj ET BT /F1 11 Tf 72 690 Td (Documento ficticio para el manual de SIEG) Tj ET";
    $objs = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
        '<< /Length '.strlen($flujo)." >>\nstream\n{$flujo}\nendstream",
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    ];
    $pdf = "%PDF-1.4\n";
    $pos = [];
    foreach ($objs as $i => $o) {
        $pos[] = strlen($pdf);
        $pdf .= ($i + 1)." 0 obj\n{$o}\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 ".(count($objs) + 1)."\n0000000000 65535 f \n".implode('', array_map(fn ($p) => sprintf("%010d 00000 n \n", $p), $pos));
    $pdf .= 'trailer << /Size '.(count($objs) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";

    return archivo($nombre, $pdf, 'application/pdf');
}

$uuidN = 0;

/**
 * CFDI 4.0 ficticio. $conceptos: [[descripcion, cantidad, valor unitario, iva?], ...] (el IVA es 16 % por concepto).
 *
 * @return array{0: UploadedFile, 1: string} El archivo y su UUID
 */
function cfdi(string $rfc, string $nombre, string $serie, string $folio, string $fecha, array $conceptos, float $isrRetenido = 0): array
{
    global $uuidN;
    $uuidN++;
    $uuid = strtoupper(sprintf('%08X-%04X-4%03X-8%03X-%012X', crc32($rfc.$folio), $uuidN, $uuidN % 4096, $uuidN % 4096, crc32($serie.$folio.$uuidN)));
    $subtotal = 0.0;
    $iva = 0.0;
    $xmlConceptos = '';
    foreach ($conceptos as [$desc, $cant, $unit, $conIva]) {
        $imp = round($cant * $unit, 2);
        $ivaC = $conIva ?? true ? round($imp * 0.16, 2) : 0.0;
        $subtotal += $imp;
        $iva += $ivaC;
        $desc = htmlspecialchars($desc, ENT_XML1);
        $xmlConceptos .= "<cfdi:Concepto ClaveProdServ=\"14111500\" Cantidad=\"{$cant}\" ClaveUnidad=\"H87\" Unidad=\"PIEZA\" Descripcion=\"{$desc}\" ValorUnitario=\"{$unit}\" Importe=\"{$imp}\" ObjetoImp=\"02\">"
            ."<cfdi:Impuestos><cfdi:Traslados><cfdi:Traslado Base=\"{$imp}\" Impuesto=\"002\" TipoFactor=\"Tasa\" TasaOCuota=\"0.160000\" Importe=\"{$ivaC}\"/></cfdi:Traslados></cfdi:Impuestos></cfdi:Concepto>";
    }
    $subtotal = round($subtotal, 2);
    $iva = round($iva, 2);
    $total = round($subtotal + $iva - $isrRetenido, 2);
    $retenciones = $isrRetenido > 0 ? "<cfdi:Retenciones><cfdi:Retencion Impuesto=\"001\" Importe=\"{$isrRetenido}\"/></cfdi:Retenciones>" : '';
    $totRet = $isrRetenido > 0 ? " TotalImpuestosRetenidos=\"{$isrRetenido}\"" : '';
    $nombre = htmlspecialchars($nombre, ENT_XML1);
    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4" xmlns:tfd="http://www.sat.gob.mx/TimbreFiscalDigital" Version="4.0" Serie="{$serie}" Folio="{$folio}" Fecha="{$fecha}T11:20:00" SubTotal="{$subtotal}" Moneda="MXN" Total="{$total}" TipoDeComprobante="I" Exportacion="01" MetodoPago="PPD" FormaPago="99" LugarExpedicion="98600" Sello="SELLODEMOSTRACION0000000000000000">
  <cfdi:Emisor Rfc="{$rfc}" Nombre="{$nombre}" RegimenFiscal="601"/>
  <cfdi:Receptor Rfc="MGU6501015R2" Nombre="MUNICIPIO DE GUADALUPE" DomicilioFiscalReceptor="98600" RegimenFiscalReceptor="603" UsoCFDI="G03"/>
  <cfdi:Conceptos>{$xmlConceptos}</cfdi:Conceptos>
  <cfdi:Impuestos TotalImpuestosTrasladados="{$iva}"{$totRet}>{$retenciones}<cfdi:Traslados><cfdi:Traslado Base="{$subtotal}" Impuesto="002" TipoFactor="Tasa" TasaOCuota="0.160000" Importe="{$iva}"/></cfdi:Traslados></cfdi:Impuestos>
  <cfdi:Complemento><tfd:TimbreFiscalDigital Version="1.1" UUID="{$uuid}" FechaTimbrado="{$fecha}T11:25:00" RfcProvCertif="SAT970701NN3" SelloCFD="X" NoCertificadoSAT="00001000000500000000" SelloSAT="X"/></cfdi:Complemento>
</cfdi:Comprobante>
XML;

    return [archivo("{$uuid}.xml", $xml, 'text/xml'), $uuid];
}

/** El SAT de demostración: todo vigente. */
Http::fake(['*' => Http::response('<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body><ConsultaResponse xmlns="http://tempuri.org/"><ConsultaResult xmlns:a="x"><a:CodigoEstatus>S - Comprobante obtenido satisfactoriamente.</a:CodigoEstatus><a:Estado>Vigente</a:Estado><a:EsCancelable>Cancelable con aceptación</a:EsCancelable><a:EstatusCancelacion/><a:ValidacionEFOS>200</a:ValidacionEFOS></ConsultaResult></ConsultaResponse></s:Body></s:Envelope>')]);

/** Línea de presupuesto de la secretaría con la primera de esas partidas que tenga disponible suficiente. */
function linea(string $clave, array $partidas, float $monto = 0): array
{
    $lineas = app(App\Services\PresupuestoService::class)->lineas([$clave]);
    foreach ($partidas as $p) {
        $l = $lineas->where('partida', $p)->where('disponible', '>=', $monto)->sortByDesc('disponible')->first();
        if ($l) {
            return $l;
        }
    }
    throw new RuntimeException("La {$clave} no tiene presupuesto en ".implode(', ', $partidas));
}

function unidad(string $clave): int
{
    return (int) App\Models\EgresoUnidadAdministrativa::where('clave', $clave)->orderByDesc('activo')->value('id_cat_egreso_unidad_administrativa');
}

function fin(): void
{
    global $errores;
    Carbon::setTestNow();
    Illuminate\Support\Facades\DB::statement('SET TIMESTAMP = DEFAULT');
    echo $errores ? "\n✘ {$errores} error(es)\n" : "\n✔ Listo\n";
}
