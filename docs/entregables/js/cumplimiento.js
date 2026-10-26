// Matriz de cumplimiento: cada punto de la propuesta (v2.3) contra lo entregado, con su estado y dónde está.
const path = require('path');
const { B, portada, documento, guardar, reiniciar } = require('../../manuales/generador/js/base');
const { h1, h2, p, l, nota, tabla } = B;
const datos = require('./datos');

const E = 'Entregado';
const A = 'Entregado con ajuste';
const P = 'Parcial';
const X = 'Pendiente';

/** [punto de la propuesta, estado, dónde está o qué se hizo] */
const BLOQUES = [
  ['Bloque A. Configuración base', [
    ['Módulo 1. Catálogos generales', [
      ['Dependencias, direcciones y departamentos', E, 'Catálogos generales: secretarías (unidades administrativas) y departamentos.'],
      ['Centros gestores, responsables y firmantes', E, 'Catálogos de unidades y de firmantes (por documento y vigencia).'],
      ['Programas presupuestarios y fuentes de financiamiento', E, 'Catálogos de programas, proyectos y fuentes.'],
      ['Clasificador por Objeto del Gasto', E, 'Catálogo de objeto del gasto (partidas).'],
      ['Ejercicios fiscales y tipos de documentos', E, 'Catálogos de ejercicios fiscales (días de captura, cierre) y tipos de documento.'],
      ['Proveedores, bancos y cuentas bancarias', E, 'Proveedores (con padrón del OIC), bancos y cuentas bancarias del Ayuntamiento.'],
      ['Padrón vehicular', E, 'Catálogo de vehículos: número económico, placas, secretaría y departamento de resguardo, resguardante.'],
      ['Altas, bajas y modificaciones; importación masiva; historial de cambios', E, 'Cada catálogo: alta, edición, baja lógica, importación desde Excel e historial.'],
      ['Bitácora de auditoría', E, 'Administración → Bitácora de auditoría (quién, cuándo, valores anteriores y nuevos).']
    ]]
  ]],
  ['Bloque B. Planeación y control presupuestal', [
    ['Módulo 2. Presupuesto de egresos', [
      ['Captura del presupuesto inicial', E, 'Importación del presupuesto aprobado desde Excel y edición por línea.'],
      ['Modificaciones presupuestales: transferencias, ampliaciones y reducciones', E, 'Traspasos, ampliaciones y reducciones con autorización y calendario de captura.'],
      ['Momentos contables (comprometido, devengado, ejercido, pagado)', E, 'Calculados por línea de presupuesto (CONAC): en trámite, comprometido, devengado, ejercido, pagado y disponible.'],
      ['Disponibilidad presupuestal en tiempo real', E, 'Disponible al momento en requisiciones, trámites, contratos, nómina y modificaciones.'],
      ['Reportes: autorizado, modificado, comprometido, devengado, ejercido y pagado', E, 'Presupuesto y Ejercicio y control del gasto por momento contable, exportable a Excel.']
    ]],
    ['Módulo 3. Validación presupuestal', [
      ['Validación automática de suficiencia', E, 'Se valida al comprometer la requisición y al validar trámites, bitácoras, contratos y nómina; si no alcanza, no avanza.']
    ]]
  ]],
  ['Bloque C. Adquisiciones', [
    ['Módulo 4. Requisiciones', [
      ['Foliado automático y flujo de autorizaciones', E, 'Folio por secretaría; revisión, aprobación, Recursos Materiales, cotización y suficiencia.'],
      ['Adjuntos', X, 'La requisición no tiene adjuntos propios (sí los tienen facturas, trámites y padrón).'],
      ['Estatus, incluida Cancelada con fecha de vencimiento', P, 'Doce estados con bitácora y cancelación con motivo; no hay vencimiento automático.'],
      ['Calendario de captura (1 al 7, configurable por ejercicio) y bloqueo fuera de periodo', E, 'Calendario de captura por mes y días por omisión en el ejercicio fiscal.'],
      ['Requisiciones extemporáneas autorizadas por la Subdirección Administrativa', E, 'Autorizar extemporánea, con registro de quién y cuándo.'],
      ['Alta de proveedores por compra única desde la orden de compra', E, 'Proveedor de compra única desde la orden de compra.'],
      ['Taller mecánico con el flujo de una requisición', E, 'Mantenimiento vehicular: recepción, requisición del taller (partidas de mantenimiento), orden, factura, CLC y entrega.'],
      ['Historial por vehículo y costo acumulado; reportes por vehículo y secretaría', E, 'Historial del vehículo y Costo de mantenimiento (Excel).'],
      ['Padrón de proveedores y contratistas del OIC con cédula', E, 'Padrón de proveedores (OIC): expediente, cédula de un año y alertas.']
    ]],
    ['Módulo 5. Órdenes de compra', [
      ['Folios automáticos, impresión en PDF y seguimiento', E, 'Órdenes de compra con folio, PDF y su factura, CLC y pago.']
    ]],
    ['Módulo 6. Recepción de bienes y servicios', [
      ['Recepción física, validación de cantidades y actas de recepción', E, 'Almacén: entradas (cantidades contra lo pedido) y salidas, con acta y formatos en PDF; inventario.']
    ]],
    ['Módulo 7. Control de proveedores', [
      ['Expediente digital, constancias fiscales y opinión de cumplimiento del SAT', E, 'Expediente del proveedor con sus documentos y vigencias.'],
      ['Padrón del OIC: registro, cédula de un año con alertas, proveedores vinculados', E, 'Solo proveedores con cédula vigente reciben órdenes; alertas de por vencer.'],
      ['Identificación de proveedores de compra única', E, 'Marcados aparte en el padrón.']
    ]]
  ]],
  ['Bloque D. Ejercicio y pago del gasto', [
    ['Módulo 8. Gestión de facturas CFDI', [
      ['Carga de XML y PDF; validación ante el SAT; detección de duplicados', E, 'Por UUID y por serie y folio del emisor; verificación de vigencia y EFOS en el SAT.'],
      ['Integración con Facturapi', A, 'Se valida directamente con el servicio de consulta del SAT, sin costo de un tercero.'],
      ['Validaciones: RFC, UUID, cancelaciones, régimen fiscal y uso de CFDI', E, 'RFC y régimen del receptor y usos de CFDI configurables (SAT_RFC_RECEPTOR, SAT_REGIMEN_RECEPTOR, SAT_USOS_CFDI).']
    ]],
    ['Módulo 9. Cuenta por Liquidar Certificada (CLC)', [
      ['Generación automática, folio único, relación de facturas y presupuestal', E, 'CLC en PDF con su clasificación presupuestal, facturas, descuento, IVA, IEPS e ISR.'],
      ['Estatus Elaborada, Recibida, Revisada, Autorizada, Trámite de pago, Pagada', A, 'Trámite por áreas: área, Recursos Materiales, Control Presupuestal, Sindicatura, Egresos y pagada, con relaciones impresas.'],
      ['CLC sin factura con referencia obligatoria', E, 'Apoyo sin factura con beneficiario y documento de referencia.'],
      ['Arrendamientos y servicios por contrato, mes con mes; requisición automática del XML', E, 'Contratos: mensualidades generadas, pagadas y pendientes, y saldo por ejercer.'],
      ['Fondos revolventes: beneficiario, tope de $2,000.00, tipo de gasto y viáticos, CLC por partida', E, 'Fondos revolventes con partida por artículo y CLC a favor del trabajador.'],
      ['Nómina: importación de Nomipac, afectación por partida y fuente, CLC por unidad, historial', E, 'Nómina y pagos de personal: plantilla de importación y una CLC por unidad administrativa.'],
      ['Pagos de personal y seguridad social (IMSS, ISSSTEZAC)', E, 'Tipo Seguridad social en el módulo de nómina.'],
      ['Acceso del Departamento de Recursos Humanos', E, 'Rol Recursos Humanos.'],
      ['Las unidades administrativas no modifican el capítulo 1000', E, 'Permiso presupuesto-capitulo-1000: las áreas no ven ni usan partidas 1xxx.'],
      ['Finiquitos y laudos sin factura, previa validación de Control Presupuestal', E, 'Tipos Finiquito y Laudo con oficio o expediente obligatorio.'],
      ['Reporte de CLC en Excel con filtros', E, 'Trámite de CLC → exportar a Excel.']
    ]],
    ['Módulo 10. Tesorería y pagos', [
      ['Transferencias SPEI, cheques y pago electrónico', E, 'Pago por transferencia o cheque desde una cuenta del Ayuntamiento.'],
      ['Mesa de control: recepción de trámites, trámite único por fondos y fuentes, agrupación de CLC', E, 'Mesa de control de pagos por proveedor y fuente.'],
      ['Verificación en el SAT previa al pago y número de transferencia que agrupa CLC', E, 'Número TRF-AÑO-0001 por proveedor.'],
      ['Vinculación del movimiento bancario y alerta si los montos no cuadran', E, 'Conciliación automática y manual; diferencia visible.'],
      ['Reportes: posición bancaria, movimientos y cheques', E, 'Reportes de Tesorería en Excel.']
    ]],
    ['Módulo 11. Contabilidad gubernamental', [
      ['Reporte mensual del ISR', E, 'ISR retenido (Contabilidad), exportable a Excel.']
    ]]
  ]],
  ['Bloque E. Combustible', [
    ['Módulo 12. Bitácora de combustible', [
      ['Carga del Excel del proveedor y asignación de XML por secretaría', E, 'Cargas del proveedor de combustible.'],
      ['Bitácora por secretaría con partida y concepto; CLC automática al validarse', E, 'Bitácoras de combustible.'],
      ['Exportable a Excel y PDF; reportes por secretaría, vehículo y periodo', E, 'Bitácora en Excel/PDF y Reporte de consumo.']
    ]]
  ]],
  ['Bloque F. Gestión y consulta', [
    ['Módulo 13. Gestión documental', [
      ['Repositorio central con expediente digital único por operación', P, 'Los documentos viven en cada operación (XML y PDF de facturas, expediente del proveedor, Excel de combustible y nómina, PDF generados); falta la vista única de expediente por operación.']
    ]],
    ['Módulo 14. Dashboard ejecutivo', [
      ['Presupuesto ejercido y disponible; facturas y CLC pendientes; pagos y pasivos; combustible; mantenimiento', E, 'Inicio: panel con todos los indicadores.']
    ]]
  ]]
];

