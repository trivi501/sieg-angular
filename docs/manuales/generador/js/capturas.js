// Capturas de pantalla del manual de usuario contra el servidor de demostración (datos ficticios).
// Uso: node capturas.js [nombre…]   (sin nombres, todas)
const puppeteer = require('puppeteer-core');
const fs = require('fs');
const path = require('path');

const BASE = 'http://127.0.0.1:8090';
const SALIDA = path.join(__dirname, '..', 'capturas');
const ids = JSON.parse(fs.readFileSync(path.join(__dirname, '..', 'ids.json'), 'utf8'));
const CLAVE = 'Demo-SIEG-2026';
fs.mkdirSync(SALIDA, { recursive: true });

const espera = (ms) => new Promise((r) => setTimeout(r, ms));

// [nombre, ruta, opciones]: completa (toda la página), accion(page) antes de la captura, usuario.
const CAPTURAS = [
  ['01-login', '/login', { sinSesion: true }],
  ['02-inicio', '/inicio', { completa: true }],
  ['03-requisiciones', '/requisiciones'],
  ['04-requisiciones-menu', '/requisiciones', { accion: menuContextual }],
  ['05-requisicion-nueva', '/requisiciones/nueva', { completa: true }],
  ['06-requisicion-revision', `/requisiciones/${ids.req_revision}`, { completa: true }],
  ['07-requisicion-extemporanea', `/requisiciones/${ids.req_extemporanea}`],
  ['08-requisicion-cotizacion', `/requisiciones/${ids.req_cotizacion}/editar`, { completa: true }],
  ['09-requisicion-comprometida', `/requisiciones/${ids.req_comprometida}`, { completa: true }],
  ['10-requisicion-orden', `/requisiciones/${ids.req_orden}`, { completa: true }],
  ['11-requisicion-pagada', `/requisiciones/${ids.req_pagada}`, { completa: true }],
  ['12-recursos-materiales', '/recursos-materiales'],
  ['13-ordenes-compra', '/ordenes-compra'],
  ['14-clc', '/clc', { completa: true }],
  ['15-facturas', '/facturas'],
  ['16-gasto', '/requisiciones/gasto', { completa: true }],
  ['17-presupuesto', '/presupuesto'],
  ['18-modificaciones', '/modificaciones'],
  ['19-modificacion', `/modificaciones/${ids.modificacion}`, { completa: true }],
  ['20-servicios', '/servicios'],
  ['21-servicio', `/servicios/${ids.servicio}`, { completa: true }],
  ['22-servicio-captura', `/servicios/${ids.servicio_captura}`, { completa: true }],
  ['23-fondo', `/fondos/${ids.fondo_por_validar}`, { completa: true }],
  ['24-contratos', '/contratos'],
  ['25-contrato', `/contratos/${ids.contrato}`, { completa: true }],
  ['26-combustible-cargas', '/combustible', { completa: true }],
  ['27-combustible-lote', `/combustible/lotes/${ids.lote}`, { completa: true }],
  ['28-combustible-bitacora', `/combustible/${ids.bitacora}`],
  ['29-combustible-reporte', '/combustible/reporte'],
  ['30-mantenimientos', '/vehiculos/mantenimientos'],
  ['31-mantenimiento-costo', '/vehiculos/mantenimientos/reporte'],
  ['32-padron', '/padron'],
  ['33-padron-ficha', `/padron/${ids.proveedor}`, { completa: true }],
  ['34-tesoreria-mesa', '/tesoreria', { completa: true }],
  ['35-tesoreria-pagos', '/tesoreria/pagos'],
  ['36-tesoreria-pago', `/tesoreria/pagos/${ids.pago}`, { completa: true }],
  ['37-tesoreria-movimientos', '/tesoreria/movimientos'],
  ['38-tesoreria-reportes', '/tesoreria/reportes', { completa: true }],
  ['39-isr', '/contabilidad/isr'],
  ['40-almacen', '/almacen'],
  ['41-almacen-orden', `/almacen/${ids.oc_almacen}`, { completa: true }],
  ['42-inventario', '/almacen/inventario'],
  ['43-catalogos', '/catalogos', { completa: true }],
  ['44-calendario', '/catalogos/calendario-captura'],
  ['45-cuentas', '/catalogos/tesoreria-cuentas'],
  ['46-vehiculos', '/catalogos/vehiculos'],
  ['47-usuarios', '/usuarios'],
  ['48-roles', '/roles'],
  ['49-tickets', '/tickets'],
  ['50-ticket', `/tickets/${ids.ticket}`],
  ['51-auditoria', '/auditoria'],
  ['52-menu-solicitante', '/inicio', { usuario: 'ana.torres@sieg.demo' }],
  ['53-fondo-validado', `/fondos/${ids.fondo}`, { completa: true }],
  ['54-bitacora-validada', `/combustible/${ids.bitacora_validada}`, { completa: true }],
  ['55-nominas', '/nomina'],
  ['56-nomina-nueva', '/nomina', { accion: nuevaNomina, usuario: 'gabriela.ruiz@sieg.demo' }],
  ['57-nomina-por-validar', `/nomina/${ids.nomina_por_validar}`, { completa: true }],
  ['58-nomina-validada', `/nomina/${ids.nomina}`, { completa: true }],
  ['59-finiquito', `/nomina/${ids.finiquito}`, { completa: true }],
  ['60-laudo-captura', `/nomina/${ids.laudo}`, { completa: true, usuario: 'gabriela.ruiz@sieg.demo' }],
];

