<?php

/** Requisiciones en cada estado del trámite, órdenes de compra, facturas, CLC por todas sus áreas, pagos y conciliación. */

require __DIR__.'/comun.php';
require __DIR__.'/flujo.php';

use App\Models\EgresoRequisicion;
use App\Models\OrdenCompra;
use App\Models\Proveedor;
use App\Models\TesoreriaCuenta;
use App\Models\TesoreriaPago;

$prov = fn (string $rfc) => Proveedor::where('rfc', $rfc)->value('id');

echo "Requisiciones\n";
$papeleria = [['HOJAS BOND CARTA, CAJA CON 10 PAQUETES', 12, ['2111']], ['FOLDERS TAMAÑO CARTA, CAJA CON 100', 8, ['2111']], ['TÓNER HP 58A ORIGINAL', 6, ['2111']]];

// 1. Pagada por transferencia y conciliada.
$r1 = requisicion(CARLOS, '210', '2026-03-03 09:30', 'Papelería y consumibles para las oficinas de la Secretaría de Gobierno, primer trimestre', $papeleria);
$oc1 = avanzar($r1, 'tramite_egresos', [1180, 245, 1890], $prov('PSC0503147K2'));

// 2. Pagada con cheque, ya entregado.
$r2 = requisicion(CARLOS, '210', '2026-04-02 10:15', 'Refacciones de cómputo para el área de Protección Civil', [['UNIDAD DE ESTADO SÓLIDO SSD 1 TB', 4, ['2941']], ['MEMORIA RAM DDR4 16 GB', 4, ['2941']]]);
$oc2 = avanzar($r2, 'tramite_egresos', [1650, 980], $prov('CRG1108239T5'));

// 3. Recibida en Egresos: por pagar en la mesa de control.
$r3 = requisicion(LAURA, '710', '2026-06-02 09:00', 'Llantas para patrullas de la Dirección de Seguridad Pública', [['LLANTA 225/60 R17 PARA PATRULLA', 16, ['2961', '2981']], ['ALINEACIÓN Y BALANCEO', 4, ['3551', '2961']]]);
$oc3 = avanzar($r3, 'tramite_egresos', [2650, 690], $prov('SAZ0911054R8'), LAURA);
$r3b = requisicion(CARLOS, '210', '2026-06-04 11:00', 'Material de limpieza para el edificio de la Secretaría de Gobierno', [['DETERGENTE INDUSTRIAL 20 L', 6, ['2161']], ['CLORO 20 L', 6, ['2161']]]);
$oc3b = avanzar($r3b, 'tramite_egresos', [780, 410], $prov('PSC0503147K2'));

// 4. CLC en Sindicatura (firma).
$r4 = requisicion(ELENA, '530', '2026-07-02 09:45', 'Señalética para el programa de alumbrado público', [['SEÑAL RESTRICTIVA 60X60 CM', 40, ['2461']], ['POSTE TUBULAR 2.5 M', 40, ['2461']]]);
avanzar($r4, 'tramite_sindicatura', [520, 380], $prov('IDB1210089Q4'), ELENA);

// 5. CLC generada, todavía en el área.
$r5 = requisicion(CARLOS, '210', '2026-08-03 10:00', 'Impresión de formatos del Registro Civil', [['FORMATO DE ACTA DE NACIMIENTO, MILLAR', 20, ['2111']]]);
avanzar($r5, 'clc', [1450], $prov('IDB1210089Q4'));

// 6. Orden de compra sin factura.
$r6 = requisicion(LAURA, '310', '2026-09-01 09:20', 'Consumibles de cómputo para la Dirección de Ingresos', [['TÓNER BROTHER TN-760', 10, ['2141', '2111']], ['MOUSE ÓPTICO USB', 15, ['2141', '2111']]]);
avanzar($r6, 'orden', [1320, 185], $prov('CRG1108239T5'), LAURA);

// 7. Comprometida (con suficiencia, sin orden).
$r7 = requisicion(ELENA, '530', '2026-09-02 12:00', 'Herramienta para cuadrillas de alumbrado público', [['ESCALERA DE FIBRA DE VIDRIO 8 PELDAÑOS', 4, ['2911']], ['PINZAS DE ELECTRICISTA', 12, ['2911']]]);
avanzar($r7, 'suficiencia', [3900, 420], null, ELENA);

// 8. En cotización (Recursos Materiales).
$r8 = requisicion(LAURA, '310', '2026-10-01 09:10', 'Mobiliario para la sala de juntas de la Tesorería', [['MESA DE JUNTAS PARA 10 PERSONAS', 1, ['5111']], ['SILLA EJECUTIVA', 10, ['5111']]]);
avanzar($r8, 'recibir', [], null, LAURA);

// 9. Enviada a Recursos Materiales.
$r9 = requisicion(ELENA, '530', '2026-10-02 10:30', 'Uniformes para el personal de limpia', [['CAMISOLA DE TRABAJO CON REFLEJANTE', 60, ['2711']], ['PANTALÓN DE MEZCLILLA', 60, ['2711']]]);
avanzar($r9, 'recursos', [], null, ELENA);

