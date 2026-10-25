<?php

/** Trámites de servicios, fondos revolventes, contratos de arrendamiento y bitácoras de combustible. */

require __DIR__.'/comun.php';

use App\Models\Contrato;
use App\Models\Proveedor;
use App\Models\TramiteServicio;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$prov = fn (string $rfc) => Proveedor::where('rfc', $rfc)->firstOrFail();

/** Clasifica todas las facturas de un trámite de servicios con la línea de esa partida. */
function clasificar(string $correo, string $ruta, TramiteServicio $t, string $clave, array $partidas, string $concepto): void
{
    $l = linea($clave, $partidas);
    foreach ($t->facturas()->pluck('id') as $f) {
        api($correo, 'PUT', "{$ruta}/{$t->id}/facturas/{$f}", ['partida' => $l['partida'], 'proyecto' => $l['proyecto'], 'fuente' => $l['fuente'], 'concepto' => $concepto]);
    }
}

echo "Trámites de servicios\n";
$lib = $prov('LIB1704116M1');
dia('2026-08-10 09:30');
[, $r] = api(CARLOS, 'POST', 'servicios', ['id_cat_egreso_unidad_administrativa' => unidad('210'), 'concepto' => 'Servicio de limpieza profunda de las oficinas de la Secretaría de Gobierno']);
$ts1 = TramiteServicio::find($r['id']);
foreach ([['A', '3381', '2026-07-31', 'LIMPIEZA PROFUNDA DE OFICINAS, JULIO', 14800], ['A', '3412', '2026-08-07', 'LAVADO DE ALFOMBRAS Y CORTINAS', 6200]] as [$serie, $folio, $fecha, $desc, $monto]) {
    [$xml] = cfdi($lib->rfc, $lib->nombre, $serie, $folio, $fecha, [[$desc, 1, $monto, true]]);
    api(CARLOS, 'POST', "servicios/{$ts1->id}/facturas", [], ['xml' => $xml]);
}
clasificar(CARLOS, 'servicios', $ts1, '210', ['3581'], 'SERVICIO DE LIMPIEZA DE OFICINAS');
foreach ($ts1->facturas()->pluck('id') as $f) {
    api(CARLOS, 'POST', "servicios/{$ts1->id}/facturas/{$f}/pdf", [], ['pdf' => pdfFalso("ts_{$f}.pdf", 'Factura de servicio de limpieza')]);
}
api(CARLOS, 'POST', "servicios/{$ts1->id}/enviar");
dia('2026-08-12 11:00');
api(MONICA, 'POST', "servicios/{$ts1->id}/validar");

dia('2026-10-14 10:00');
[, $r] = api(ELENA, 'POST', 'servicios', ['id_cat_egreso_unidad_administrativa' => unidad('530'), 'concepto' => 'Lavado y desinfección de camiones recolectores']);
$ts2 = TramiteServicio::find($r['id']);
[$xml] = cfdi($lib->rfc, $lib->nombre, 'A', '3590', '2026-10-10', [['LAVADO Y DESINFECCIÓN DE CAMIÓN RECOLECTOR', 8, 1150, true]]);
api(ELENA, 'POST', "servicios/{$ts2->id}/facturas", [], ['xml' => $xml]);
[$xml] = cfdi($lib->rfc, $lib->nombre, 'A', '3604', '2026-10-13', [['LAVADO DE CONTENEDORES', 12, 380, true]]);
api(ELENA, 'POST', "servicios/{$ts2->id}/facturas", [], ['xml' => $xml]);
$l = linea('530', ['3581']);
api(ELENA, 'PUT', "servicios/{$ts2->id}/facturas/".$ts2->facturas()->value('id'), ['partida' => $l['partida'], 'proyecto' => $l['proyecto'], 'fuente' => $l['fuente'], 'concepto' => 'LAVADO DE CAMIONES RECOLECTORES']);