/** Abre el formulario de un trámite nuevo de nómina. */
async function nuevaNomina(page) {
  const [boton] = await page.$$('xpath/.//button[contains(., "Nuevo trámite")]');
  if (!boton) return;
  await boton.click();
  await espera(700);
}

async function menuContextual(page) {
  const fila = await page.$('table tbody tr');
  if (!fila) return;
  const caja = await fila.boundingBox();
  await page.mouse.click(caja.x + 120, caja.y + caja.height / 2, { button: 'right' });
  await espera(400);
}

async function entrar(page, correo) {
  await page.goto(`${BASE}/login`, { waitUntil: 'networkidle0' });
  await page.evaluate(() => localStorage.clear());
  await page.goto(`${BASE}/login`, { waitUntil: 'networkidle0' });
  await page.waitForSelector('input[type=email]');
  await page.type('input[type=email]', correo);
  await page.type('input[type=password]', CLAVE);
  await page.keyboard.press('Enter');
  // La SPA no navega al entrar: se espera a que guarde el token (si no entra, falla aquí y no saca capturas del login).
  await page.waitForFunction(() => !!localStorage.getItem('sieg_token'), { timeout: 60000 });
  await page.waitForNetworkIdle({ idleTime: 500, timeout: 60000 }).catch(() => {});
  await espera(800);
}

(async () => {
  const solo = process.argv.slice(2);
  const navegador = await puppeteer.launch({
    executablePath:
      process.env.CHROME ??
      (process.platform === 'win32'
        ? 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe'
        : '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'),
    headless: true,
    args: ['--lang=es-MX', '--hide-scrollbars'],
    defaultViewport: { width: 1440, height: 900, deviceScaleFactor: 1.5 }
  });
  const page = await navegador.newPage();
  // En Windows la API atiende una petición a la vez (js/proxy.js): las páginas con muchas consultas tardan más.
  page.setDefaultNavigationTimeout(90000);
  page.on('pageerror', (e) => console.log('  error de página:', e.message));
  let usuario = null;
  for (const [nombre, ruta, op = {}] of CAPTURAS) {
    if (solo.length && !solo.includes(nombre)) continue;
    const quien = op.sinSesion ? null : op.usuario ?? 'laura.mendez@sieg.demo';
    if (quien !== usuario) {
      if (quien) await entrar(page, quien);
      else {
        await page.goto(`${BASE}/login`, { waitUntil: 'networkidle0' });
        await page.evaluate(() => localStorage.clear());
      }
      usuario = quien;
    }
    await page.goto(BASE + ruta, { waitUntil: 'networkidle0' });
    await espera(900);
    const desplazados = await page.evaluate(() => {
      const lista = [];
      for (const el of [document.scrollingElement, ...document.querySelectorAll('*')]) {
        if (el && el.scrollLeft > 0) {
          lista.push((el.className || el.tagName).toString().slice(0, 60) + ' ' + el.scrollLeft + ' (' + el.scrollWidth + '/' + el.clientWidth + ')');
          el.scrollLeft = 0;
        }
      }
      document.activeElement?.blur?.();
      return lista;
    });
    if (desplazados.length) console.log('  desplazamiento horizontal:', desplazados.join(' | '));
    if (op.accion) await op.accion(page);
    const archivo = path.join(SALIDA, `${nombre}.png`);
    // Página completa: la ventana se estira al alto del contenido (fullPage de Chrome recorre el diseño).
    const alto = await page.evaluate(() => document.documentElement.scrollHeight);
    if (op.completa && alto > 900) {
      await page.setViewport({ width: 1440, height: Math.min(alto, 3200), deviceScaleFactor: 1.5 });
      await espera(700);
    }
    await page.screenshot({ path: archivo });
    if (op.completa && alto > 900) await page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 1.5 });
    console.log(`✔ ${nombre} (${ruta}) alto ${alto}px`);
  }
  await navegador.close();
})();
