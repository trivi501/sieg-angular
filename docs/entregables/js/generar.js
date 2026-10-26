// Genera los documentos del expediente en orden: matriz y documentación técnica primero; el acta al final, cuando ya
// están todos los archivos de las carpetas 02 a 06 (lleva su huella SHA-256). Uso: node generar.js documentos|acta
(async () => {
  if (process.argv[2] === 'acta') {
    await require('./acta').generar();
    return;
  }
  await require('./cumplimiento').generar();
  await require('./tecnica').generar();
})();
