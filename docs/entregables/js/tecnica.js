// Documentación técnica de SIEG: arquitectura, módulos, flujo y estados, modelo de datos, API, seguridad y desarrollo.
// Las tablas de rutas, base de datos y permisos salen del sistema (datos.php), no se escriben a mano.
const path = require('path');
const { B, portada, documento, guardar, reiniciar } = require('../../manuales/generador/js/base');
const { h1, h2, h3, p, l, n, nota, tabla, codigo } = B;
const d = require('./datos');

/** Para qué sirve cada tabla propia del sistema nuevo (las heredadas de SIEG se agrupan por prefijo). */
const TABLAS = {
  users: 'Usuarios del sistema (correo, contraseña cifrada, secretaría).',
  roles: 'Roles (spatie/laravel-permission).', permissions: 'Permisos.', role_has_permissions: 'Permisos de cada rol.',
  model_has_roles: 'Roles de cada usuario.', model_has_permissions: 'Permisos directos de un usuario.',
  personal_access_tokens: 'Tokens de sesión (Sanctum).', auditoria: 'Bitácora de auditoría: altas, cambios, bajas y accesos.',
  presupuesto: 'Presupuesto de egresos por línea (unidad, fuente, proyecto, partida) e importe por mes.',
  saldos_mensuales: 'Saldos por línea y mes (corrida de saldos).', corridas_saldos: 'Historial de corridas de saldos.',
  modificaciones_presupuestales: 'Traspasos, ampliaciones y reducciones con su autorización.',
  tb_egreso_requisicion: 'Requisiciones (encabezado, estado, suficiencia, beneficiario sin factura).',
  tb_egreso_requisicion_detalle: 'Conceptos de la requisición con su línea de presupuesto.',
  tb_egreso_requisicion_bitacora: 'Bitácora de cada requisición (cambios de estado).',
  ordenes_compra: 'Órdenes de compra y registros sin factura (AP, FR, PN): factura, CLC, trámite y pago.',
  ordenes_compra_refacturas: 'Facturas sustituidas por refactura.', clc_movimientos: 'Movimientos del trámite de la CLC.',
  clc_relaciones: 'Relaciones (REL) entre Control Presupuestal y Sindicatura.', clc_relacion_orden: 'CLC de cada relación.',
  tramites_servicio: 'Trámites de servicios, fondos revolventes, bitácoras de combustible y mensualidades de contrato.',
  tramite_servicio_facturas: 'Facturas (CFDI) de los trámites con su clasificación.',
  combustible_lotes: 'Cargas del Excel del proveedor de combustible.', combustible_cargas: 'Tickets de combustible.',
  combustible_facturas: 'XML del combustible por asignar.', contratos: 'Contratos de arrendamiento y servicios.',
  contrato_mensualidades: 'Mensualidades de cada contrato.', nominas: 'Trámites de nómina, seguridad social, finiquitos y laudos.',
  nomina_renglones: 'Conceptos de la nómina por unidad y línea de presupuesto.',
  proveedores: 'Proveedores y padrón del OIC.', proveedor_documentos: 'Expediente del proveedor.', proveedor_cedulas: 'Cédulas del padrón.',
  proveedor_cuentas: 'Cuentas bancarias de proveedores.', tesoreria_cuentas: 'Cuentas bancarias del Ayuntamiento.',
  tesoreria_pagos: 'Pagos (transferencias y cheques).', tesoreria_pago_clc: 'CLC de cada pago.', tesoreria_movimientos: 'Movimientos del estado de cuenta.',
  vehiculos: 'Padrón vehicular.', vehiculo_mantenimientos: 'Mantenimientos del taller.', almacen_entradas: 'Entradas de almacén.',
  almacen_entrada_detalles: 'Conceptos de cada entrada.', almacen_salidas: 'Salidas de almacén.', almacen_salida_detalles: 'Conceptos de cada salida.',
  firmantes: 'Firmantes de los documentos por área y vigencia.', calendario_captura: 'Periodo de captura de requisiciones por mes.',
  calendario_modificaciones: 'Periodo de captura de modificaciones.', ejercicios_fiscales: 'Ejercicios fiscales.',
  support_tickets: 'Tickets de soporte.', ticket_comments: 'Comentarios de los tickets.', notifications: 'Notificaciones (campana).',
  departamentos: 'Departamentos de cada secretaría.', tipos_documento: 'Tipos de documento.'
};

