// Datos comunes de los documentos del expediente: lo que leyó datos.php (DATOS_JSON), la carpeta de salida (SALIDA) y
// las huellas SHA-256 de los archivos que se entregan.
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const d = JSON.parse(fs.readFileSync(process.env.DATOS_JSON, 'utf8'));
const MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
const [a, m, dia] = d.fecha_commit.split('-').map(Number);

/** Archivos de una carpeta del expediente con su tamaño y huella SHA-256 (para el acta). */
function archivos(carpeta) {
  const dir = path.join(process.env.SALIDA, carpeta);
  if (!fs.existsSync(dir)) return [];
  return fs.readdirSync(dir).filter((f) => fs.statSync(path.join(dir, f)).isFile()).map((f) => {
    const contenido = fs.readFileSync(path.join(dir, f));
    return { carpeta, nombre: f, kb: Math.max(1, Math.round(contenido.length / 1024)), sha256: crypto.createHash('sha256').update(contenido).digest('hex') };
  });
}

module.exports = {
  ...d,
  salida: process.env.SALIDA,
  fechaTexto: `${dia} de ${MESES[m - 1]} de ${a}`,
  archivos,
  /** Miles con coma. */
  num: (n) => Number(n).toLocaleString('es-MX')
};
