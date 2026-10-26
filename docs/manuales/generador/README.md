# Generador de los manuales

Regenera los dos manuales en Word de `docs/manuales/`:

- **SIEG - Manual de usuario.docx**, con capturas de pantalla tomadas sobre datos ficticios.
- **SIEG - Manual de producción.docx**, solo texto.

Las capturas salen de una base aparte, **`sieg_demo`**, que es una copia de `sieg` con personas, proveedores, folios e importes inventados. La base `sieg` nunca se modifica.

## Requisitos

- El backend instalado (`composer install`) con las migraciones aplicadas en `sieg`.
- MySQL con `mysql` y `mysqldump`. Por omisión se conecta como `root` en `127.0.0.1`; se cambia con `MYSQL_DIR` y `MYSQL_OPCIONES`.
- Node 22 o superior, con las dependencias del frontend instaladas.
- Google Chrome. Otra ruta se indica con `CHROME=/ruta/al/chrome`.
- macOS, o Windows con Git Bash (por ejemplo, con Laragon). En macOS `imagenes.sh` reduce las capturas con `sips`; en Windows lo hace `reducir.php` con la extensión GD de PHP.

### En Windows (Laragon)

- La base de origen se indica con `ORIGEN`: `ORIGEN=sieg_angular ./generar.sh`. `mysql` y `mysqldump` se toman de Laragon (`C:\laragon\bin\mysql\…`) si no están en el PATH.
- El servidor integrado de PHP atiende una conexión a la vez y se traba con las conexiones que Chrome abre por adelantado. Por eso `servidor.sh` deja el PHP en el puerto 8091 y pone delante `js/proxy.js` (Node) en el 8090: sirve el frontend y pasa a PHP cada petición de la API ya completa.
- El frontend se compila con `MSYS_NO_PATHCONV=1`, si no, Git Bash convierte `--base-href /` en la carpeta de Git.
- La copia vacía también las tablas de la operación (requisiciones, órdenes, CLC, pagos, padrón…): en una base de desarrollo el origen puede traer registros de prueba.

## Todo de una vez

```bash
docs/manuales/generador/generar.sh
```

Los manuales quedan en `docs/manuales/`. Al abrirlos, Word pregunta si actualiza los campos: hay que decirle que sí para que se llene el índice.

## Paso por paso

| Paso | Comando | Qué hace |
|---|---|---|
| 1 | `./todo.sh` | Copia `sieg` (o `ORIGEN`) en `sieg_demo` con `reset.sh` y corre `01_base.php` a `05_nomina.php`. Al final escribe `ids.json` con los registros que se van a capturar. |
| 2 | `./servidor.sh` | Compila el frontend en `dist/` y levanta `http://127.0.0.1:8090` contra `sieg_demo`. Con `--sin-build` usa la compilación anterior. |
| 3 | `cd js && npm install && node capturas.js` | Toma las capturas en `capturas/`. Para repetir solo algunas: `node capturas.js 05-requisicion-nueva 11-requisicion-pagada`. |
| 4 | `./imagenes.sh` | Reduce las capturas a JPEG de 1600 px en `imgj/`. |
| 5 | `cd js && node manual_usuario.js && node manual_produccion.js` | Genera los dos `.docx`. |

Para revisar el contenido sin Word: `node js/revisar.js "<manual>.docx" /ruta/salida`. Convierte el documento a HTML y saca capturas por tramos.

## Datos de demostración

| Script | Qué carga |
|---|---|
| `01_base.php` | Quita los datos reales de la copia y deja 13 usuarios ficticios (uno de Recursos Humanos) (contraseña `Demo-SIEG-2026`), el ejercicio y el calendario 2026, bancos, cuentas del Ayuntamiento, 11 proveedores con expediente y cédula (vigente, por vencer, vencido, en revisión, suspendido y compra única), departamentos y 7 vehículos. |
| `02_requisiciones.php` | Requisiciones en cada estado: pendiente, en revisión, rechazada, extemporánea, en Recursos Materiales, en cotización, comprometida, con orden, CLC en distintas áreas, pagada y cancelada. También los pagos por transferencia y cheque, el estado de cuenta y la conciliación. |
| `03_tramites.php` | Trámites de servicios, fondos revolventes (uno con viáticos), dos contratos (uno con 9 mensualidades) y el Excel ficticio del proveedor de combustible de septiembre, con sus bitácoras por secretaría. |
| `04_otros.php` | Mantenimientos vehiculares con sus requisiciones, modificaciones presupuestales, entradas y salidas de almacén, y tickets de soporte. |
| `05_nomina.php` | Nómina: la 1a quincena de octubre importada de Excel (3 unidades) y validada, con sus CLC; la 2a quincena por validar; cuotas del IMSS en captura; un finiquito validado y un laudo en captura. Los captura Gabriela Ruiz (Recursos Humanos) y los valida Raúl Cárdenas (Control Presupuestal). |

Notas sobre cómo se cargan:

- Todo pasa por la API, como lo haría un usuario. Así se respetan las mismas reglas del sistema. Los pasos compartidos del trámite están en `flujo.php` y la conexión a la API en `comun.php`.
- El SAT se simula: todas las facturas salen vigentes.
- Las fechas se simulan con `Carbon::setTestNow` y con `SET TIMESTAMP` en MySQL. Así también la bitácora de la requisición queda con fechas de 2026.
- Las líneas de presupuesto se toman de las que existen en `sieg`. Si cambia el presupuesto y alguna partida ya no existe en una secretaría, el script lo indica y hay que ajustar la partida en el script.

Para agregar una pantalla al manual:

1. Agrégala a la lista `CAPTURAS` de `js/capturas.js`.
2. Si necesita un registro en particular, agrega su id en `ids.php`.
3. Inserta la captura en `js/manual_usuario.js` con `img('nombre', 'pie de figura')`.
