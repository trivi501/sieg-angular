<?php

/** Mantenimiento vehicular, modificaciones presupuestales, almacén y tickets de soporte. */

require __DIR__.'/comun.php';
require __DIR__.'/flujo.php';

use App\Models\OrdenCompra;
use App\Models\Proveedor;
use App\Models\SupportTicket;
use App\Models\TesoreriaCuenta;
use App\Models\Vehiculo;
use App\Models\VehiculoMantenimiento;

$taller = Proveedor::where('rfc', 'SAZ0911054R8')->value('id');
$vehiculo = fn (string $eco) => Vehiculo::where('numero_economico', $eco)->firstOrFail();

echo "Mantenimiento vehicular\n";
/** Recibe el vehículo y, si se indica, hace su requisición y la avanza. */
$servicio = function (string $eco, string $fecha, string $tipo, int $km, string $falla, ?array $req = null, ?string $hasta = null, ?string $entrega = null) use ($vehiculo, $taller) {
    $v = $vehiculo($eco);
    dia($fecha);
    api(JAVIER, 'POST', 'vehiculos/mantenimientos', ['vehiculo_id' => $v->id, 'fecha_recepcion' => substr($fecha, 0, 10), 'kilometraje' => $km, 'tipo' => $tipo,
        'descripcion' => $falla, 'entrega' => $v->resguardante]);
    $m = VehiculoMantenimiento::latest('id')->first();
    if ($req) {
        $clave = $v->unidadAdministrativa->clave;
        $r = requisicion(JAVIER, $clave, now('America/Mexico_City')->addDay()->toDateString().' 09:00', "Mantenimiento {$tipo} del vehículo {$eco}", $req, ['mantenimiento_id' => $m->id]);
        if ($r && $hasta) {
            avanzar($r, $hasta, array_column($req, 3), $taller, $clave === '210' ? CARLOS : LAURA, ['recursos', 'recibir', 'cotizar']);
        }
    }
    if ($entrega) {
        dia($entrega);
        api(JAVIER, 'POST', "vehiculos/mantenimientos/{$m->id}/entregar", ['fecha_entrega' => substr($entrega, 0, 10), 'recibe_entrega' => $v->resguardante,
            'observaciones_entrega' => 'Se entrega funcionando; se recomienda revisión en 10,000 km.']);
    }

    return $m;
};
$servicio('G-014', '2026-02-16 09:00', 'preventivo', 48210, 'Servicio de 50,000 km: cambio de aceite, filtros y revisión de frenos.',
    [['CAMBIO DE ACEITE Y FILTROS', 1, ['3551'], 2450], ['BALATAS DELANTERAS', 1, ['3551'], 1380]], 'tramite_egresos', '2026-02-27 13:00');
$servicio('P-105', '2026-06-10 08:30', 'correctivo', 61540, 'Falla en el sistema de enfriamiento; se calienta el motor en patrullaje.',
    [['REPARACIÓN DEL SISTEMA DE ENFRIAMIENTO', 1, ['3551'], 6900], ['BOMBA DE AGUA', 1, ['3551'], 2850]], 'factura', '2026-06-24 16:00');
$servicio('S-031', '2026-09-08 07:45', 'correctivo', 132880, 'Fuga en el sistema hidráulico de la compactadora.',
    [['REPARACIÓN DE CILINDRO HIDRÁULICO', 1, ['3551'], 12800]], 'suficiencia', '2026-09-22 15:00');
$servicio('P-112', '2026-10-19 09:15', 'preventivo', 35120, 'Servicio de 35,000 km y alineación.',
    [['SERVICIO DE 35,000 KM', 1, ['3551'], 3200]], 'enviar');
$servicio('G-022', '2026-10-22 10:00', 'correctivo', 88400, 'Ruido en la suspensión delantera.');

