<?php

/** Limpia la copia, pone el elenco ficticio y los catálogos (bancos, cuentas, proveedores con su padrón, vehículos). */

require __DIR__.'/comun.php';

use App\Models\Banco;
use App\Models\CalendarioCaptura;
use App\Models\Departamento;
use App\Models\EjercicioFiscal;
use App\Models\Proveedor;
use App\Models\TesoreriaCuenta;
use App\Models\User;
use App\Models\Vehiculo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

echo "Limpieza (datos reales fuera de la copia)\n";
DB::statement('SET FOREIGN_KEY_CHECKS=0');
foreach (['combustible_cargas', 'combustible_facturas', 'combustible_lotes', 'tramite_servicio_facturas', 'tramites_servicio', 'nomina_renglones', 'nominas', 'auditoria', 'notifications',
    'sessions', 'personal_access_tokens', 'support_tickets', 'ticket_comments', 'cache',
    // La operación del sistema (requisiciones, órdenes, CLC, pagos, padrón…) también sale de la copia: la demostración
    // la vuelve a crear desde REQ-0001 (en una base de desarrollo, el origen sí puede traer registros de prueba).
    'almacen_entrada_detalles', 'almacen_entradas', 'almacen_salida_detalles', 'almacen_salidas', 'clc_movimientos', 'clc_relacion_orden', 'clc_relaciones',
    'contrato_mensualidades', 'contratos', 'modificaciones_presupuestales', 'ordenes_compra_refacturas', 'ordenes_compra', 'proveedor_cedulas',
    'proveedor_cuentas', 'proveedor_documentos', 'proveedores', 'tb_egreso_requisicion_bitacora', 'tb_egreso_requisicion_detalle', 'tb_egreso_requisicion',
    'tesoreria_movimientos', 'tesoreria_pago_clc', 'tesoreria_pagos', 'vehiculo_mantenimientos'] as $t) {
    if (Illuminate\Support\Facades\Schema::hasTable($t)) {
        DB::table($t)->truncate();
    }
}
DB::statement('SET FOREIGN_KEY_CHECKS=1');

$elenco = [
    LAURA => ['LAURA MÉNDEZ RÍOS', 'Super Admin', null],
    CARLOS => ['CARLOS IBARRA NAVA', 'Área solicitante', '210'],
    ELENA => ['ELENA ZAMORA FUENTES', 'Área solicitante', '530'],
    MONICA => ['MÓNICA SALAS ORTEGA', 'Recursos Materiales', null],
    RAUL => ['RAÚL CÁRDENAS PINEDO', 'Control Presupuestal', null],
    SOFIA => ['SOFÍA DELGADO ARELLANO', 'Sindicatura', null],
    HECTOR => ['HÉCTOR VALDEZ LUNA', 'Tesorería', null],
    ROCIO => ['ROCÍO ESPARZA GIL', 'Control de combustible', null],
    ANDRES => ['ANDRÉS ROBLES CARRILLO', 'Órgano Interno de Control', null],
    JAVIER => ['JAVIER NÚÑEZ OLVERA', 'Taller mecánico', null],
    PATRICIA => ['PATRICIA LUGO SERNA', 'Contabilidad', null],
    ANA => ['ANA TORRES VILLA', 'Área solicitante', '210'],
    GABRIELA => ['GABRIELA RUIZ MONTOYA', 'Recursos Humanos', null],
];
// Los usuarios de la copia (personas reales) se sustituyen: los 5 de prueba pasan a ser parte del elenco y los demás se borran.
$prueba = ['admin.prueba@sieg.test' => LAURA, 'solicitante.prueba@sieg.test' => CARLOS, 'materiales.prueba@sieg.test' => MONICA,
    'combustible.prueba@sieg.test' => ROCIO, 'oic.prueba@sieg.test' => ANDRES];
