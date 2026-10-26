// Acta de entrega-recepción: relación de entregables (con la huella SHA-256 de cada archivo del expediente), estado de
// cumplimiento, observaciones, garantía y firmas. Los datos de personas, fecha y hora se dejan para llenar a mano.
const path = require('path');
// docx está instalado con el generador de manuales.
const { AlignmentType, Paragraph, TextRun, Table, TableRow, TableCell, WidthType, BorderStyle } = require(require.resolve('docx', { paths: [path.join(__dirname, '../../manuales/generador/js')] }));
const { B, documento, guardar, reiniciar } = require('../../manuales/generador/js/base');
const { h1SinSalto, h2, p, l, nota, tabla, espacio } = B;
const d = require('./datos');
const matriz = require('./cumplimiento');

const LINEA = '______________________________';

function firmas(pares) {
  const sin = { style: BorderStyle.NONE, size: 0, color: 'FFFFFF' };
  const celda = ([cargo, area]) => new TableCell({
    width: { size: 4680, type: WidthType.DXA }, borders: { top: sin, bottom: sin, left: sin, right: sin },
    children: [
      new Paragraph({ spacing: { before: 900 }, alignment: AlignmentType.CENTER, children: [new TextRun(LINEA)] }),
      new Paragraph({ alignment: AlignmentType.CENTER, children: [new TextRun({ text: 'Nombre, cargo y firma', size: 18, color: '595959' })] }),
      new Paragraph({ alignment: AlignmentType.CENTER, children: [new TextRun({ text: cargo, bold: true, size: 20 })] }),
      new Paragraph({ alignment: AlignmentType.CENTER, children: [new TextRun({ text: area, size: 18 })] })
    ]
  });
  return new Table({ width: { size: 9360, type: WidthType.DXA }, columnWidths: [4680, 4680], rows: pares.map((fila) => new TableRow({ children: fila.map(celda) })) });
}

