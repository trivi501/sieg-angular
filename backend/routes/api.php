<?php

use App\Http\Controllers\Api\AlmacenController;
use App\Http\Controllers\Api\AuditoriaController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CatalogoController;
use App\Http\Controllers\Api\ClcController;
use App\Http\Controllers\Api\FacturaController;
use App\Http\Controllers\Api\ModificacionPresupuestalController;
use App\Http\Controllers\Api\OrdenCompraController;
use App\Http\Controllers\Api\PanelController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\PresupuestoController;
use App\Http\Controllers\Api\RequisicionController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\SupportTicketController;
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

    // Trámite de CLC entre áreas (cada acción revisa su propio permiso en App\Services\TramiteClc).
    Route::get('clc', [ClcController::class, 'index'])->middleware('permission:clc-index');
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