const MODULOS = [
  ['Requisiciones', '`requisiciones/`', 'RequisicionController', 'PresupuestoService'],
  ['Órdenes de compra y CLC', '`ordenes-compra/`, `clc/`', 'OrdenCompraController, ClcController', 'TramiteClc, Cfdi, EstadoCfdiSat'],
  ['Trámites de servicios y fondos revolventes', '`servicios/`', 'TramiteServicioController', 'FacturasTramite, GeneradorTramiteServicio'],
  ['Arrendamientos y contratos', '`contratos/`', 'ContratoController', 'GeneradorTramiteServicio'],
  ['Bitácora de combustible', '`combustible/`', 'CombustibleController', 'ExcelCombustible'],
  ['Nómina y pagos de personal', '`nomina/`', 'NominaController', 'GeneradorNomina, ExcelNomina'],
  ['Presupuesto y gasto', '`presupuesto/`, `requisiciones/gasto`', 'PresupuestoController, RequisicionController', 'PresupuestoService, SaldosMensuales, ExcelGasto'],
  ['Modificaciones presupuestales', '`modificaciones/`', 'ModificacionPresupuestalController', 'SaldosMensuales, DistribucionMensual'],
  ['Padrón de proveedores (OIC)', '`padron/`', 'PadronController', '—'],
  ['Tesorería', '`tesoreria/`', 'TesoreriaController', 'TesoreriaPagos, ExcelMovimientos'],
  ['Almacén', '`almacen/`', 'AlmacenController', 'Almacen'],
  ['Mantenimiento vehicular', '`vehiculos/`', 'VehiculoMantenimientoController', '—'],
  ['Facturas', '`facturas/`', 'FacturaController', 'EstadoCfdiSat'],
  ['Contabilidad (ISR)', '`contabilidad/`', 'ContabilidadController', '—'],
  ['Catálogos generales', '`catalogos/`', 'CatalogoController', 'app/Catalogos (definición de cada catálogo)'],
  ['Panel (Inicio)', '`inicio/`', 'PanelController', 'PresupuestoService'],
  ['Administración y auditoría', '`admin/`, `auditoria/`, `tickets/`', 'UserController, RoleController, PermissionController, AuditoriaController, SupportTicketController', '—']
];

function grupoTabla(nombre) {
  if (TABLAS[nombre]) return null;
  if (nombre.startsWith('cat_')) return 'Catálogos heredados de SIEG (cat_*)';
  if (nombre.startsWith('tb_')) return 'Tablas de operación heredadas de SIEG (tb_*)';
  return 'Tablas del framework y otras';
}