function generar() {
  reiniciar();
  const c = matriz.conteo;
  const E = matriz.ESTADOS;
  const archivos = ['', '04 Manuales', '05 Código fuente', '06 Base de datos'].flatMap((carpeta) => d.archivos(carpeta))
    .filter((a) => a.nombre !== '01 Acta de entrega-recepción.docx' && a.nombre.toLowerCase() !== 'leeme.txt');

  const contenido = [
    new Paragraph({ alignment: AlignmentType.CENTER, spacing: { after: 80 }, children: [new TextRun({ text: 'ACTA DE ENTREGA-RECEPCIÓN', bold: true, size: 32, color: '7A1F3D' })] }),
    new Paragraph({ alignment: AlignmentType.CENTER, spacing: { after: 360 }, children: [new TextRun({ text: 'Sistema Integral de Egresos Municipales (SIEG) · Municipio de Guadalupe, Zacatecas', size: 22 })] }),
    p(`En la ciudad de Guadalupe, Zacatecas, siendo las ______ horas del día ______ de ____________________ de 20____, se reúnen en las oficinas de ____________________________________ las personas que firman al final de esta acta, para hacer constar la entrega y recepción del **Sistema Integral de Egresos Municipales (SIEG)**, versión \`${d.commit}\` del ${d.fechaTexto}, conforme a la **Propuesta de Desarrollo SIEG (v2.3)**.`),

    h1SinSalto('1. Antecedentes'),
    l([
      'La propuesta comprende el desarrollo de 14 módulos en seis bloques (configuración base, planeación y control presupuestal, adquisiciones, ejercicio y pago del gasto, combustible, gestión y consulta), su implementación, la capacitación y la documentación.',
      'Inversión de la propuesta: $600,000.00 más IVA (16 %) de $96,000.00; total $696,000.00 (seiscientos noventa y seis mil pesos 00/100 M.N.).',
      'El sistema está publicado en el ambiente de producción **https://siem.gpe.gob.mx**.'
    ]),

    h1SinSalto('2. Relación de entregables'),
    tabla(['No.', 'Entregable', 'Contenido', 'En el expediente'], [
      ['1', 'Código fuente completo', 'Repositorio sieg-angular: backend (Laravel), frontend (Angular), script de despliegue y generadores de documentación.', '05 Código fuente'],
      ['2', 'Base de datos MySQL', 'Script de la estructura (tablas, rutinas) y de los catálogos, roles y permisos, sin datos de operación.', '06 Base de datos'],
      ['3', 'Documentación técnica', 'Arquitectura, módulos, flujo y estados, modelo de datos, API, seguridad y desarrollo.', '03 Documentación técnica'],
      ['4', 'Manual de usuario', 'Operación del sistema por área, con capturas sobre datos ficticios.', '04 Manuales'],
      ['5', 'Manual de producción', 'Instalación, configuración, despliegue, respaldos y operación del servidor.', '04 Manuales'],
      ['6', 'Matriz de cumplimiento', 'Cada punto de la propuesta contra lo entregado.', '02 Matriz de cumplimiento'],
      ['7', 'Ambiente de producción', 'Sistema instalado y en operación.', 'https://siem.gpe.gob.mx'],
      ['8', 'Capacitación', 'Sesiones al personal designado por el Municipio.', 'Por programar (sección 5)']
    ], [600, 2200, 4560, 2000]),

    h2('2.1 Archivos entregados'),
    p('Cada archivo se identifica por su huella SHA-256: si el archivo cambia, su huella cambia. Se verifica, por ejemplo, con `certutil -hashfile "<archivo>" SHA256` en Windows o `sha256sum "<archivo>"` en Linux.'),
    tabla(['Archivo', 'KB', 'SHA-256'], archivos.map((a) => [(a.carpeta ? a.carpeta + '/' : '') + a.nombre, d.num(a.kb), a.sha256]), [3560, 900, 4900]),

    h1SinSalto('3. Estado de cumplimiento'),
    p(`De los **${matriz.total} puntos** de la propuesta revisados en la Matriz de cumplimiento: **${c[E.E]}** entregados, **${c[E.A]}** entregados con ajuste técnico, **${c[E.P]}** parciales y **${c[E.X]}** pendientes.`),
    h2('3.1 Observaciones (puntos parciales y pendientes)'),
    tabla(['Punto de la propuesta', 'Estado', 'Situación'], matriz.pendientes, [3700, 1500, 4160]),
    p('Compromiso para su atención: ____________________________________________________________________________ con fecha límite del ______ de ____________________ de 20____.'),

    h1SinSalto('4. Garantía'),
    p('El proveedor otorga una garantía de **90 días naturales** a partir de la puesta en producción, que cubre la corrección de errores atribuibles al desarrollo sin costo adicional. Fecha de puesta en producción: ______ de ____________________ de 20____. Los reportes se levantan como tickets de soporte en el propio sistema o por escrito.'),

    h1SinSalto('5. Capacitación'),
    p('Se impartirá al personal designado por el Municipio con el contenido de la sección 10 de la propuesta (administración, catálogos, módulos presupuestales y financieros, requisiciones, órdenes de compra y CLC, combustible, taller, fondos revolventes, nómina y pago a proveedores, reportes, respaldos y mantenimiento básico), con el Manual de usuario y el Manual de producción como material de apoyo.'),
    tabla(['Sesión', 'Fecha', 'Áreas participantes'], [['1', '', ''], ['2', '', ''], ['3', '', '']], [1500, 2500, 5360]),

    h1SinSalto('6. Recepción'),
    p('El Municipio recibe los entregables relacionados en la sección 2, con las observaciones de la sección 3, para su revisión. Leída la presente, la firman de conformidad los que en ella intervienen.'),
    espacio(),
    firmas([
      [['ENTREGA', 'Por el proveedor'], ['RECIBE', 'Secretaría de la Tesorería y Finanzas · Subsecretaría de Egresos']],
      [['TESTIGO', 'Órgano Interno de Control'], ['TESTIGO', '____________________________________']]
    ]),
    nota('Anexos: Matriz de cumplimiento, Documentación técnica, Manual de usuario, Manual de producción, código fuente y script de la base de datos (carpetas 02 a 06 del expediente).', 'Anexos')
  ];
  return guardar(documento('Acta de entrega-recepción', contenido), path.join(d.salida, '01 Acta de entrega-recepción.docx'));
}

module.exports = { generar };