echo "Fondos revolventes\n";
$fondo = function (string $correo, string $clave, string $fecha, string $concepto, array $trabajador, array $facturas) {
    dia($fecha);
    [, $r] = api($correo, 'POST', 'fondos', ['id_cat_egreso_unidad_administrativa' => unidad($clave), 'concepto' => $concepto,
        'trabajador_nombre' => $trabajador[0], 'trabajador_numero' => $trabajador[1], 'trabajador_puesto' => $trabajador[2]]);
    $t = TramiteServicio::find($r['id']);
    foreach ($facturas as [$rfc, $nombre, $folio, $fechaF, $conceptos, $tipo, $comision, $partida, $desc]) {
        [$xml] = cfdi($rfc, $nombre, 'B', $folio, $fechaF, $conceptos);
        api($correo, 'POST', "fondos/{$t->id}/facturas", [], ['xml' => $xml]);
        $f = $t->facturas()->reorder()->latest("id")->first();
        $l = linea($clave, [$partida]);
        api($correo, 'PUT', "fondos/{$t->id}/facturas/{$f->id}", ['concepto' => $desc, 'tipo_gasto' => $tipo, 'comision' => $comision,
            'articulos' => array_fill(0, count($f->conceptos), ['partida' => $l['partida'], 'proyecto' => $l['proyecto'], 'fuente' => $l['fuente']])]);
    }

    return $t;
};
$fr1 = $fondo(CARLOS, '210', '2026-09-15 09:00', 'Reposición del fondo revolvente de agosto de la Secretaría de Gobierno', ['MARTHA GÓMEZ RUIZ', '10482', 'AUXILIAR ADMINISTRATIVA'], [
    ['HCO050510AB1', 'HOTEL COLONIAL DE ZACATECAS, S.A. DE C.V.', '22841', '2026-08-20', [['HOSPEDAJE HABITACIÓN SENCILLA (2 NOCHES)', 2, 750, true]], 'viaticos',
        'Guadalupe – Ciudad de México, 19 y 20 de agosto, entrega de documentación ante la Secretaría de Gobernación', '3752', 'Hospedaje en comisión'],
    ['RLP120304CD2', 'RESTAURANTE LA PLAZUELA, S.A. DE C.V.', '9071', '2026-08-21', [['CONSUMO DE ALIMENTOS', 1, 860, true]], 'alimentos', null, '2211', 'Alimentos de reunión de trabajo'],
    ['PSC0503147K2', 'PAPELERÍA Y SUMINISTROS DEL CENTRO, S.A. DE C.V.', '4410', '2026-08-25', [['CARPETAS DE ARGOLLA', 10, 48, true], ['MARCADORES PERMANENTES', 12, 22, true]], 'papeleria', null, '2111', 'Material de papelería urgente'],
]);
api(CARLOS, 'POST', "fondos/{$fr1->id}/enviar");
dia('2026-09-17 10:00');
api(LAURA, 'POST', "fondos/{$fr1->id}/validar");

$fr2 = $fondo(ELENA, '530', '2026-10-16 12:00', 'Reposición del fondo revolvente de septiembre de Servicios Públicos', ['JORGE ALBERTO PÉREZ ÁVILA', '20931', 'JEFE DE CUADRILLA'], [
    ['FCR990101EF3', 'FERRETERÍA EL CERRO DE LA BUFA, S.A. DE C.V.', '7720', '2026-09-22', [['GUANTES DE CARNAZA', 20, 45, true], ['CINTA DE AISLAR', 30, 18, true]], 'mantenimiento', null, '2911', 'Material para cuadrillas'],
    ['LCE080808GH4', 'LIMPIEZA Y COMPLEMENTOS DE ZACATECAS, S.A. DE C.V.', '1388', '2026-09-26', [['BOLSA PARA BASURA JUMBO, PAQUETE', 25, 52, true]], 'limpieza', null, '2161', 'Bolsas para basura'],
]);
api(ELENA, 'POST', "fondos/{$fr2->id}/enviar");