const SEGURIDAD = [
  ['Control de acceso por perfil', E, `${datos.roles.length} roles y ${datos.permisos.length} permisos; menú y API según el rol.`],
  ['Autenticación MFA', X, 'Inicio de sesión con correo y contraseña (token de Sanctum).'],
  ['Auditoría completa y bitácora de movimientos', E, 'Bitácora de auditoría y bitácora por requisición y CLC.'],
  ['Firma electrónica', X, 'Los documentos se firman de forma autógrafa; no hay firma electrónica (e.firma).'],
  ['Respaldo automático', P, 'Respaldo de la base antes de cada despliegue (desplegar.sh) y procedimiento de respaldo diario programado en el Manual de producción; su programación en el servidor queda a cargo del Municipio.']
];

const ARQUITECTURA = [
  ['Backend Laravel 13, PHP 8.4+', E, `Laravel ${datos.versiones.laravel}, PHP 8.3 o superior (producción con PHP 8.4).`],
  ['Frontend React 19, TypeScript, Tailwind CSS', A, `Angular ${datos.versiones.angular} con TypeScript y Bootstrap ${datos.versiones.bootstrap} (plantilla Mantis).`],
  ['Base de datos MySQL 8.4', A, 'MySQL 8 o MariaDB (producción con MariaDB).'],
  ['Infraestructura Nginx, Redis, Supervisor, Laravel Horizon', A, 'Nginx; no requiere Redis, Supervisor ni Horizon: no hay colas ni tareas en segundo plano (todo se procesa en la petición).']
];