// Pago del servicio de la G-014.
$oc = OrdenCompra::whereHas('requisicion', fn ($q) => $q->where('observaciones', 'like', '%G-014%'))->first();
if ($oc) {
    dia('2026-03-20 12:00');
    api(HECTOR, 'POST', 'tesoreria/pagos', ['ordenes' => [$oc->id], 'pago_forma' => 'transferencia', 'pago_fecha' => '2026-03-20', 'cuenta_id' => TesoreriaCuenta::where('fuente', '111')->value('id')]);
}

echo "Modificaciones presupuestales\n";
dia('2026-10-08 11:00');
$origen = linea('210', ['2111']);
$destino = linea('210', ['2161']);
api(CARLOS, 'POST', 'modificaciones-presupuestales', ['tipo' => 'traspaso', 'origen' => $origen['clave'], 'destino' => $destino['clave'], 'origen_mes' => 10, 'destino_mes' => 10,
    'importe' => 15000, 'justificacion' => 'Se requiere material de limpieza adicional para la temporada de lluvias; la partida de papelería tiene remanente.']);
$mod = App\Models\ModificacionPresupuestal::latest('id')->first();
dia('2026-10-09 10:00');
api(RAUL, 'POST', "modificaciones-presupuestales/{$mod->id}/autorizar", ['observaciones' => 'Procede: hay disponible en la partida emisora.']);
dia('2026-10-21 12:00');
$destino = linea('530', ['2711']);
api(ELENA, 'POST', 'modificaciones-presupuestales', ['tipo' => 'ampliacion', 'destino' => $destino['clave'], 'destino_mes' => 11, 'importe' => 85000,
    'justificacion' => 'Ampliación para uniformes del personal de nuevo ingreso del programa de recolección nocturna.']);

echo "Almacén\n";
$oc1 = OrdenCompra::where('folio_completo', 'OC-2026-0001')->first();
dia('2026-04-06 10:00');
api(MONICA, 'POST', "ordenes-compra/{$oc1->id}/almacen");
$conceptos = app(App\Services\Almacen::class)->conceptos($oc1);
dia('2026-04-07 12:00');
api(MONICA, 'POST', "almacen/{$oc1->id}/entradas", ['fecha' => '2026-04-07', 'observaciones' => 'Se recibe completo y en buen estado.',
    'cantidades' => $conceptos->mapWithKeys(fn ($c) => [$c['detalle_id'] => $c['pedido']])->all()]);
dia('2026-04-08 09:30');
api(MONICA, 'POST', "almacen/{$oc1->id}/salidas", ['fecha' => '2026-04-08', 'recibe' => 'CARLOS IBARRA NAVA', 'observaciones' => 'Entrega a la Secretaría de Gobierno.',
    'cantidades' => $conceptos->mapWithKeys(fn ($c) => [$c['detalle_id'] => (int) ceil($c['pedido'] / 2)])->all()]);

echo "Tickets de soporte\n";
dia('2026-10-21 09:40');
api(CARLOS, 'POST', 'tickets', ['title' => 'No puedo descargar la bitácora de combustible en PDF', 'description' => 'Al dar clic en "Bitácora en PDF" tarda mucho y no descarga. Bitácora BC-2026 de septiembre.',
    'url' => '/combustible/bitacoras', 'priority' => 'media']);
$t = SupportTicket::latest('id')->first();
dia('2026-10-21 11:15');
api(LAURA, 'POST', "tickets/{$t->id}/comment", ['comment' => 'Ya quedó: se aumentó la memoria del servidor para generar el PDF. ¿Puedes intentarlo de nuevo?'], [], false);
api(LAURA, 'PUT', "tickets/{$t->id}", ['status' => 'resuelto', 'assigned_to' => App\Models\User::where('email', LAURA)->value('id')], [], false);
dia('2026-10-23 16:20');
api(ELENA, 'POST', 'tickets', ['title' => 'Alta de un nuevo enlace administrativo', 'description' => 'Solicito usuario para el nuevo enlace de la Dirección de Alumbrado Público.', 'priority' => 'baja']);

echo '  '.VehiculoMantenimiento::count().' mantenimientos, '.App\Models\ModificacionPresupuestal::count().' modificaciones, '.SupportTicket::count()." tickets\n";
fin();