function generar() {
  reiniciar();
  const v = d.versiones;
  const c = d.conteos;
  const propias = d.tablas.filter((t) => TABLAS[t.nombre]);
  const grupos = {};
  for (const t of d.tablas) {
    const g = grupoTabla(t.nombre);
    if (g) (grupos[g] ??= []).push(t);
  }
  const porModulo = {};
  for (const r of d.rutas) {
    const m = r.uri.split('/')[2] ?? '';
    porModulo[m] = (porModulo[m] ?? 0) + 1;
  }
  const permisoRuta = (r) => r.permiso || (r.uri.startsWith('/api/catalogos') ? 'por catálogo (middleware PermisoCatalogo)' : 'sesión iniciada');

  const contenido = [
    portada('Documentación técnica', 'Arquitectura, módulos, modelo de datos, API, seguridad y desarrollo', `Versión ${d.commit} · ${d.fechaTexto}`),

    h1('1. Alcance'),
    p(`Este documento describe la construcción de SIEG (Sistema Integral de Egresos Municipales) en la versión \`${d.commit}\` del repositorio \`sieg-angular\` (${d.fechaTexto}). Complementa al **Manual de usuario** (operación por área) y al **Manual de producción** (instalación, despliegue y respaldos), que se entregan en el mismo expediente.`),
    tabla(['Elemento', 'Cantidad'], [
      ['Módulos del frontend (carpetas de pantallas)', String(c.modulos_frontend)], ['Pantallas (componentes de página)', String(c.pantallas)],
      ['Controladores de la API', String(c.controladores)], ['Rutas de la API', String(d.rutas.length)],
      ['Modelos', String(c.modelos)], ['Servicios (reglas de negocio)', String(c.servicios)],
      ['Plantillas de PDF', String(c.vistas_pdf)], ['Tablas de la base de datos', String(d.tablas.length)],
      ['Migraciones propias', String(c.migraciones)], ['Roles / permisos', `${d.roles.length} / ${d.permisos.length}`]
    ], [6360, 3000]),

    h1('2. Arquitectura'),
    p('SIEG es una aplicación web de una sola página (SPA) con una API REST, en el mismo repositorio:'),
    tabla(['Capa', 'Tecnología', 'Carpeta'], [
      ['Frontend', `Angular ${v.angular} (componentes standalone, señales, sin zone.js), TypeScript ${v.typescript}, Bootstrap ${v.bootstrap} (plantilla Mantis), Tom Select ${v.tomselect}`, '`frontend/`'],
      ['Backend (API)', `Laravel ${v.laravel.replace(/^v/, '')} sobre PHP 8.3+ (producción 8.4)`, '`backend/`'],
      ['Sesión y permisos', `laravel/sanctum ${v.sanctum.replace(/^v/, '')} (token Bearer) y spatie/laravel-permission ${v.permission}`, '—'],
      ['Documentos', `barryvdh/laravel-dompdf ${v.dompdf.replace(/^v/, '')} (PDF) y phpoffice/phpspreadsheet ${v.phpspreadsheet} (Excel)`, '`backend/resources/views`'],
      ['Base de datos', `MySQL 8 o MariaDB (desarrollo con MySQL ${v.mysql}; esquema heredado de SIEG más migraciones)`, '`backend/database/migrations`'],
      ['Archivos', 'Disco local: XML/PDF de facturas, expedientes, Excel', '`backend/storage/app/private`']
    ], [1800, 5560, 2000]),
    h2('2.1 Comunicación'),
    l([
      'El navegador descarga el frontend compilado y llama a la API en la misma dirección bajo `/api` (sin CORS).',
      'Cada petición lleva el token de Sanctum (`Authorization: Bearer …`). El frontend guarda el token en el navegador y lo borra al cerrar sesión o si la API responde 401.',
      'Las respuestas JSON se comprimen (middleware `ComprimirJson`).',
      'El backend consulta el estado de las facturas en el servicio SOAP del SAT (`consultaqr.facturaelectronica.sat.gob.mx`).',
      'No hay colas ni tareas programadas: todo se procesa dentro de la petición.'
    ]),
    h2('2.2 Organización del frontend'),
    l([
      '`src/app/pages/<módulo>`: pantallas de cada módulo y su servicio (`*.service.ts`) con las llamadas a la API.',
      '`src/app/core`: sesión (`auth`), guardias por permiso (`data.permission` en las rutas), interceptor del token y del indicador de carga, formatos.',
      '`src/app/shared`: piezas comunes, como los botones de acción con icono (`appAccion`) y la búsqueda por escritura en todos los `select` (`SelectBusquedaDirective`, Tom Select).',
      '`src/app/theme`: plantilla (menú en `navigation.ts`, barra superior).'
    ]),
    h2('2.3 Organización del backend'),
    l([
      '`app/Http/Controllers/Api`: un controlador por módulo; validan la petición y devuelven JSON, PDF o Excel.',
      '`app/Services`: reglas de negocio reutilizables (presupuesto, trámite de CLC, CFDI y SAT, generadores de trámites y nómina, Excel).',
      '`app/Models`: modelos Eloquent; los que usan `Auditable` quedan en la bitácora de auditoría.',
      '`app/Catalogos`: definición declarativa de cada catálogo general (campos, validación, importación, permisos).',
      '`routes/api.php`: todas las rutas con su permiso (`permission:…`).'
    ]),

    h1('3. Módulos'),
    tabla(['Módulo', 'Frontend (pages/)', 'Controlador', 'Servicios'], MODULOS, [2300, 2200, 2700, 2160]),
    h2('3.1 Rutas de la API por módulo'),
    tabla(['Prefijo', 'Rutas'], Object.entries(porModulo).sort((a, b) => b[1] - a[1]).map(([m, k]) => [`/api/${m}`, String(k)]), [6360, 3000]),
    p('La lista completa, con el método, la acción y el permiso de cada ruta, está en el Anexo A.'),

    h1('4. Flujo del gasto y estados'),
    h2('4.1 Requisición'),
    tabla(['Id', 'Estado', 'Paso'], [
      ['1', 'Pendiente', 'Capturada por el área.'], ['2', 'En revisión', 'Enviada a la Subdirección Administrativa.'], ['3', 'Aprobada', 'Validada.'],
      ['4', 'Rechazada', 'Regresada con motivo.'], ['5', 'En Recursos Materiales', 'Enviada a cotizar.'], ['6', 'En cotización', 'Con precios.'],
      ['7', 'Comprometida', 'Con suficiencia.'], ['8', 'Orden de compra', 'Orden generada.'], ['9', 'Con factura', 'XML cargado (devengado).'],
      ['10', 'CLC generada', 'Ejercido.'], ['11', 'Pagada', 'Pago registrado.'], ['12', 'Cancelada', 'Con motivo.']
    ], [900, 2800, 5660]),
    p('Los trámites de servicios, fondos, combustible, contratos y nómina generan su requisición ya aprobada y comprometida al validarse (`GeneradorTramiteServicio`, `GeneradorNomina`).'),
    h2('4.2 Momentos contables'),
    p('`PresupuestoService` calcula por línea de presupuesto (unidad | fuente | proyecto | partida), con los importes de los conceptos de las requisiciones:'),
    l([
      '**Vigente** = asignado ± modificaciones autorizadas.',
      '**En trámite**: requisiciones sin suficiencia. **Comprometido**: con suficiencia. **Devengado**: con factura. **Ejercido**: con CLC vigente. **Pagado**: con pago.',
      'Los momentos son acumulativos (CONAC) y **Disponible** = vigente − comprometido acumulado.'
    ]),
    h2('4.3 Trámite de la CLC'),
    p('`TramiteClc` mueve las CLC entre áreas: área → Recursos Materiales → Control Presupuestal → Sindicatura → Control Presupuestal (firmadas) → Egresos → pagada. Cada paso queda en `clc_movimientos`; los envíos con Sindicatura generan relaciones (REL). Las CLC de nómina entran directo en Control Presupuestal.'),
    h2('4.4 Registros sin factura'),
    tabla(['Folio', 'Origen', 'A favor de'], [
      ['AP-AÑO-0001', 'Apoyo sin factura (requisición)', 'Beneficiario capturado'],
      ['FR-AÑO-0001', 'Fondo revolvente', 'Trabajador'],
      ['PN-AÑO-0001', 'Nómina, seguridad social, finiquito o laudo', 'Cuenta de nómina, institución o persona']
    ], [2200, 4160, 3000]),
    p('Se guardan en `ordenes_compra` con `sin_factura = 1` (sin proveedor), para que la CLC, el trámite y el pago funcionen igual que en una compra.'),

    h1('5. Modelo de datos'),
    p(`La base \`${d.base}\` tiene **${d.tablas.length} tablas**: el esquema heredado de SIEG (catálogos \`cat_*\` y operación \`tb_*\`) y las tablas que agregan las migraciones. Las migraciones **solo agregan** tablas y columnas; nunca borran datos (la migración \`poner_al_dia_esquema_base\` completa lo que falte en una base heredada).`),
    h2('5.1 Tablas principales'),
    tabla(['Tabla', 'Columnas', 'Contenido'], propias.map((t) => [`\`${t.nombre}\``, String(t.columnas), TABLAS[t.nombre]]), [3200, 1100, 5060]),
    h2('5.2 Otras tablas'),
    ...Object.entries(grupos).flatMap(([g, ts]) => [p(`**${g}** (${ts.length}): ${ts.map((t) => `\`${t.nombre}\``).join(', ')}.`)]),
    nota('El script de la carpeta 06 del expediente crea esta estructura con sus catálogos y permisos, sin datos de operación.'),

    h1('6. Seguridad'),
    l([
      'Contraseñas cifradas (bcrypt). Sesión por token de Sanctum; el cierre de sesión borra el token.',
      'Cada ruta exige su permiso (`permission:…` en `routes/api.php`); los catálogos, por el middleware `PermisoCatalogo`. El frontend oculta lo que el rol no permite, pero la regla está en la API.',
      'Las áreas solo ven lo de su secretaría (unidades con la misma clave presupuestal); Admin y las áreas revisoras ven todo.',
      'Las partidas del capítulo 1000 solo las usa quien tiene `presupuesto-capitulo-1000`.',
      'Los archivos privados solo se descargan por la API, que revisa el permiso.',
      'Toda alta, cambio y baja de los modelos auditables queda en `auditoria`.'
    ]),
    h2('6.1 Roles'),
    tabla(['Rol', 'Para quién'], [
      ['Super Admin / Admin', 'Administración del sistema: todos los permisos (`php artisan permissions:sync`).'],
      ['Área solicitante', 'Enlaces de cada secretaría: requisiciones, trámites, fondos, contratos y bitácoras.'],
      ['Recursos Materiales', 'Cotización, órdenes, facturas, validación de trámites y contratos.'],
      ['Control Presupuestal', 'Suficiencia, modificaciones, revisión de CLC y validación de nómina.'],
      ['Sindicatura', 'Firma de CLC.'], ['Egresos / Tesorería', 'Recepción y pago de CLC, conciliación y reportes.'],
      ['Control de combustible', 'Excel y XML del proveedor de combustible.'], ['Taller vehicular', 'Mantenimiento vehicular.'],
      ['Almacén', 'Entradas, salidas e inventario.'], ['Órgano Interno de Control', 'Padrón de proveedores.'],
      ['Contabilidad', 'Reporte de ISR retenido.'], ['Recursos Humanos', 'Nómina, seguridad social, finiquitos y laudos.']
    ], [3000, 6360]),
    p('La lista completa de permisos está en el Anexo C.'),

    h1('7. Integraciones y formatos'),
    tabla(['Integración', 'Cómo'], [
      ['SAT: estado del CFDI', 'Servicio SOAP de consulta (`EstadoCfdiSat`): vigente, cancelado, EFOS. Se consulta al cargar y antes de pagar.'],
      ['CFDI 3.3 / 4.0', 'Lectura del XML (`Cfdi`): emisor, receptor, conceptos, IVA, IEPS, ISR, descuento, timbre.'],
      ['Excel del proveedor de combustible', 'Columnas por nombre (`ExcelCombustible`).'],
      ['Nómina de Nomipac', 'Plantilla Unidad, Fuente, Proyecto, Partida, Concepto, Importe (`ExcelNomina`).'],
      ['Estado de cuenta bancario', 'Plantilla de Tesorería o columnas Cargo/Abono (`ExcelMovimientos`).'],
      ['Catálogos y presupuesto', 'Importación desde Excel con vista previa.'],
      ['PDF', 'FUR, orden de compra, CLC, relaciones, actas de almacén, cédula y bitácoras (DomPDF).']
    ], [3000, 6360]),

    h1('8. Configuración'),
    p('Las variables del `backend/.env` (base de datos, URL, correo y validaciones del SAT: `SAT_RFC_RECEPTOR`, `SAT_REGIMEN_RECEPTOR`, `SAT_USOS_CFDI`) se describen en el Manual de producción, capítulo 3. El frontend toma la API de `src/environments/environment.prod.ts` (`apiUrl: \'/api\'`).'),

    h1('9. Desarrollo y mantenimiento'),
    h2('9.1 Ambiente local'),
    ...codigo('cd backend\ncomposer install\ncp .env.example .env && php artisan key:generate\nphp artisan migrate && php artisan permissions:sync\nphp artisan db:seed --class=RolesSistemaSeeder\nphp artisan serve --port=8002\n\ncd ../frontend\nnpm ci\nnpm start   # http://localhost:4200'),
    h2('9.2 Agregar una función'),
    n([
      'Base de datos: una migración nueva que **solo agregue** (con `Schema::hasTable` / `hasColumn`), nunca que borre.',
      'Permisos: agrégalos a `SyncPermissions` y, si es un rol nuevo, a `RolesSistemaSeeder` o en la migración.',
      'Ruta en `routes/api.php` con su `permission:…`; reglas en un servicio si se reutilizan.',
      'Pantalla en `frontend/src/app/pages`, ruta en `app-routing.module.ts` con `data.permission` y entrada en `navigation.ts`.',
      'Probar contra una transacción que se deshace, compilar el frontend y actualizar los manuales con su generador.'
    ]),
    h2('9.3 Documentación'),
    l([
      '`docs/manuales/generador`: genera el Manual de usuario (con capturas sobre datos ficticios) y el de producción.',
      '`docs/entregables`: genera este expediente (`generar.sh`).',
      '`desplegar.sh`: despliegue a producción (Manual de producción, capítulo 5).'
    ]),

    h1('Anexo A. Rutas de la API'),
    tabla(['Método', 'Ruta', 'Acción', 'Permiso'], d.rutas.map((r) => [r.metodo, r.uri.replace(/^\/api/, ''), r.accion.replace('Controller', ''), permisoRuta(r)]), [900, 3500, 2660, 2300]),
    h1('Anexo B. Tablas de la base de datos'),
    tabla(['Tabla', 'Columnas'], d.tablas.map((t) => [t.nombre, String(t.columnas)]), [7360, 2000]),
    h1('Anexo C. Permisos'),
    tabla(['Permiso', 'Descripción'], d.permisos.map((x) => [x.nombre, x.descripcion ?? '']), [4000, 5360])
  ];
  return guardar(documento('Documentación técnica', contenido), path.join(d.salida, '03 Documentación técnica.docx'));
}

module.exports = { generar };