// 10. En revisión.
$r10 = requisicion(LAURA, '310', '2026-10-05 09:00', 'Curso de actualización fiscal para el personal de Ingresos', [['CURSO DE ACTUALIZACIÓN FISCAL (20 PERSONAS)', 1, ['3341']]]);
avanzar($r10, 'enviar', [], null, LAURA);

// 11. Pendiente (en captura).
requisicion(LAURA, '710', '2026-10-06 13:00', 'Botiquines para unidades de patrullaje', [['BOTIQUÍN DE PRIMEROS AUXILIOS EQUIPADO', 25, ['2531']]]);

// 12. Rechazada (no válida).
$r12 = requisicion(CARLOS, '210', '2026-09-03 11:00', 'Arreglos florales para evento cívico', [['ARREGLO FLORAL GRANDE', 6, ['3821']]]);
dia('2026-09-04 10:00');
api(CARLOS, 'POST', "requisiciones/{$r12->id_tb_egreso_requisicion}/enviar");
api(LAURA, 'POST', "requisiciones/{$r12->id_tb_egreso_requisicion}/rechazar", ['motivo' => 'La partida no corresponde: los gastos de eventos van con la Coordinación de Eventos.']);

// 13. Extemporánea por autorizar (capturada fuera del periodo).
requisicion(CARLOS, '210', '2026-10-20 16:40', 'Servicio de cafetería para la reunión regional de Protección Civil', [['SERVICIO DE CAFETERÍA (80 PERSONAS)', 1, ['2211']]]);

// 14. Cancelada.
$r14 = requisicion(LAURA, '310', '2026-05-04 09:00', 'Renovación de licencias de antivirus', [['LICENCIA DE ANTIVIRUS ANUAL', 30, ['3391']]]);
dia('2026-05-06 10:00');
api(LAURA, 'POST', "requisiciones/{$r14->id_tb_egreso_requisicion}/cancelar", ['motivo' => 'Se cubrirá con el contrato marco de la Secretaría de Finanzas.']);

echo "Tesorería: pagos y conciliación\n";
$cuenta = TesoreriaCuenta::where('fuente', '111')->value('id');
$cuentaDe = fn (OrdenCompra $oc) => TesoreriaCuenta::where('fuente', App\Models\EgresoRequisicionDetalle::where('id_tb_egreso_requisicion', $oc->id_tb_egreso_requisicion)->value('fuente') ?? '111')->value('id') ?? $cuenta;
dia('2026-04-10 12:00');
api(HECTOR, 'POST', 'tesoreria/pagos', ['ordenes' => [$oc1->id], 'pago_forma' => 'transferencia', 'pago_fecha' => '2026-04-10', 'cuenta_id' => $cuentaDe($oc1->fresh())]);
dia('2026-05-12 12:00');
api(HECTOR, 'POST', 'tesoreria/pagos', ['ordenes' => [$oc2->id], 'pago_forma' => 'cheque', 'pago_referencia' => '0004518', 'pago_fecha' => '2026-05-12', 'cuenta_id' => $cuentaDe($oc2->fresh())]);
$cheque = TesoreriaPago::where('forma', 'cheque')->latest('id')->first();
dia('2026-05-14 10:00');
api(HECTOR, 'POST', "tesoreria/pagos/{$cheque->id}/entregar", ['entregado_a' => 'DIANA OCHOA MURILLO (representante del proveedor)']);

// Estado de cuenta: el SPEI del pago 1, el cheque cobrado y depósitos.
$trf = TesoreriaPago::where('forma', 'transferencia')->latest('id')->first();
dia('2026-05-31 18:00');
foreach ([
    [$trf->cuenta_id, '2026-04-10', 'cargo', $trf->importe, $trf->numero, 'BNET0102'.$trf->numero, 'SPEI ENVIADO PAPELERIA Y SUMINISTROS'],
    [$cheque->cuenta_id, '2026-05-20', 'cargo', $cheque->importe, 'CHQ 0004518', null, 'CHEQUE PAGADO 0004518'],
    [$cuenta, '2026-04-15', 'abono', 1250000, 'PARTICIPACIONES', null, 'DEPÓSITO PARTICIPACIONES ABRIL'],
    [$cuenta, '2026-05-15', 'abono', 1310000, 'PARTICIPACIONES', null, 'DEPÓSITO PARTICIPACIONES MAYO'],
    [$cuenta, '2026-05-28', 'cargo', 2320.00, 'COMISIONES', null, 'COMISIONES BANCARIAS MAYO'],
] as [$c, $fecha, $tipo, $importe, $ref, $rastreo, $concepto]) {
    api(HECTOR, 'POST', 'tesoreria/movimientos', ['cuenta_id' => $c, 'fecha' => $fecha, 'tipo' => $tipo, 'importe' => $importe, 'referencia' => $ref, 'clave_rastreo' => $rastreo, 'concepto' => $concepto]);
}
api(HECTOR, 'POST', 'tesoreria/pagos/conciliar-automatico');

echo '  '.EgresoRequisicion::count().' requisiciones, '.OrdenCompra::count().' órdenes, '.TesoreriaPago::count()." pagos\n";
fin();
