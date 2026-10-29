<?php

use App\Http\Controllers\Api\AlmacenController;
use App\Http\Controllers\Api\AuditoriaController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CatalogoController;
use App\Http\Controllers\Api\ClcController;
use App\Http\Controllers\Api\CombustibleController;
use App\Http\Controllers\Api\ContabilidadController;
use App\Http\Controllers\Api\ContratoController;
use App\Http\Controllers\Api\NominaController;
use App\Http\Controllers\Api\FacturaController;
use App\Http\Controllers\Api\ModificacionPresupuestalController;
use App\Http\Controllers\Api\OrdenCompraController;
use App\Http\Controllers\Api\PadronController;
use App\Http\Controllers\Api\PanelController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\PresupuestoController;
use App\Http\Controllers\Api\RequisicionController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\SupportTicketController;
use App\Http\Controllers\Api\TesoreriaController;
use App\Http\Controllers\Api\TramiteServicioController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\VehiculoMantenimientoController;
use App\Http\Middleware\PermisoCatalogo;
use Illuminate\Support\Facades\Route;

// Cada ruta exige su permiso, igual que en routes/settings.php de SIEG.
Route::post('login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('me', [AuthController::class, 'me']);
    Route::post('logout', [AuthController::class, 'logout']);
    // Panel de inicio: cada parte la acota el controlador según los permisos.
    Route::get('panel', [PanelController::class, 'index']);

    Route::get('users', [UserController::class, 'index'])->middleware('permission:users-index');
    Route::get('users/opciones', [UserController::class, 'opciones'])->middleware('permission:users-create|users-edit');
    Route::post('users', [UserController::class, 'store'])->middleware('permission:users-create');
    Route::get('users/{user}', [UserController::class, 'show'])->middleware('permission:users-edit');
    Route::put('users/{user}', [UserController::class, 'update'])->middleware('permission:users-edit');
    Route::delete('users/{user}', [UserController::class, 'destroy'])->middleware('permission:users-delete');

    Route::get('roles', [RoleController::class, 'index'])->middleware('permission:roles-index');
    Route::get('roles/opciones', [RoleController::class, 'opciones'])->middleware('permission:roles-create|roles-edit');
    Route::post('roles', [RoleController::class, 'store'])->middleware('permission:roles-create');
    Route::get('roles/{role}', [RoleController::class, 'show'])->middleware('permission:roles-edit');
    Route::put('roles/{role}', [RoleController::class, 'update'])->middleware('permission:roles-edit');
    Route::delete('roles/{role}', [RoleController::class, 'destroy'])->middleware('permission:roles-delete');

    Route::get('permissions', [PermissionController::class, 'index'])->middleware('permission:permisos-index');
    Route::post('permissions', [PermissionController::class, 'store'])->middleware('permission:permisos-create');
    Route::get('permissions/{permission}', [PermissionController::class, 'show'])->middleware('permission:permisos-edit');
    Route::put('permissions/{permission}', [PermissionController::class, 'update'])->middleware('permission:permisos-edit');
    Route::delete('permissions/{permission}', [PermissionController::class, 'destroy'])->middleware('permission:permisos-delete');

    // Presupuesto (SIEG: routes/web.php). Alta, edición, importación y borrado además exigen rol Admin.
    Route::get('presupuesto', [PresupuestoController::class, 'index'])->middleware('permission:presupuesto-index');
    Route::get('presupuesto/catalogos', [PresupuestoController::class, 'catalogos'])->middleware('permission:presupuesto-create|presupuesto-edit');
    Route::get('presupuesto/{presupuesto}', [PresupuestoController::class, 'show'])->whereNumber('presupuesto')->middleware('permission:presupuesto-index');
    Route::post('presupuesto', [PresupuestoController::class, 'store'])->middleware('permission:presupuesto-create');
    Route::put('presupuesto/{presupuesto}', [PresupuestoController::class, 'update'])->whereNumber('presupuesto')->middleware('permission:presupuesto-edit');
    Route::post('presupuesto/correr-saldos', [PresupuestoController::class, 'correrSaldos'])->middleware('permission:presupuesto-correr-saldos');
    Route::post('presupuesto/import', [PresupuestoController::class, 'import'])->middleware('permission:presupuesto-import');
    Route::delete('presupuesto', [PresupuestoController::class, 'destroy'])->middleware('permission:presupuesto-delete');

    // Requisiciones (SIEG: routes/web.php), con los mismos permisos.
    Route::get('recursos-materiales', [RequisicionController::class, 'recursosMateriales'])->middleware('permission:recursos-materiales-index');
    Route::get('requisiciones', [RequisicionController::class, 'index'])->middleware('permission:requisiciones-index');
    Route::get('requisiciones/opciones', [RequisicionController::class, 'opciones'])->middleware('permission:requisiciones-create');
    Route::get('requisiciones/gasto', [RequisicionController::class, 'gasto'])->middleware('permission:gasto-index');
    Route::get('requisiciones/gasto/detalle', [RequisicionController::class, 'gastoDetalle'])->middleware('permission:gasto-index');
    Route::get('requisiciones/gasto/exportar', [RequisicionController::class, 'exportarGasto'])->middleware('permission:gasto-index');
    Route::post('requisiciones', [RequisicionController::class, 'store'])->middleware('permission:requisiciones-create');
    Route::whereNumber('requisicion')->group(function () {
        Route::get('requisiciones/{requisicion}', [RequisicionController::class, 'show'])->middleware('permission:requisiciones-index');
        // La captura la edita el área (requisiciones-edit); la cotización, Recursos Materiales (requisiciones-cotizar).
        Route::get('requisiciones/{requisicion}/edicion', [RequisicionController::class, 'edicion'])->middleware('permission:requisiciones-edit|requisiciones-cotizar|vehiculos-mantenimiento');
        Route::put('requisiciones/{requisicion}', [RequisicionController::class, 'update'])->middleware('permission:requisiciones-edit|requisiciones-cotizar|vehiculos-mantenimiento');
        Route::delete('requisiciones/{requisicion}', [RequisicionController::class, 'destroy'])->middleware('permission:requisiciones-delete');
        Route::get('requisiciones/{requisicion}/pdf', [RequisicionController::class, 'pdf'])->middleware('permission:requisiciones-index');
        Route::post('requisiciones/{requisicion}/autorizar-extemporanea', [RequisicionController::class, 'autorizarExtemporanea'])->middleware('permission:requisiciones-extemporaneas');
        // Permiso requisiciones-fecha / requisiciones-cancelar o enlace de la misma secretaría (se revisa en el controlador).
        Route::post('requisiciones/{requisicion}/fecha', [RequisicionController::class, 'cambiarFecha'])->middleware('permission:requisiciones-index');
        Route::post('requisiciones/{requisicion}/cancelar', [RequisicionController::class, 'cancelar'])->middleware('permission:requisiciones-index');
        Route::post('requisiciones/{requisicion}/enviar', [RequisicionController::class, 'enviarRevision'])->middleware('permission:requisiciones-create');
        Route::post('requisiciones/{requisicion}/aprobar', [RequisicionController::class, 'aprobar'])->middleware('permission:requisiciones-edit');
        Route::post('requisiciones/{requisicion}/rechazar', [RequisicionController::class, 'rechazar'])->middleware('permission:requisiciones-edit');
        Route::post('requisiciones/{requisicion}/recursos-materiales', [RequisicionController::class, 'enviarRecursosMateriales'])->middleware('permission:requisiciones-edit|vehiculos-mantenimiento');
        Route::post('requisiciones/{requisicion}/devolver-area', [RequisicionController::class, 'devolverArea'])->middleware('permission:requisiciones-devolver');
        Route::post('requisiciones/{requisicion}/recibido', [RequisicionController::class, 'recibido'])->middleware('permission:requisiciones-cotizar|vehiculos-mantenimiento');
        Route::post('requisiciones/{requisicion}/suficiencia', [RequisicionController::class, 'validarSuficiencia'])->middleware('permission:requisiciones-suficiencia');
    });

    // Órdenes de compra (SIEG: routes/web.php), con los mismos permisos. La CLC y el pago van con su trámite.
    Route::get('ordenes-compra', [OrdenCompraController::class, 'index'])->middleware('permission:recursos-materiales-index|ordenes-compra-create|ordenes-compra-cancelar|ordenes-compra-pagar|ordenes-compra-verificar-sat');
    Route::get('ordenes-compra/proveedores', [OrdenCompraController::class, 'proveedores'])->middleware('permission:ordenes-compra-create|vehiculos-mantenimiento');
    // Apoyo sin factura: la CLC sale directo de la requisición comprometida (folio AP-AÑO-0001).
    Route::post('requisiciones/{requisicion}/clc', [OrdenCompraController::class, 'generarClcSinFactura'])->whereNumber('requisicion')->middleware('permission:requisiciones-clc');
    Route::post('requisiciones/{requisicion}/orden-compra', [OrdenCompraController::class, 'store'])->whereNumber('requisicion')->middleware('permission:ordenes-compra-create|vehiculos-mantenimiento');
    Route::whereNumber('ordenCompra')->group(function () {
        Route::get('ordenes-compra/{ordenCompra}/pdf', [OrdenCompraController::class, 'pdf'])->middleware('permission:requisiciones-index');
        Route::get('ordenes-compra/{ordenCompra}/xml', [OrdenCompraController::class, 'descargarXml'])->middleware('permission:requisiciones-index');
        Route::post('ordenes-compra/{ordenCompra}/xml', [OrdenCompraController::class, 'cargarXml'])->middleware('permission:ordenes-compra-create|vehiculos-mantenimiento');
        Route::get('ordenes-compra/{ordenCompra}/factura-pdf', [OrdenCompraController::class, 'descargarPdf'])->middleware('permission:requisiciones-index');
        Route::post('ordenes-compra/{ordenCompra}/factura-pdf', [OrdenCompraController::class, 'cargarPdf'])->middleware('permission:ordenes-compra-create|vehiculos-mantenimiento');
        Route::post('ordenes-compra/{ordenCompra}/proveedor', [OrdenCompraController::class, 'cambiarProveedor'])->middleware('permission:ordenes-compra-create|vehiculos-mantenimiento');
        Route::post('ordenes-compra/{ordenCompra}/fecha', [OrdenCompraController::class, 'cambiarFecha'])->middleware('permission:ordenes-compra-fecha');
        Route::post('ordenes-compra/{ordenCompra}/verificar-sat', [OrdenCompraController::class, 'verificarSat'])->middleware('permission:ordenes-compra-verificar-sat');
        Route::post('ordenes-compra/{ordenCompra}/refacturar', [OrdenCompraController::class, 'refacturar'])->middleware('permission:ordenes-compra-refacturar');
        Route::post('ordenes-compra/{ordenCompra}/cancelar', [OrdenCompraController::class, 'cancelar'])->middleware('permission:ordenes-compra-cancelar');
        // CLC de la orden.
        Route::post('ordenes-compra/{ordenCompra}/clc', [OrdenCompraController::class, 'generarClc'])->middleware('permission:requisiciones-clc');
        Route::get('ordenes-compra/{ordenCompra}/clc', [OrdenCompraController::class, 'clcPdf'])->middleware('permission:requisiciones-index');
        Route::post('ordenes-compra/{ordenCompra}/cancelar-clc', [OrdenCompraController::class, 'cancelarClc'])->middleware('permission:clc-cancelar');
        Route::post('ordenes-compra/{ordenCompra}/pago', [OrdenCompraController::class, 'registrarPago'])->middleware('permission:ordenes-compra-pagar');
    });

    // Almacén (SIEG: routes/web.php), con los mismos permisos.
    Route::post('ordenes-compra/{ordenCompra}/almacen', [AlmacenController::class, 'asignar'])->whereNumber('ordenCompra')->middleware('permission:ordenes-compra-almacen');
    Route::get('almacen', [AlmacenController::class, 'index'])->middleware('permission:almacen-index');
    Route::get('almacen/inventario', [AlmacenController::class, 'inventario'])->middleware('permission:almacen-index');
    Route::get('almacen/entradas/{entrada}/pdf', [AlmacenController::class, 'entradaPdf'])->whereNumber('entrada')->middleware('permission:almacen-index');
    Route::get('almacen/entradas/{entrada}/acta', [AlmacenController::class, 'actaPdf'])->whereNumber('entrada')->middleware('permission:almacen-index');
    Route::get('almacen/salidas/{salida}/pdf', [AlmacenController::class, 'salidaPdf'])->whereNumber('salida')->middleware('permission:almacen-index');
    Route::get('almacen/{ordenCompra}', [AlmacenController::class, 'show'])->whereNumber('ordenCompra')->middleware('permission:almacen-index');
    Route::post('almacen/{ordenCompra}/entradas', [AlmacenController::class, 'storeEntrada'])->whereNumber('ordenCompra')->middleware('permission:almacen-entradas');
    Route::post('almacen/{ordenCompra}/salidas', [AlmacenController::class, 'storeSalida'])->whereNumber('ordenCompra')->middleware('permission:almacen-salidas');

    // Mantenimiento vehicular (taller).
    Route::get('vehiculos/{vehiculo}/mantenimientos', [VehiculoMantenimientoController::class, 'historial'])->whereNumber('vehiculo')->middleware('permission:catalogos-vehiculos-index|vehiculos-mantenimiento');
    Route::get('vehiculos/mantenimientos/reporte', [VehiculoMantenimientoController::class, 'reporte'])->middleware('permission:vehiculos-mantenimiento|catalogos-vehiculos-index');
    Route::get('vehiculos/mantenimientos/reporte/excel', [VehiculoMantenimientoController::class, 'reporteExcel'])->middleware('permission:vehiculos-mantenimiento|catalogos-vehiculos-index');
    Route::get('vehiculos/mantenimientos', [VehiculoMantenimientoController::class, 'index'])->middleware('permission:vehiculos-mantenimiento');
    Route::post('vehiculos/mantenimientos', [VehiculoMantenimientoController::class, 'store'])->middleware('permission:vehiculos-mantenimiento');
    Route::post('vehiculos/mantenimientos/{mantenimiento}/entregar', [VehiculoMantenimientoController::class, 'entregar'])->whereNumber('mantenimiento')->middleware('permission:vehiculos-mantenimiento');

    // Tickets de soporte y notificaciones (SIEG: routes/web.php), con los mismos permisos.
    Route::get('tickets', [SupportTicketController::class, 'index'])->middleware('permission:tickets-index');
    Route::post('tickets', [SupportTicketController::class, 'store'])->middleware('permission:tickets-create');
    Route::get('tickets/{supportTicket}', [SupportTicketController::class, 'show'])->whereNumber('supportTicket')->middleware('permission:tickets-index');
    Route::put('tickets/{supportTicket}', [SupportTicketController::class, 'update'])->whereNumber('supportTicket')->middleware('permission:tickets-update');
    Route::post('tickets/{supportTicket}/comment', [SupportTicketController::class, 'comment'])->whereNumber('supportTicket')->middleware('permission:tickets-create');
    Route::get('notifications', [SupportTicketController::class, 'notifications'])->middleware('permission:tickets-index');
    Route::put('notifications/{notification}/read', [SupportTicketController::class, 'markNotification'])->middleware('permission:tickets-index');

    // Bitácora de auditoría.
    Route::get('auditoria', [AuditoriaController::class, 'index'])->middleware('permission:auditoria-index');

    // Facturas (CFDI) cargadas a las órdenes de compra.
    Route::get('facturas', [FacturaController::class, 'index'])->middleware('permission:facturas-index');

    // Facturas primero: trámites de servicios (/servicios, permisos servicios-*) y fondos revolventes (/fondos,
    // permisos fondos-*). Mismo controlador; el tipo sale de la ruta.
    // Bitácora de combustible: el Excel del proveedor (lotes), sus XML, la bitácora y el reporte de consumo. Cada
    // bitácora es además un trámite tipo combustible (rutas combustible/{tramite} del foreach de abajo).
    Route::prefix('combustible')->group(function () {
        // Unidad de combustibles (combustible-cargar): XML (antes o después del Excel), Excel y cargas. Quien valida
        // también ve las cargas.
        Route::get('lotes', [CombustibleController::class, 'lotes'])->middleware('permission:combustible-cargar|combustible-validar');
        Route::post('lotes/previa', [CombustibleController::class, 'previa'])->middleware('permission:combustible-cargar');
        Route::post('lotes', [CombustibleController::class, 'importar'])->middleware('permission:combustible-cargar');
        Route::get('lotes/{lote}', [CombustibleController::class, 'lote'])->whereNumber('lote')->middleware('permission:combustible-cargar|combustible-validar');
        Route::delete('lotes/{lote}', [CombustibleController::class, 'eliminarLote'])->whereNumber('lote')->middleware('permission:combustible-cargar');
        Route::post('facturas', [CombustibleController::class, 'subirFactura'])->middleware('permission:combustible-cargar');
        Route::get('facturas/por-asignar', [CombustibleController::class, 'porAsignar'])->middleware('permission:combustible-cargar');
        Route::delete('facturas/por-asignar/{factura}', [CombustibleController::class, 'quitarPorAsignar'])->whereNumber('factura')->middleware('permission:combustible-cargar');
        Route::get('reporte', [CombustibleController::class, 'reporte'])->middleware('permission:combustible-index');
        Route::get('reporte/excel', [CombustibleController::class, 'reporteExcel'])->middleware('permission:combustible-index');
        Route::get('{tramite}/tickets', [CombustibleController::class, 'tickets'])->whereNumber('tramite')->middleware('permission:combustible-index');
        Route::get('{tramite}/bitacora/excel', [CombustibleController::class, 'bitacoraExcel'])->whereNumber('tramite')->middleware('permission:combustible-index');
        Route::get('{tramite}/bitacora/pdf', [CombustibleController::class, 'bitacoraPdf'])->whereNumber('tramite')->middleware('permission:combustible-index');
    });

    foreach (['servicios' => 'servicio', 'fondos' => 'fondo', 'combustible' => 'combustible'] as $prefijo => $tipo) {
        Route::prefix($prefijo)->whereNumber(['tramite', 'factura'])->group(function () use ($prefijo, $tipo) {
            Route::get('', [TramiteServicioController::class, 'index'])->defaults('tipo', $tipo)->middleware("permission:{$prefijo}-index");
            Route::post('', [TramiteServicioController::class, 'store'])->defaults('tipo', $tipo)->middleware("permission:{$prefijo}-create");
            Route::get('{tramite}', [TramiteServicioController::class, 'show'])->defaults('tipo', $tipo)->middleware("permission:{$prefijo}-index");
            Route::put('{tramite}', [TramiteServicioController::class, 'update'])->defaults('tipo', $tipo)->middleware("permission:{$prefijo}-create");
            Route::post('{tramite}/facturas', [TramiteServicioController::class, 'subirFactura'])->defaults('tipo', $tipo)->middleware("permission:{$prefijo}-create");
            Route::put('{tramite}/facturas/{factura}', [TramiteServicioController::class, 'clasificarFactura'])->defaults('tipo', $tipo)->middleware("permission:{$prefijo}-create");
            Route::delete('{tramite}/facturas/{factura}', [TramiteServicioController::class, 'quitarFactura'])->defaults('tipo', $tipo)->middleware("permission:{$prefijo}-create");
            Route::post('{tramite}/facturas/{factura}/xml', [TramiteServicioController::class, 'sustituirXml'])->defaults('tipo', $tipo)->middleware("permission:{$prefijo}-create");
            Route::post('{tramite}/facturas/{factura}/revisar', [TramiteServicioController::class, 'revisarFactura'])->defaults('tipo', $tipo)->middleware("permission:{$prefijo}-validar");
            Route::get('{tramite}/facturas/{factura}/xml', [TramiteServicioController::class, 'descargarXml'])->defaults('tipo', $tipo)->middleware("permission:{$prefijo}-index");
            Route::post('{tramite}/facturas/{factura}/pdf', [TramiteServicioController::class, 'subirPdf'])->defaults('tipo', $tipo)->middleware("permission:{$prefijo}-create");
            Route::get('{tramite}/facturas/{factura}/pdf', [TramiteServicioController::class, 'descargarPdf'])->defaults('tipo', $tipo)->middleware("permission:{$prefijo}-index");
            Route::post('{tramite}/enviar', [TramiteServicioController::class, 'enviar'])->defaults('tipo', $tipo)->middleware("permission:{$prefijo}-create");
            Route::post('{tramite}/validar', [TramiteServicioController::class, 'validar'])->defaults('tipo', $tipo)->middleware("permission:{$prefijo}-validar");
            Route::post('{tramite}/rechazar', [TramiteServicioController::class, 'rechazar'])->defaults('tipo', $tipo)->middleware("permission:{$prefijo}-validar");
            Route::post('{tramite}/cancelar', [TramiteServicioController::class, 'cancelar'])->defaults('tipo', $tipo)->middleware("permission:{$prefijo}-create|{$prefijo}-validar");
        });
    }

    // Trámite de CLC entre áreas (cada acción revisa su propio permiso en App\Services\TramiteClc).
    // Arrendamientos y servicios por contrato: el área registra y carga el XML de cada mes (genera requisición,
    // orden de compra y CLC); Recursos Materiales autoriza el contrato una vez.
    Route::prefix('contratos')->group(function () {
        Route::get('', [ContratoController::class, 'index'])->middleware('permission:contratos-index');
        Route::get('opciones', [ContratoController::class, 'opciones'])->middleware('permission:contratos-create|contratos-autorizar');
        Route::post('', [ContratoController::class, 'store'])->middleware('permission:contratos-create');
        Route::whereNumber('contrato')->group(function () {
            Route::get('{contrato}', [ContratoController::class, 'show'])->middleware('permission:contratos-index');
            Route::put('{contrato}', [ContratoController::class, 'update'])->middleware('permission:contratos-create');
            Route::post('{contrato}/enviar', [ContratoController::class, 'enviar'])->middleware('permission:contratos-create');
            Route::post('{contrato}/autorizar', [ContratoController::class, 'autorizarContrato'])->middleware('permission:contratos-autorizar');
            Route::post('{contrato}/rechazar', [ContratoController::class, 'rechazar'])->middleware('permission:contratos-autorizar');
            Route::post('{contrato}/cancelar', [ContratoController::class, 'cancelar'])->middleware('permission:contratos-create|contratos-autorizar');
            Route::post('{contrato}/mensualidades', [ContratoController::class, 'facturar'])->middleware('permission:contratos-create');
        });
    });

    // Nómina: Recursos Humanos importa la nómina de Nomipac (o captura un finiquito o laudo); Control Presupuestal
    // la valida y se genera una CLC por unidad administrativa.
    Route::prefix('nominas')->group(function () {
        Route::get('', [NominaController::class, 'index'])->middleware('permission:nomina-index');
        Route::get('opciones', [NominaController::class, 'opciones'])->middleware('permission:nomina-index');
        Route::get('plantilla', [NominaController::class, 'plantilla'])->middleware('permission:nomina-create');
        Route::post('', [NominaController::class, 'store'])->middleware('permission:nomina-create');
        Route::whereNumber(['nomina', 'renglon'])->group(function () {
            Route::get('{nomina}', [NominaController::class, 'show'])->middleware('permission:nomina-index');
            Route::put('{nomina}', [NominaController::class, 'update'])->middleware('permission:nomina-create');
            Route::get('{nomina}/archivo', [NominaController::class, 'archivo'])->middleware('permission:nomina-index');
            Route::post('{nomina}/importar', [NominaController::class, 'importar'])->middleware('permission:nomina-create');
            Route::post('{nomina}/renglones', [NominaController::class, 'agregarRenglon'])->middleware('permission:nomina-create');
            Route::delete('{nomina}/renglones/{renglon}', [NominaController::class, 'quitarRenglon'])->middleware('permission:nomina-create');
            Route::post('{nomina}/enviar', [NominaController::class, 'enviar'])->middleware('permission:nomina-create');
            Route::post('{nomina}/validar', [NominaController::class, 'validarNomina'])->middleware('permission:nomina-validar');
            Route::post('{nomina}/rechazar', [NominaController::class, 'rechazar'])->middleware('permission:nomina-validar');
            Route::post('{nomina}/cancelar', [NominaController::class, 'cancelar'])->middleware('permission:nomina-create|nomina-validar');
        });
    });

    // Padrón de proveedores del Órgano Interno de Control: expediente, cédula (vigencia de un año) y alertas.
    Route::prefix('padron')->group(function () {
        Route::get('', [PadronController::class, 'index'])->middleware('permission:padron-index');
        Route::get('alertas', [PadronController::class, 'alertas'])->middleware('permission:padron-index');
        Route::post('', [PadronController::class, 'store'])->middleware('permission:padron-gestionar');
        Route::whereNumber(['proveedor', 'documento'])->group(function () {
            Route::get('{proveedor}', [PadronController::class, 'show'])->middleware('permission:padron-index');
            Route::put('{proveedor}', [PadronController::class, 'update'])->middleware('permission:padron-gestionar');
            Route::post('{proveedor}/documentos', [PadronController::class, 'subirDocumento'])->middleware('permission:padron-gestionar');
            Route::get('{proveedor}/documentos/{documento}', [PadronController::class, 'descargarDocumento'])->middleware('permission:padron-index');
            Route::delete('{proveedor}/documentos/{documento}', [PadronController::class, 'quitarDocumento'])->middleware('permission:padron-gestionar');
            Route::post('{proveedor}/cedula', [PadronController::class, 'emitirCedula'])->middleware('permission:padron-gestionar');
            Route::get('{proveedor}/cedula/pdf', [PadronController::class, 'cedulaPdf'])->middleware('permission:padron-index');
            Route::post('{proveedor}/{accion}', [PadronController::class, 'cambiarEstado'])->whereIn('accion', ['rechazar', 'suspender', 'reactivar'])->middleware('permission:padron-gestionar');
        });
    });

    // Tesorería: mesa de control de pagos (Egresos), pago a proveedores, conciliación bancaria y reportes.
    Route::prefix('tesoreria')->group(function () {
        Route::get('mesa', [TesoreriaController::class, 'mesa'])->middleware('permission:tesoreria-index');
        Route::get('cuentas', [TesoreriaController::class, 'cuentasActivas'])->middleware('permission:tesoreria-index|tesoreria-pagar|ordenes-compra-pagar');
        Route::post('clc/{ordenCompra}/verificar-sat', [TesoreriaController::class, 'verificarSat'])->whereNumber('ordenCompra')->middleware('permission:tesoreria-index');
        Route::get('pagos', [TesoreriaController::class, 'listaPagos'])->middleware('permission:tesoreria-index');
        Route::post('pagos', [TesoreriaController::class, 'pagar'])->middleware('permission:tesoreria-pagar');
        Route::post('pagos/conciliar-automatico', [TesoreriaController::class, 'conciliarAutomatico'])->middleware('permission:tesoreria-conciliar');
        Route::whereNumber('pago')->group(function () {
            Route::get('pagos/{pago}', [TesoreriaController::class, 'pago'])->middleware('permission:tesoreria-index');
            Route::post('pagos/{pago}/cancelar', [TesoreriaController::class, 'cancelarPago'])->middleware('permission:tesoreria-pagar');
            Route::post('pagos/{pago}/entregar', [TesoreriaController::class, 'entregar'])->middleware('permission:tesoreria-pagar');
            Route::post('pagos/{pago}/conciliar', [TesoreriaController::class, 'conciliar'])->middleware('permission:tesoreria-conciliar');
            Route::post('pagos/{pago}/desconciliar', [TesoreriaController::class, 'desconciliar'])->middleware('permission:tesoreria-conciliar');
        });
        Route::get('movimientos', [TesoreriaController::class, 'movimientos'])->middleware('permission:tesoreria-index');
        Route::get('movimientos/excel', [TesoreriaController::class, 'movimientosExcel'])->middleware('permission:tesoreria-index');
        Route::get('movimientos/plantilla', [TesoreriaController::class, 'plantillaMovimientos'])->middleware('permission:tesoreria-conciliar');
        Route::post('movimientos', [TesoreriaController::class, 'guardarMovimiento'])->middleware('permission:tesoreria-conciliar');
        Route::post('movimientos/importar', [TesoreriaController::class, 'importarMovimientos'])->middleware('permission:tesoreria-conciliar');
        Route::delete('movimientos/{movimiento}', [TesoreriaController::class, 'eliminarMovimiento'])->whereNumber('movimiento')->middleware('permission:tesoreria-conciliar');
        Route::get('posicion', [TesoreriaController::class, 'posicion'])->middleware('permission:tesoreria-index');
        Route::get('posicion/excel', [TesoreriaController::class, 'posicionExcel'])->middleware('permission:tesoreria-index');
        Route::get('cheques', [TesoreriaController::class, 'cheques'])->middleware('permission:tesoreria-index');
        Route::get('cheques/excel', [TesoreriaController::class, 'chequesExcel'])->middleware('permission:tesoreria-index');
    });

    Route::get('clc', [ClcController::class, 'index'])->middleware('permission:clc-index');
    Route::get('clc/excel', [ClcController::class, 'excel'])->middleware('permission:clc-index');
    // Contabilidad: reporte mensual del ISR retenido (por fecha de pago o de CLC).
    Route::get('contabilidad/isr', [ContabilidadController::class, 'isr'])->middleware('permission:contabilidad-isr');
    Route::get('contabilidad/isr/excel', [ContabilidadController::class, 'isrExcel'])->middleware('permission:contabilidad-isr');
    Route::post('clc/accion', [ClcController::class, 'accion'])->middleware('permission:clc-index');
    Route::get('clc/relaciones/{relacion}/pdf', [ClcController::class, 'relacionPdf'])->whereNumber('relacion')->middleware('permission:clc-index');

    // Modificaciones presupuestales (SIEG: routes/web.php), con los mismos permisos.
    Route::get('modificaciones-presupuestales', [ModificacionPresupuestalController::class, 'index'])->middleware('permission:modificaciones-index');
    Route::get('modificaciones-presupuestales/opciones', [ModificacionPresupuestalController::class, 'opciones'])->middleware('permission:modificaciones-create');
    Route::post('modificaciones-presupuestales', [ModificacionPresupuestalController::class, 'store'])->middleware('permission:modificaciones-create');
    Route::whereNumber('modificacion')->group(function () {
        Route::get('modificaciones-presupuestales/{modificacion}', [ModificacionPresupuestalController::class, 'show'])->middleware('permission:modificaciones-index');
        Route::get('modificaciones-presupuestales/{modificacion}/pdf', [ModificacionPresupuestalController::class, 'pdf'])->middleware('permission:modificaciones-index');
        Route::post('modificaciones-presupuestales/{modificacion}/autorizar', [ModificacionPresupuestalController::class, 'autorizar'])->middleware('permission:modificaciones-autorizar');
        Route::post('modificaciones-presupuestales/{modificacion}/rechazar', [ModificacionPresupuestalController::class, 'rechazar'])->middleware('permission:modificaciones-autorizar');
    });

    // Catálogos generales (App\Catalogos\Catalogos). PermisoCatalogo:<accion> exige "{prefijo}-<accion>"
    // del catálogo de la URL (p. ej. catalogos-vehiculos-edit). El listado de catálogos no lleva middleware:
    // CatalogoController::inicio ya devuelve solo los que el usuario puede ver.
    Route::get('catalogos', [CatalogoController::class, 'inicio']);
    Route::get('catalogos/{catalogo}/definicion', [CatalogoController::class, 'definicion'])->middleware(PermisoCatalogo::class.':index');
    Route::get('catalogos/{catalogo}', [CatalogoController::class, 'index'])->middleware(PermisoCatalogo::class.':index');
    Route::post('catalogos/{catalogo}', [CatalogoController::class, 'store'])->middleware(PermisoCatalogo::class.':create');
    Route::get('catalogos/{catalogo}/plantilla', [CatalogoController::class, 'plantilla'])->middleware(PermisoCatalogo::class.':import');
    Route::post('catalogos/{catalogo}/importar/previa', [CatalogoController::class, 'previa'])->middleware(PermisoCatalogo::class.':import');
    Route::post('catalogos/{catalogo}/importar', [CatalogoController::class, 'importar'])->middleware(PermisoCatalogo::class.':import');
    Route::put('catalogos/{catalogo}/{registro}', [CatalogoController::class, 'update'])->middleware(PermisoCatalogo::class.':edit');
    Route::post('catalogos/{catalogo}/{registro}/estado', [CatalogoController::class, 'estado'])->middleware(PermisoCatalogo::class.':delete');
    Route::get('catalogos/{catalogo}/{registro}/historial', [CatalogoController::class, 'historial'])->middleware(PermisoCatalogo::class.':index');
});