const ENTREGABLES = [
  ['Código fuente completo', E, 'Carpeta 05 del expediente (repositorio sieg-angular).'],
  ['Base de datos MySQL', E, 'Carpeta 06: script de estructura y de catálogos (sin datos de operación).'],
  ['Capacitación', X, 'Por programar; material de apoyo: Manual de usuario y Manual de producción.'],
  ['Ambiente de producción', E, 'https://siem.gpe.gob.mx (servidor Debian, PHP 8.4, MariaDB, Nginx).'],
  ['Documentación técnica y manual de usuario', E, 'Carpetas 03 y 04 del expediente.']
];

function conteo(filas) {
  const c = { [E]: 0, [A]: 0, [P]: 0, [X]: 0 };
  for (const f of filas) c[f[1]]++;
  return c;
}

const todos = [...BLOQUES.flatMap(([, ms]) => ms.flatMap(([, fs]) => fs)), ...SEGURIDAD, ...ARQUITECTURA, ...ENTREGABLES];
const c = conteo(todos);
function generar() {
  reiniciar();
  const contenido = [
  portada('Matriz de cumplimiento', 'Propuesta de desarrollo SIEG (v2.3) contra lo entregado', `Versión ${datos.commit} · ${datos.fechaTexto}`),
  h1('1. Resumen'),
  p(`Se revisaron **${todos.length} puntos** de la propuesta de desarrollo (14 módulos en 6 bloques, seguridad, arquitectura y entregables) contra el sistema entregado (versión \`${datos.commit}\` del ${datos.fechaTexto}).`),
  tabla(['Estado', 'Puntos', 'Significado'], [
    [E, String(c[E]), 'Funciona como lo describe la propuesta.'],
    [A, String(c[A]), 'Se cumple el objetivo con otra solución técnica (se explica en cada punto).'],
    [P, String(c[P]), 'Hay una parte funcionando; se indica lo que falta.'],
    [X, String(c[X]), 'No está desarrollado en esta versión.']
  ], [2600, 1200, 5560]),
  nota('Los puntos Parcial y Pendiente se listan en la sección 4 y en el acta de entrega-recepción como observaciones.'),
  h1('2. Módulos del sistema'),
  ...BLOQUES.flatMap(([bloque, modulos]) => [
    h2(bloque),
    ...modulos.flatMap(([modulo, filas]) => [p(`**${modulo}**`), tabla(['Punto de la propuesta', 'Estado', 'En el sistema'], filas, [3700, 1500, 4160])])
  ]),
  h1('3. Seguridad, arquitectura y entregables'),
  h2('3.1 Seguridad'),
  tabla(['Punto de la propuesta', 'Estado', 'En el sistema'], SEGURIDAD, [3700, 1500, 4160]),
  h2('3.2 Arquitectura tecnológica'),
  tabla(['Propuesta', 'Estado', 'Entregado'], ARQUITECTURA, [3700, 1500, 4160]),
  h2('3.3 Entregables'),
  tabla(['Entregable', 'Estado', 'Dónde'], ENTREGABLES, [3700, 1500, 4160]),
  h1('4. Puntos pendientes y parciales'),
  tabla(['Punto', 'Estado', 'Situación'], todos.filter((f) => f[1] === P || f[1] === X), [3700, 1500, 4160]),
  h2('4.1 Además de la propuesta'),
  p('Se entregaron también funciones que la propuesta no detallaba:'),
  l([
    'Trámites de servicios: las facturas primero; al validarse se generan requisición, orden de compra y CLC.',
    'CLC con columnas de descuento, IVA, IEPS e ISR tomadas de la factura.',
    'Refactura de órdenes de compra con historial.',
    'Tickets de soporte con notificaciones.',
    'Generador de manuales con datos ficticios y script de despliegue (desplegar.sh).'
  ])
];

  return guardar(documento('Matriz de cumplimiento', contenido), path.join(datos.salida, '02 Matriz de cumplimiento.docx'));
}

module.exports = { generar, conteo: c, total: todos.length, pendientes: todos.filter((f) => f[1] === P || f[1] === X), ESTADOS: { E, A, P, X } };