echo "Contratos\n";
$olivos = $prov('IOZ1502281P6');
dia('2026-01-08 10:00');
$l = linea('210', ['3221']);
[, $r] = api(CARLOS, 'POST', 'contratos', ['tipo' => 'arrendamiento', 'numero_contrato' => 'SG-ARR-001/2026', 'id_cat_egreso_unidad_administrativa' => unidad('210'),
    'proveedor_id' => $olivos->id, 'concepto' => 'Arrendamiento de la bodega de Protección Civil (calle Juárez 214)', 'fecha_inicio' => '2026-01-01', 'fecha_fin' => '2026-12-31',
    'monto_mensual' => 18000, 'fuente' => $l['fuente'], 'proyecto' => $l['proyecto'], 'partida' => $l['partida']]);
$c1 = Contrato::find($r['id']);
api(CARLOS, 'POST', "contratos/{$c1->id}/enviar");
dia('2026-01-12 10:00');
api(MONICA, 'POST', "contratos/{$c1->id}/autorizar");
foreach (range(1, 9) as $mes) {
    dia(sprintf('2026-%02d-06 10:00', $mes));
    [$xml] = cfdi($olivos->rfc, $olivos->nombre, 'R', (string) (500 + $mes), sprintf('2026-%02d-02', $mes), [['RENTA DE BODEGA, MES '.$mes, 1, round(18000 / 1.16, 2), true]]);
    api(CARLOS, 'POST', "contratos/{$c1->id}/mensualidades", ['periodo' => sprintf('2026-%02d', $mes)], ['xml' => $xml]);
}
dia('2026-06-25 10:00');
$l = linea('530', ['3581']);
[, $r] = api(ELENA, 'POST', 'contratos', ['tipo' => 'servicio', 'numero_contrato' => 'SPM-SRV-014/2026', 'id_cat_egreso_unidad_administrativa' => unidad('530'),
    'proveedor_id' => $lib->id, 'concepto' => 'Servicio de limpieza de la Unidad Deportiva Norte', 'fecha_inicio' => '2026-07-01', 'fecha_fin' => '2026-12-31',
    'monto_mensual' => 9500, 'fuente' => $l['fuente'], 'proyecto' => $l['proyecto'], 'partida' => $l['partida']]);
api(ELENA, 'POST', 'contratos/'.$r['id'].'/enviar');

echo "Combustible (Excel ficticio del proveedor, septiembre)\n";
$mirador = $prov('GMI9806177B3');
$conductores = ['210' => ['LUIS ARMANDO REYES', 'MARISOL VEGA', 'PEDRO SALAS'], '530' => ['JOSÉ MANUEL DÍAZ', 'RICARDO OLIVA', 'ERNESTO LIRA'], '710' => ['OFICIAL M. HERNÁNDEZ', 'OFICIAL R. SÁNCHEZ', 'OFICIAL J. TREJO']];
$vehiculos = ['210' => [['G-014', 'PROTECCIÓN CIVIL'], ['G-022', 'REGISTRO CIVIL'], ['PART', 'PROTECCIÓN CIVIL']], '530' => [['S-031', 'LIMPIA Y RECOLECCIÓN'], ['S-047', 'ALUMBRADO PÚBLICO']],
    '710' => [['P-105', 'PATRULLAJE'], ['P-112', 'PATRULLAJE']]];