foreach ($prueba as $viejo => $nuevo) {
    User::where('email', $viejo)->update(['email' => $nuevo]);
}
$borrar = User::whereNotIn('email', array_keys($elenco))->pluck('id');
DB::table('model_has_roles')->where('model_type', User::class)->whereIn('model_id', $borrar)->delete();
DB::table('model_has_permissions')->where('model_type', User::class)->whereIn('model_id', $borrar)->delete();
User::whereIn('id', $borrar)->delete();
// Roles heredados de otro sistema (catastro, cajas): fuera.
Role::whereIn('name', ['prueba2', 'Catastro', 'Cajero', 'Catastro Solo Impresion', 'Ventanilla', 'Auxiliar Jefe Cajas'])->delete();

$todos = Permission::pluck('name')->all();
$minimos = ['requisiciones-index', 'requisiciones-create', 'requisiciones-edit', 'requisiciones-clc', 'gasto-index', 'presupuesto-index', 'tickets-index', 'tickets-create'];
foreach ($elenco as $correo => [$nombre, $rol, $clave]) {
    $u = User::updateOrCreate(['email' => $correo], ['name' => $nombre, 'password' => Hash::make(CLAVE),
        'id_cat_egreso_unidad_administrativa' => $clave ? unidad($clave) : null]);
    $u->syncRoles([Role::findOrCreate($rol, 'web')]);
    // Para que el guion de datos haga cada paso con quien le toca, el elenco tiene todos los permisos; Ana solo los de su rol.
    $u->syncPermissions($correo === ANA ? $minimos : ($correo === LAURA ? [] : $todos));
}
app(Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
echo '  '.User::count()." usuarios ficticios\n";

echo "Ejercicio y calendario\n";
EjercicioFiscal::updateOrCreate(['año' => 2026], ['descripcion' => 'Ejercicio fiscal 2026', 'estado' => 'abierto', 'captura_dia_inicio' => 1, 'captura_dia_fin' => 7, 'activo' => true]);
CalendarioCaptura::query()->delete();
foreach ([9 => ['2026-08-29', '2026-09-07'], 10 => ['2026-09-28', '2026-10-07'], 11 => ['2026-10-29', '2026-11-06']] as $mes => [$i, $f]) {
    CalendarioCaptura::create(['año' => 2026, 'mes' => $mes, 'fecha_inicio' => $i, 'fecha_fin' => $f, 'activo' => true]);
}

echo "Bancos y cuentas del Ayuntamiento\n";
// Por su clave: el catálogo de la copia puede traer ya los bancos con otros ids.
$bancos = [];
foreach ([1 => ['072', 'BANORTE'], 2 => ['012', 'BBVA MÉXICO'], 3 => ['014', 'SANTANDER']] as $n => [$clave, $nombre]) {
    $bancos[$n] = Banco::updateOrCreate(['clave' => $clave], ['descripcion' => $nombre, 'activo' => true])->id_cat_banco;
}
TesoreriaCuenta::query()->delete();
TesoreriaCuenta::create(['nombre' => 'CONCENTRADORA RECURSOS PROPIOS', 'id_cat_banco' => $bancos[1], 'numero' => '0845123370', 'clabe' => '072930008451233704', 'fuente' => '111',
    'saldo_inicial' => 18500000, 'fecha_saldo_inicial' => '2026-01-01', 'activo' => true]);
TesoreriaCuenta::create(['nombre' => 'PARTICIPACIONES FEDERALES', 'id_cat_banco' => $bancos[2], 'numero' => '0198842211', 'clabe' => '012930001988422115', 'fuente' => '561',
    'saldo_inicial' => 42750000, 'fecha_saldo_inicial' => '2026-01-01', 'activo' => true]);
TesoreriaCuenta::create(['nombre' => 'FORTAMUN', 'id_cat_banco' => $bancos[3], 'numero' => '6550912384', 'clabe' => '014930655091238411', 'fuente' => '512',
    'saldo_inicial' => 26300000, 'fecha_saldo_inicial' => '2026-01-01', 'activo' => true]);

echo "Proveedores (padrón del OIC)\n";
dia('2026-02-10 10:00');
$proveedores = [
    ['PAPELERÍA Y SUMINISTROS DEL CENTRO, S.A. DE C.V.', 'PSC0503147K2', 'Papelería y consumibles de oficina', 'GERARDO PIÑA SOLÍS', '072930011112223334', 1, 'vigente'],
    ['COMPUTACIÓN Y REDES DE GUADALUPE, S.A. DE C.V.', 'CRG1108239T5', 'Equipo de cómputo y redes', 'DIANA OCHOA MURILLO', '012930022223334445', 2, 'vigente'],
    ['SERVICIOS AUTOMOTRICES ZACATECANOS, S.A. DE C.V.', 'SAZ0911054R8', 'Mantenimiento y refacciones automotrices', 'RAMIRO ESQUIVEL DÍAZ', '014930033334445556', 3, 'vigente'],
    ['GASOLINERA EL MIRADOR, S.A. DE C.V.', 'GMI9806177B3', 'Venta de combustibles', 'TERESA CAMPOS ROLDÁN', '072930044445556667', 1, 'vigente'],
    ['INMOBILIARIA LOS OLIVOS DE ZACATECAS, S.A. DE C.V.', 'IOZ1502281P6', 'Arrendamiento de inmuebles', 'MAURICIO LEAL BARRAZA', '012930055556667778', 2, 'vigente'],
    ['LIMPIEZA INTEGRAL DEL BAJÍO, S.A. DE C.V.', 'LIB1704116M1', 'Servicios de limpieza', 'NORMA ACEVEDO RIVAS', '014930066667778889', 3, 'vigente'],
    ['IMPRESOS Y DISEÑO LA BUFA, S.A. DE C.V.', 'IDB1210089Q4', 'Imprenta y señalética', 'OSCAR MEDINA LARA', '072930077778889990', 1, 'por_vencer'],
    ['FERRETERÍA Y MATERIALES VETAGRANDE, S.A. DE C.V.', 'FMV0607215H9', 'Ferretería y materiales', 'SILVIA OROZCO NAVA', '012930088889990001', 2, 'vencido'],
    ['ROBERTO SALINAS CHÁVEZ', 'SACR780412MN3', 'Consultoría en sistemas', null, '014930099990001112', 3, 'en_revision'],
    ['FUMIGACIONES DEL NORTE, S.A. DE C.V.', 'FNO1309243L7', 'Control de plagas', 'IRMA BECERRA SOTO', '072930010101010101', 1, 'suspendido'],
];
foreach ($proveedores as [$nombre, $rfc, $giro, $rep, $clabe, $banco, $estado]) {
    [$s, $r] = api(ANDRES, 'POST', 'padron', ['nombre' => $nombre, 'rfc' => $rfc, 'giro' => $giro, 'representante_legal' => $rep, 'regimen_fiscal' => '601 GENERAL DE LEY PERSONAS MORALES',
        'correo' => 'contacto@'.strtolower(substr($rfc, 0, 3)).'.demo', 'telefono' => '492 '.rand(100, 999).' '.rand(1000, 9999), 'domicilio' => 'AV. GARCÍA SALINAS '.rand(100, 900).', GUADALUPE, ZAC.', 'clabe' => $clabe]);
    $p = Proveedor::find($r['id'] ?? 0);
    if (! $p) {
        continue;
    }
    $p->update(['id_cat_banco' => $bancos[$banco]]);
    if ($estado === 'en_revision') {
        dia('2026-10-18 10:00');
        api(ANDRES, 'POST', "padron/{$p->id}/documentos", ['tipo' => 'constancia_fiscal', 'fecha_documento' => '2026-02-01'], ['archivo' => pdfFalso("csf_{$rfc}.pdf", "Constancia de situacion fiscal {$rfc}")]);

        continue;
    }
    $fechaOpinion = match ($estado) { 'por_vencer' => '2026-09-28', 'vencido' => '2026-08-15', default => '2026-10-15' };
    // Los documentos se suben el día que se emite la cédula (con la opinión recién expedida).
    dia(match ($estado) { 'por_vencer' => '2026-09-29 10:00', 'vencido' => '2026-08-16 10:00', default => '2026-10-16 10:00' });
    foreach (array_keys(App\Models\ProveedorDocumento::TIPOS) as $tipo) {
        if (in_array($tipo, ['poder_notarial', 'otro'], true) || ($tipo === 'acta_constitutiva' && $p->tipo_persona !== 'moral')) {
            continue;
        }
        $datos = ['tipo' => $tipo, 'fecha_documento' => $tipo === 'opinion_cumplimiento' ? $fechaOpinion : '2026-02-01'] + ($tipo === 'opinion_cumplimiento' ? ['sentido' => 'positiva'] : []);
        api(ANDRES, 'POST', "padron/{$p->id}/documentos", $datos, ['archivo' => pdfFalso("{$tipo}_{$rfc}.pdf", "{$tipo} {$rfc}")]);
    }
    api(ANDRES, 'POST', "padron/{$p->id}/cedula", ['observaciones' => 'Expediente completo.']);
    dia('2026-10-20 10:00');
    if ($estado === 'suspendido') {
        api(ANDRES, 'POST', "padron/{$p->id}/suspender", ['motivo' => 'Observaciones pendientes de la auditoría 2025.']);
    }
}
api(MONICA, 'POST', 'padron', ['nombre' => 'REFACCIONARIA EL CRUCERO', 'rfc' => 'RCR2001152D4', 'giro' => 'Refacciones', 'compra_unica' => true]);
echo '  '.Proveedor::count()." proveedores\n";

echo "Departamentos y padrón vehicular\n";
$deptos = [];
foreach ([['210', 'PROTECCIÓN CIVIL'], ['210', 'REGISTRO CIVIL'], ['530', 'ALUMBRADO PÚBLICO'], ['530', 'LIMPIA Y RECOLECCIÓN'], ['710', 'PATRULLAJE'], ['310', 'INGRESOS']] as $i => [$clave, $nombre]) {
    $deptos[$nombre] = Departamento::updateOrCreate(['nombre' => $nombre], ['id_cat_egreso_unidad_administrativa' => unidad($clave), 'clave' => sprintf('D%02d', $i + 1), 'responsable' => null, 'activo' => true]);
}
Vehiculo::query()->delete();
foreach ([
    ['G-014', 'ZFA-123-B', 'NISSAN', 'NP300', '2021', 'BLANCO', 'PICKUP', '210', 'PROTECCIÓN CIVIL', 'LUIS ARMANDO REYES'],
    ['G-022', 'ZFB-456-C', 'CHEVROLET', 'AVEO', '2020', 'GRIS', 'SEDÁN', '210', 'REGISTRO CIVIL', 'MARISOL VEGA'],
    ['S-031', 'ZFC-789-D', 'INTERNATIONAL', 'DURASTAR 4300', '2019', 'BLANCO', 'CAMIÓN RECOLECTOR', '530', 'LIMPIA Y RECOLECCIÓN', 'JOSÉ MANUEL DÍAZ'],
    ['S-047', 'ZFD-012-E', 'FORD', 'F-350', '2022', 'BLANCO', 'GRÚA CANASTILLA', '530', 'ALUMBRADO PÚBLICO', 'RICARDO OLIVA'],
    ['P-105', 'ZFE-345-F', 'DODGE', 'CHARGER', '2023', 'NEGRO/BLANCO', 'PATRULLA', '710', 'PATRULLAJE', 'OFICIAL M. HERNÁNDEZ'],
    ['P-112', 'ZFF-678-G', 'FORD', 'RANGER', '2022', 'NEGRO/BLANCO', 'PATRULLA', '710', 'PATRULLAJE', 'OFICIAL R. SÁNCHEZ'],
    ['T-008', 'ZFG-901-H', 'VOLKSWAGEN', 'JETTA', '2020', 'ROJO', 'SEDÁN', '310', 'INGRESOS', 'ADRIANA BELMONTE'],
] as [$eco, $placas, $marca, $linea, $modelo, $color, $tipo, $clave, $depto, $resguardante]) {
    Vehiculo::create(['numero_economico' => $eco, 'placas' => $placas, 'serie' => strtoupper(substr(md5($eco), 0, 17)), 'marca' => $marca, 'linea' => $linea, 'modelo' => $modelo,
        'color' => $color, 'tipo' => $tipo, 'resguardante' => $resguardante, 'estado' => 'en_servicio', 'activo' => true,
        'id_cat_egreso_unidad_administrativa' => unidad($clave), 'departamento_id' => $deptos[$depto]->id]);
}
echo '  '.Vehiculo::count()." vehículos\n";

fin();
