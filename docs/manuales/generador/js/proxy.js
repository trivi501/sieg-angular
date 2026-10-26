// Servidor de demostración en Windows. El servidor integrado de PHP (php -S) atiende una conexión a la vez y en Windows
// se traba con las conexiones que Chrome deja abiertas o que no cierra a tiempo. Aquí Node sirve el frontend compilado y
// corre cada petición de /api, /sanctum, /up y /storage con php-cgi (un proceso por petición, como un servidor web).
// Uso: node proxy.js <dist/browser> <puerto> <php-cgi> <backend/public>
const http = require('http');
const fs = require('fs');
const path = require('path');
const { spawn } = require('child_process');

const [dist, puerto, phpCgi, publico] = process.argv.slice(2);
const INDEX = path.join(publico, 'index.php');
const TIPOS = {
  '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml', '.png': 'image/png', '.jpg': 'image/jpeg', '.ico': 'image/x-icon',
  '.woff2': 'font/woff2', '.woff': 'font/woff', '.ttf': 'font/ttf', '.json': 'application/json', '.webp': 'image/webp', '.html': 'text/html; charset=utf-8'
};

/** Corre la petición con php-cgi (CGI/1.1) y devuelve su respuesta. */
function php(req, res, cuerpo) {
  const url = new URL(req.url, 'http://x');
  const env = {
    ...process.env,
    REDIRECT_STATUS: '200', GATEWAY_INTERFACE: 'CGI/1.1', SERVER_SOFTWARE: 'sieg-demo', SERVER_PROTOCOL: 'HTTP/1.1',
    SERVER_NAME: '127.0.0.1', SERVER_PORT: String(puerto), REMOTE_ADDR: '127.0.0.1', REQUEST_SCHEME: 'http',
    REQUEST_METHOD: req.method, REQUEST_URI: req.url, QUERY_STRING: url.search.slice(1),
    SCRIPT_FILENAME: INDEX, SCRIPT_NAME: '/index.php', DOCUMENT_ROOT: publico,
    CONTENT_TYPE: req.headers['content-type'] ?? '', CONTENT_LENGTH: String(cuerpo.length)
  };
  for (const [k, v] of Object.entries(req.headers)) env['HTTP_' + k.toUpperCase().replace(/-/g, '_')] = Array.isArray(v) ? v.join(', ') : v;

  const proceso = spawn(phpCgi, ['-d', 'memory_limit=1G'], { env, cwd: publico, windowsHide: true });
  const salida = [];
  proceso.stdout.on('data', (d) => salida.push(d));
  proceso.stderr.on('data', (d) => process.stderr.write(d));
  proceso.on('close', () => {
    const todo = Buffer.concat(salida);
    const fin = todo.indexOf('\r\n\r\n');
    const [cabeza, cuerpoResp] = fin < 0 ? ['', todo] : [todo.subarray(0, fin).toString('latin1'), todo.subarray(fin + 4)];
    let estado = 200;
    const encabezados = {};
    for (const linea of cabeza.split('\r\n').filter(Boolean)) {
      const i = linea.indexOf(':');
      const nombre = linea.slice(0, i).trim();
      const valor = linea.slice(i + 1).trim();
      if (nombre.toLowerCase() === 'status') estado = parseInt(valor, 10);
      else (encabezados[nombre] ??= []).push(valor);
    }
    if (res.destroyed) return;
    res.writeHead(estado, { ...encabezados, 'Content-Length': cuerpoResp.length });
    res.end(cuerpoResp);
  });
  proceso.stdin.end(cuerpo);
}

http
  .createServer((req, res) => {
    const ruta = decodeURIComponent(new URL(req.url, 'http://x').pathname);
    if (/^\/(api|sanctum|up|storage)(\/|$)/.test(ruta)) {
      const partes = [];
      req.on('data', (c) => partes.push(c));
      req.on('end', () => php(req, res, Buffer.concat(partes)));
      return;
    }
    const archivo = path.join(dist, ruta);
    const existe = archivo.startsWith(path.resolve(dist)) && fs.existsSync(archivo) && fs.statSync(archivo).isFile();
    const final = existe ? archivo : path.join(dist, 'index.html');
    res.writeHead(200, { 'Content-Type': TIPOS[path.extname(final)] ?? 'application/octet-stream' });
    fs.createReadStream(final).pipe(res);
  })
  .listen(Number(puerto), '127.0.0.1', () => console.log(`Demostración en http://127.0.0.1:${puerto} (API con ${phpCgi})`));