$nombres = ['210' => 'SECRETARÍA DE GOBIERNO MUNICIPAL', '530' => 'SECRETARÍA DE SERVICIOS PÚBLICOS MUNICIPALES', '710' => 'DIRECCIÓN DE SEGURIDAD PÚBLICA MUNICIPAL'];
mt_srand(2026);
$filas = [];
$folioN = 4201;
foreach ($vehiculos as $clave => $vs) {
    foreach ([['2026-09-01', '2026-09-15', '2026-09-16'], ['2026-09-16', '2026-09-30', '2026-10-01']] as [$desde, $hasta, $fechaFactura]) {
        $folio = 'GM'.$folioN++;
        for ($i = 0; $i < ($clave === '710' ? 12 : 8); $i++) {
            [$eco, $depto] = $vs[$i % count($vs)];
            $diesel = $eco === 'S-031';
            $precio = $diesel ? 25.89 : 24.19;
            $litros = round(mt_rand(250, 600) / 10, 3);
            $fecha = date('Y-m-d', strtotime($desde) + mt_rand(0, (strtotime($hasta) - strtotime($desde)) / 86400) * 86400);
            $filas[] = [$clave, $fecha, (string) mt_rand(300000, 399999), 'V-'.mt_rand(10000, 99999), $eco, $nombres[$clave], $depto, $conductores[$clave][$i % 3],
                $diesel ? 'DIESEL' : 'MAGNA', $litros, round($litros * $precio, 2), $precio, $fechaFactura, $folio, $mirador->nombre];
        }
    }
}
usort($filas, fn ($a, $b) => strcmp($a[1], $b[1]));
$libro = new Spreadsheet;
$hoja = $libro->getActiveSheet()->setTitle('SEPTIEMBRE');
$hoja->setCellValue('A1', 'GASOLINERA EL MIRADOR, S.A. DE C.V. — REPORTE MENSUAL DE CONSUMO');
$hoja->setCellValue('A2', 'MUNICIPIO DE GUADALUPE, ZAC. — SEPTIEMBRE 2026');
$hoja->fromArray(['CLAVE', 'FECHA DEL TICKET', 'NO. TICKET', 'FOLIO VALE', 'NO. ECO', 'SECRETARÍA', 'DEPARTAMENTO', 'NOMBRE', 'GASOLINA/DIESEL', 'LITROS', 'MONTO',
    'PRECIO POR LITRO', 'FECHA DE FACTURA', 'FOLIO', 'PROVEEDOR'], null, 'A4');
$hoja->fromArray($filas, null, 'A5');
$excel = DEMO.'/archivos/REPORTE MENSUAL SEPTIEMBRE 2026.xlsx';
(new Xlsx($libro))->save($excel);
copy($excel, DEMO.'/REPORTE MENSUAL SEPTIEMBRE 2026.xlsx');

// La unidad de combustibles sube las facturas antes del Excel (quedan por asignar).
dia('2026-10-02 09:00');
$porFolio = collect($filas)->groupBy(13)->map(fn ($g) => round($g->sum(10), 2));
foreach ($porFolio as $folio => $total) {
    [$xml] = cfdi($mirador->rfc, $mirador->nombre, 'GM', substr($folio, 2), '2026-10-01', [['COMBUSTIBLE SEGÚN TICKETS', 1, round($total / 1.16, 2), true]]);
    api(ROCIO, 'POST', 'combustible/facturas', [], ['xml' => $xml]);
}
// Una de octubre: queda por asignar hasta el Excel de octubre.
[$xml] = cfdi($mirador->rfc, $mirador->nombre, 'GM', '4301', '2026-10-16', [['COMBUSTIBLE SEGÚN TICKETS', 1, 18420.50, true]]);
api(ROCIO, 'POST', 'combustible/facturas', [], ['xml' => $xml]);

dia('2026-10-02 10:30');
api(ROCIO, 'POST', 'combustible/lotes', ['periodo' => 'Septiembre 2026', 'hoja' => 'SEPTIEMBRE'], ['archivo' => new Illuminate\Http\UploadedFile($excel, basename($excel), null, null, true)]);
$bitacora = fn (string $clave) => TramiteServicio::where('tipo', 'combustible')->where('id_cat_egreso_unidad_administrativa', unidad($clave))->latest('id')->first();
foreach (['210' => CARLOS, '530' => ELENA] as $clave => $quien) {
    dia('2026-10-05 09:00');
    $b = $bitacora($clave);
    clasificar($quien, 'combustible', $b, $clave, ['2611'], 'CONSUMO DE COMBUSTIBLE SEPTIEMBRE 2026');
    api($quien, 'POST', "combustible/{$b->id}/enviar");
}
dia('2026-10-07 11:00');
api(MONICA, 'POST', 'combustible/'.$bitacora('210')->id.'/validar');

echo '  '.TramiteServicio::count().' trámites (servicios, fondos, contratos y bitácoras), '.Contrato::count()." contratos\n";
fin();
