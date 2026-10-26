<?php

/** Nómina: una quincena validada (Excel de 3 unidades), otra por validar, un finiquito validado y un laudo en captura. */

require __DIR__.'/comun.php';

use App\Models\Nomina;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/** La plantilla de nómina con un renglón por concepto: [unidad, [partidas…], concepto, importe]. */
function excelNomina(string $nombre, array $conceptos)
{
    $libro = new Spreadsheet;
    $filas = [['Nómina (acomodada de Nomipac)'], [], App\Services\ExcelNomina::PLANTILLA];
    foreach ($conceptos as [$clave, $partidas, $concepto, $importe]) {
        $l = linea($clave, $partidas, $importe);
        $filas[] = [$clave, $l['fuente'], $l['proyecto'], $l['partida'], $concepto, $importe];
    }
    $libro->getActiveSheet()->fromArray($filas);
    $ruta = DEMO.'/archivos/'.$nombre;
    (new Xlsx($libro))->save($ruta);

    return new Illuminate\Http\UploadedFile($ruta, $nombre, null, null, true);
}

/** Registra el trámite y devuelve su id. */
function nomina(array $datos): int
{
    [, $r] = api(GABRIELA, 'POST', 'nominas', $datos);

    return (int) ($r['id'] ?? 0);
}

echo "Nómina\n";
$conceptos = fn (float $factor) => [
    ['210', ['1131'], 'SUELDOS BASE', round(412580.40 * $factor, 2)],
    ['210', ['1321', '1131'], 'PRIMA VACACIONAL', round(38920.15 * $factor, 2)],
    ['530', ['1131'], 'SUELDOS BASE', round(685410.90 * $factor, 2)],
    ['530', ['1211', '1131'], 'HONORARIOS ASIMILABLES A SALARIOS', round(96300.00 * $factor, 2)],
    ['710', ['1131'], 'SUELDOS BASE', round(1254870.35 * $factor, 2)],
    ['710', ['1311', '1131'], 'PRIMA QUINQUENAL', round(41250.00 * $factor, 2)],
];

// 1a quincena de octubre: importada, enviada y validada (genera una CLC por unidad).
dia('2026-10-12 09:00');
$n1 = nomina(['tipo' => 'nomina', 'periodo' => '1a quincena de octubre 2026', 'fecha_pago' => '2026-10-15', 'concepto' => 'Nómina ordinaria',
    'beneficiario' => 'Cuenta pagadora de nómina', 'referencia' => 'Nomipac Q19-2026']);
api(GABRIELA, 'POST', "nominas/{$n1}/importar", [], ['archivo' => excelNomina('nomina_q19_2026.xlsx', $conceptos(1))]);
api(GABRIELA, 'POST', "nominas/{$n1}/enviar");
dia('2026-10-13 11:30');
api(RAUL, 'POST', "nominas/{$n1}/validar");

// 2a quincena: importada y enviada, en espera de Control Presupuestal.
dia('2026-10-26 09:15');
$n2 = nomina(['tipo' => 'nomina', 'periodo' => '2a quincena de octubre 2026', 'fecha_pago' => '2026-10-30', 'concepto' => 'Nómina ordinaria',
    'beneficiario' => 'Cuenta pagadora de nómina', 'referencia' => 'Nomipac Q20-2026']);
api(GABRIELA, 'POST', "nominas/{$n2}/importar", [], ['archivo' => excelNomina('nomina_q20_2026.xlsx', $conceptos(1.004))]);
api(GABRIELA, 'POST', "nominas/{$n2}/enviar");

// Cuotas del IMSS del mes: en captura.
$ss = nomina(['tipo' => 'seguridad_social', 'periodo' => 'Septiembre 2026', 'fecha_pago' => '2026-10-17', 'concepto' => 'Cuotas obrero-patronales',
    'beneficiario' => 'Instituto Mexicano del Seguro Social', 'referencia' => 'SUA 09-2026']);
api(GABRIELA, 'POST', "nominas/{$ss}/importar", [], ['archivo' => excelNomina('imss_09_2026.xlsx', [
    ['210', ['1413', '1411', '1131'], 'CUOTAS AL IMSS', 61240.50], ['530', ['1413', '1411', '1131'], 'CUOTAS AL IMSS', 98710.20], ['710', ['1413', '1411', '1131'], 'CUOTAS AL IMSS', 172330.80],
])]);

// Finiquito: capturado a mano, validado.
dia('2026-10-20 10:00');
$fin = nomina(['tipo' => 'finiquito', 'fecha_pago' => '2026-10-24', 'concepto' => 'Finiquito por renuncia voluntaria',
    'beneficiario' => 'Martín Gallegos Pérez', 'beneficiario_numero' => '2087', 'referencia' => 'Oficio RH/0412/2026']);
foreach ([['1131', 'SUELDO PENDIENTE (10 DÍAS)', 5840.00], ['1322', 'AGUINALDO PROPORCIONAL', 9125.50], ['1321', 'PRIMA VACACIONAL PROPORCIONAL', 1460.00]] as [$partida, $concepto, $importe]) {
    $l = linea('530', [$partida, '1131'], $importe);
    api(GABRIELA, 'POST', "nominas/{$fin}/renglones", ['id_cat_egreso_unidad_administrativa' => unidad('530'), 'fuente' => $l['fuente'], 'proyecto' => $l['proyecto'],
        'partida' => $l['partida'], 'concepto' => $concepto, 'importe' => $importe]);
}
api(GABRIELA, 'POST', "nominas/{$fin}/enviar");
dia('2026-10-21 12:00');
api(RAUL, 'POST', "nominas/{$fin}/validar");

// Laudo: en captura, con su expediente.
dia('2026-10-23 13:00');
$lau = nomina(['tipo' => 'laudo', 'fecha_pago' => '2026-11-05', 'concepto' => 'Cumplimiento de laudo laboral',
    'beneficiario' => 'Ernesto Villalobos Castro', 'referencia' => 'Expediente TCA-318/2024']);
$l = linea('710', ['1521', '1131'], 186400);
api(GABRIELA, 'POST', "nominas/{$lau}/renglones", ['id_cat_egreso_unidad_administrativa' => unidad('710'), 'fuente' => $l['fuente'], 'proyecto' => $l['proyecto'],
    'partida' => $l['partida'], 'concepto' => 'INDEMNIZACIÓN Y SALARIOS CAÍDOS', 'importe' => 186400]);

echo '  '.Nomina::count().' trámites de nómina, '.App\Models\OrdenCompra::whereNotNull('nomina_id')->count()." CLC\n";
fin();
