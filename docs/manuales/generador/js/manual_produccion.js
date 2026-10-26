// Manual de producción de SIEG: instalación, configuración, despliegue, respaldos y operación del servidor.
const { B, portada, documento, guardar, reiniciar } = require('./base');
const { h1, h2, h3, p, l, n, nota, tabla, codigo } = B;

reiniciar();
const contenido = [
  portada('Manual de producción', 'Instalación, configuración, despliegue, respaldos y operación del servidor', 'Versión 1.1 · Octubre de 2026'),

  // ------------------------------------------------------------------ 1
  h1('1. Arquitectura'),
  p('SIEG es una aplicación web de dos partes que viven en el mismo repositorio (`sieg-angular`):'),
  tabla(['Parte', 'Tecnología', 'Carpeta', 'Qué hace'], [
    ['Frontend', 'Angular 22 (SPA, componentes standalone)', '`frontend/`', 'La interfaz. Se compila a archivos estáticos (HTML, JS, CSS) que sirve el servidor web.'],
    ['Backend (API)', 'Laravel 13 sobre PHP 8.3 o superior', '`backend/`', 'API REST en `/api`: reglas del trámite, permisos, PDF, Excel y verificación de CFDI en el SAT.'],
    ['Base de datos', 'MySQL 8.0', '—', 'Esquema heredado de SIEG más las tablas nuevas (migraciones de Laravel).'],
    ['Archivos', 'Disco local del servidor', '`backend/storage/app`', 'XML y PDF de facturas, expedientes del padrón, Excel del combustible y de la nómina, e imágenes de tickets.']
  ], [1500, 2500, 1700, 3660]),
  p('Librerías principales del backend: **laravel/sanctum** (sesión por token), **spatie/laravel-permission** (roles y permisos), **barryvdh/laravel-dompdf** (PDF) y **phpoffice/phpspreadsheet** (Excel).'),
  h2('1.1 Cómo se conectan'),
  l([
    'El navegador descarga el frontend y llama a la API en la **misma dirección**, bajo `/api` (el build de producción usa `apiUrl: \'/api\'`). Así no hay CORS que configurar.',
    'La sesión es un **token de Sanctum** que el frontend guarda en el navegador y manda en cada petición (`Authorization: Bearer …`). No usa cookies de sesión.',
    'El backend consulta el estado de las facturas en el SAT (`https://consultaqr.facturaelectronica.sat.gob.mx`): el servidor necesita **salida a Internet por HTTPS (443)**.',
    'No hay tareas programadas ni colas de trabajo que mantener encendidas: todo se procesa en la petición. Los respaldos sí se programan (capítulo 7).'
  ]),

  // ------------------------------------------------------------------ 2
  h1('2. Requisitos del servidor'),
  h2('2.1 Software'),
  tabla(['Componente', 'Versión', 'Notas'], [
    ['Sistema operativo', 'Linux (Ubuntu 22.04/24.04 o similar)', 'También funciona en otros, pero los ejemplos son de Ubuntu.'],
    ['Servidor web', 'Nginx 1.18+ (o Apache 2.4 con mod_rewrite)', 'Con HTTPS (certificado válido).'],
    ['PHP', '8.3 o superior, con PHP-FPM', 'Probado con PHP 8.5.'],
    ['Composer', '2.x', 'Para instalar las dependencias del backend.'],
    ['MySQL', '8.0', 'Usuario propio para SIEG; `mysqldump` disponible.'],
    ['Git', '2.x', 'El servidor tiene un clon del repositorio.'],
    ['Node.js', '22 o superior', 'Solo donde se compila el frontend (puede ser la computadora del desarrollador, no el servidor).'],
    ['rsync, ssh', '—', 'Los usa el script de despliegue.']
  ], [2200, 3200, 3960]),
  h2('2.2 Extensiones de PHP'),
  p('`bcmath`, `ctype`, `curl`, `dom`, `fileinfo`, `gd`, `iconv`, `intl`, `libxml`, `mbstring`, `openssl`, `pdo_mysql`, `simplexml`, `tokenizer`, `xml`, `xmlreader`, `xmlwriter`, `zip` y `zlib`. En Ubuntu:'),
  ...codigo('sudo apt install php8.4-fpm php8.4-mysql php8.4-mbstring php8.4-xml php8.4-curl \\\n  php8.4-zip php8.4-gd php8.4-intl php8.4-bcmath'),
  h2('2.3 Ajustes de PHP (php.ini de FPM)'),
  tabla(['Directiva', 'Valor recomendado', 'Por qué'], [
    ['memory_limit', '256M', 'Lectura del Excel del combustible (~65 MB por mes). La bitácora en PDF sube sola su límite a 1 GB.'],
    ['upload_max_filesize', '20M', 'Excel del proveedor de combustible (hasta 20 MB) y documentos.'],
    ['post_max_size', '25M', 'Mayor que upload_max_filesize.'],
    ['max_execution_time', '120', 'Importaciones y reportes grandes.'],
    ['opcache.enable', '1', 'Rendimiento.']
  ], [2400, 2200, 4760]),
  nota('En Nginx agrega también `client_max_body_size 25m;`, si no, las cargas grandes fallan con el error 413.'),
  h2('2.4 Capacidad sugerida'),
  l(['2 vCPU, 4 GB de RAM y 40 GB de disco para empezar.', 'El disco crece con los XML, PDF y expedientes: revisa el espacio de `backend/storage/app` y de los respaldos cada mes.']),

  // ------------------------------------------------------------------ 3
  h1('3. Instalación inicial'),
  h2('3.1 Base de datos'),
  ...codigo("CREATE DATABASE sieg CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\nCREATE USER 'sieg'@'localhost' IDENTIFIED BY '<contraseña segura>';\nGRANT ALL PRIVILEGES ON sieg.* TO 'sieg'@'localhost';\nFLUSH PRIVILEGES;"),
  p('Si se migra desde el SIEG anterior, restaura primero su respaldo en esa base; las migraciones solo agregan lo que falta (la migración `poner_al_dia_esquema_base` completa columnas y tablas que la base heredada no tenía).'),
  h2('3.2 Código y dependencias'),
  ...codigo('cd /var/www\ngit clone git@github.com:trivi501/sieg-angular.git sieg\ncd sieg/backend\ncomposer install --no-dev --optimize-autoloader\ncp .env.example .env\nphp artisan key:generate'),
  h2('3.3 Archivo .env (backend/.env)'),
  p('Variables que hay que revisar en producción:'),
  tabla(['Variable', 'Valor en producción', 'Notas'], [
    ['APP_NAME', 'SIEG', ''],
    ['APP_ENV', 'production', 'Activa las protecciones de producción (p. ej. no corre los seeders de prueba).'],
    ['APP_DEBUG', 'false', 'Nunca true en producción: mostraría datos internos en los errores.'],
    ['APP_KEY', '(generada con key:generate)', 'No cambiarla después: invalida datos cifrados.'],
    ['APP_URL', 'https://siem.gpe.gob.mx', 'Dirección pública del sistema.'],
    ['APP_LOCALE', 'es', ''],
    ['DB_CONNECTION / DB_HOST / DB_PORT', 'mysql / 127.0.0.1 / 3306', ''],
    ['DB_DATABASE / DB_USERNAME / DB_PASSWORD', 'sieg / sieg / …', 'El usuario del paso 3.1.'],
    ['SESSION_DRIVER, CACHE_STORE, QUEUE_CONNECTION', 'database', 'Ya vienen así; usan tablas de la base.'],
    ['LOG_CHANNEL / LOG_LEVEL', 'daily / warning', 'Un archivo de log por día en `storage/logs`.'],
    ['FRONTEND_URL', 'https://siem.gpe.gob.mx', 'Solo importa si el frontend se sirve en otro dominio (CORS).'],
    ['MAIL_MAILER, MAIL_HOST, MAIL_PORT, MAIL_USERNAME, MAIL_PASSWORD, MAIL_FROM_ADDRESS', 'Datos del SMTP del Ayuntamiento', 'Las notificaciones de los tickets de soporte también se envían por correo. Con `MAIL_MAILER=log` solo se registran en el log.'],
    ['SAT_RFC_RECEPTOR', 'MGU6501015R2', 'RFC del Municipio: las facturas deben venir a su nombre.'],
    ['SAT_REGIMEN_RECEPTOR', '603', 'Régimen fiscal del receptor que se exige en CFDI 4.0. Vacío: no se revisa.'],
    ['SAT_USOS_CFDI', 'G01,G03,I01,I02,I03,I04,I05,I06,I07,I08,S01', 'Usos de CFDI aceptados. Vacío: no se revisa.']
  ], [3100, 2700, 3560]),
  nota('Protege el archivo: `chmod 640 backend/.env` y que solo lo lean el usuario del despliegue y el de PHP-FPM. Nunca lo subas al repositorio.', 'Importante'),
  h2('3.4 Base de datos, permisos y roles'),
  ...codigo('php artisan migrate --force\nphp artisan permissions:sync\nphp artisan db:seed --class=RolesSistemaSeeder --force\nphp artisan permission:cache-reset'),
  l([
    '`migrate --force` crea las tablas y columnas que falten (no borra datos).',
    '`permissions:sync` registra todos los permisos de los módulos y se los da a los roles **Super Admin** y **Admin**.',
    '`RolesSistemaSeeder` crea los roles de los módulos nuevos (Área solicitante, Recursos Materiales, Control de combustible, Órgano Interno de Control, Tesorería, Contabilidad, Recursos Humanos y Control Presupuestal) y solo **agrega** permisos: no quita lo que cada rol ya tenga ni crea usuarios. Se puede correr las veces que sea.'
  ]),
  nota('La migración `crear_nominas` (módulo de nómina) agrega las tablas `nominas` y `nomina_renglones`, la columna `ordenes_compra.nomina_id`, los permisos `nomina-index`, `nomina-create`, `nomina-validar` y `presupuesto-capitulo-1000`, y el rol **Recursos Humanos**. No borra datos. Después de aplicarla, asigna el rol Recursos Humanos a su personal en **Administración → Usuarios**.'),
  nota('No corras `UsuariosPruebaSeeder` en producción: crea usuarios de prueba (está bloqueado cuando APP_ENV=production).', 'Importante'),
  h2('3.5 Primer usuario administrador'),
  p('Si la base es nueva y no hay ningún administrador, créalo desde la terminal del servidor:'),
  ...codigo("php artisan tinker\n>>> $u = App\\Models\\User::create(['name' => 'NOMBRE DEL ADMINISTRADOR', 'email' => 'admin@gpe.gob.mx', 'password' => bcrypt('<contraseña temporal>')]);\n>>> $u->assignRole('Super Admin');"),
  p('Con ese usuario se dan de alta los demás desde **Administración → Usuarios** (cada uno con su rol y su secretaría).'),
  h2('3.6 Carpetas y permisos de archivos'),
  ...codigo('cd /var/www/sieg/backend\nphp artisan storage:link\nsudo chown -R deploy:www-data storage bootstrap/cache\nsudo chmod -R ug+rwX storage bootstrap/cache'),
  l([
    '`storage/app/private`: XML y PDF de facturas (órdenes de compra y trámites), expedientes del padrón, Excel del combustible y Excel de la nómina importada (`nominas/`). **No** debe quedar accesible desde la web.',
    '`storage/app/public` (enlazada en `public/storage` con `storage:link`): imágenes adjuntas a los tickets de soporte.',
    '`storage/logs`: logs de Laravel.'
  ]),
  h2('3.7 Servidor web (Nginx)'),
  p('Una sola dirección sirve el frontend compilado y, bajo `/api`, el backend. Ejemplo para `siem.gpe.gob.mx` (el frontend se publica en `/var/www/sieg-web`):'),
  ...codigo([
    'server {',
    '    listen 443 ssl http2;',
    '    server_name siem.gpe.gob.mx;',
    '    ssl_certificate     /etc/ssl/siem/fullchain.pem;',
    '    ssl_certificate_key /etc/ssl/siem/privkey.pem;',
    '    client_max_body_size 25m;',
    '',
    '    # Frontend (Angular): archivos estáticos; cualquier ruta cae en index.html.',
    '    root /var/www/sieg-web;',
    '    index index.html;',
    '    location / {',
    '        try_files $uri $uri/ /index.html;',
    '    }',
    '',
    '    # Backend (Laravel): /api, /up y /storage van a backend/public.',
    '    location ~ ^/(api|up|storage)(/|$) {',
    '        root /var/www/sieg/backend/public;',
    '        try_files $uri /index.php?$query_string;',
    '    }',
    '    location = /index.php {',
    '        root /var/www/sieg/backend/public;',
    '        fastcgi_pass unix:/run/php/php8.4-fpm.sock;',
    '        fastcgi_param SCRIPT_FILENAME $document_root/index.php;',
    '        include fastcgi_params;',
    '    }',
    '}',
    'server {',
    '    listen 80;',
    '    server_name siem.gpe.gob.mx;',
    '    return 301 https://$host$request_uri;',
    '}'
  ].join('\n')),
  p('Después: `sudo nginx -t && sudo systemctl reload nginx`.'),

  // ------------------------------------------------------------------ 4
  h1('4. Compilar el frontend'),
  p('El frontend se compila (en la computadora de desarrollo o en el servidor, con Node 22+):'),
  ...codigo('cd frontend\nnpm ci\nnpx ng build --configuration production --base-href /'),
  l([
    'El resultado queda en `frontend/dist/` (los archivos que se publican).',
    'Si el sistema se publica en una subcarpeta (por ejemplo `https://servidor/sieg/`), usa `--base-href /sieg/` y ajusta Nginx.',
    'El script `npm run build-prod` del package.json trae un base-href de la plantilla original (`/angular/free/`): **no lo uses**; usa el comando de arriba o el script de despliegue.',
    '`frontend/.npmrc` trae `legacy-peer-deps=true`: los paquetes de revisión de código (`@angular-eslint`, de Angular 21) conviven con el CLI de Angular 22. Sin ese archivo, `npm ci` falla con **ERESOLVE**.',
    'Si `git pull` se niega porque cambiaron `package.json` o `package-lock.json` en el servidor, regrésalos con `git checkout -- frontend/package.json frontend/package-lock.json` antes de actualizar.'
  ]),
  ...codigo('rsync -az frontend/dist/ deploy@servidor:/var/www/sieg-web/'),

  // ------------------------------------------------------------------ 5
  h1('5. Desplegar una actualización'),
  p('El repositorio trae `desplegar.sh`, que hace todo el despliegue por SSH desde la computadora del desarrollador. Los datos del servidor van en variables (no se guardan en el repositorio):'),
  tabla(['Variable', 'Obligatoria', 'Ejemplo'], [
    ['SIEG_HOST', 'Sí', 'siem.gpe.gob.mx'],
    ['SIEG_USUARIO', 'Sí', 'deploy'],
    ['SIEG_PUERTO', 'No (22)', '22'],
    ['SIEG_REPO', 'Sí', '/var/www/sieg (clon del repositorio; el backend es SIEG_REPO/backend)'],
    ['SIEG_PUBLICO', 'Sí', '/var/www/sieg-web (carpeta que publica el servidor web para el frontend)'],
    ['SIEG_URL', 'No', 'https://siem.gpe.gob.mx (para revisar al final que responda)'],
    ['SIEG_BASE_HREF', 'No (/)', '/'],
    ['SIEG_PHP', 'No (php)', 'php8.4'],
    ['SIEG_COMPOSER', 'No (composer)', 'composer']
  ], [2400, 1800, 5160]),
  h2('5.1 Primero, simular'),
  ...codigo('./desplegar.sh --simular'),
  p('Muestra todo lo que haría sin conectarse ni cambiar nada.'),
  h2('5.2 Desplegar'),
  ...codigo('SIEG_HOST=siem.gpe.gob.mx SIEG_USUARIO=deploy SIEG_REPO=/var/www/sieg \\\nSIEG_PUBLICO=/var/www/sieg-web SIEG_URL=https://siem.gpe.gob.mx ./desplegar.sh'),
  p('Pasos que hace, en orden, y se detiene en el primer error:'),
  ...n([
    'Revisa que estés en la rama `main` y que esté igual que `origin/main` (todo subido a GitHub).',
    'Compila el frontend de producción.',
    'En el servidor: **respalda la base** con `mysqldump` en `~/respaldos/sieg_<base>_<fecha>.sql.gz` (si el respaldo queda vacío, no sigue).',
    'Actualiza el código con `git pull --ff-only` de `main`.',
    'Pone el sitio en **mantenimiento** (`artisan down`). Si algo falla a partir de aquí, lo vuelve a levantar y dice dónde quedó el respaldo.',
    'Instala dependencias (`composer install --no-dev`).',
    'Corre las migraciones (`migrate --force`).',
    'Sincroniza permisos y roles (`permissions:sync`, `RolesSistemaSeeder`, `permission:cache-reset`).',
    'Regenera las cachés de configuración, rutas y vistas, y levanta el sitio (`artisan up`).',
    'Copia el frontend a SIEG_PUBLICO con rsync (sin borrar otros archivos del sitio).',
    'Si diste SIEG_URL, revisa que el sitio y `/up` respondan 200.'
  ]),
  nota('El usuario de SSH debe poder entrar sin contraseña (llave SSH) y tener permiso de escritura en SIEG_REPO y SIEG_PUBLICO. El servidor debe poder hacer `git pull` del repositorio (llave de despliegue en GitHub).'),
  h2('5.3 Despliegue manual (sin el script)'),
  ...codigo('cd /var/www/sieg && git pull --ff-only origin main\ncd backend\nphp artisan down --retry=60\ncomposer install --no-dev --optimize-autoloader --no-interaction\nphp artisan migrate --force\nphp artisan permissions:sync\nphp artisan db:seed --class=RolesSistemaSeeder --force\nphp artisan permission:cache-reset\nphp artisan optimize:clear\nphp artisan config:cache && php artisan route:cache && php artisan view:cache\nphp artisan up'),
  p('Y después copia el frontend compilado a la carpeta pública (capítulo 4).'),
  h2('5.4 Regresar a la versión anterior'),
  ...n([
    '`php artisan down`',
    'Regresa el código: `git checkout <commit anterior>` (o `git revert` del cambio y vuelve a desplegar).',
    'Si la versión nueva migró la base, restaura el respaldo que hizo el despliegue (capítulo 7.3).',
    'Vuelve a copiar el frontend de esa versión.',
    '`php artisan optimize:clear && php artisan config:cache && php artisan route:cache` y `php artisan up`.'
  ]),

  // ------------------------------------------------------------------ 6
  h1('6. Configuración funcional'),
  h2('6.1 Datos que hay que cargar antes de operar'),
  tabla(['Dato', 'Dónde', 'Quién'], [
    ['Presupuesto aprobado del ejercicio', 'Presupuesto → importar Excel; luego correr saldos', 'Control Presupuestal'],
    ['Ejercicio fiscal (días de captura, abierto)', 'Catálogos → Ejercicios fiscales', 'Administración'],
    ['Calendario de captura de requisiciones y de modificaciones', 'Requisiciones → Calendario de captura / de modificaciones', 'Administración'],
    ['Secretarías, departamentos y fuentes de financiamiento', 'Catálogos generales', 'Administración'],
    ['Cuentas bancarias del Ayuntamiento (banco, CLABE, fuente, saldo inicial)', 'Tesorería → Cuentas bancarias', 'Tesorería'],
    ['Proveedores con su expediente y cédula', 'Padrón de proveedores (OIC)', 'Órgano Interno de Control'],
    ['Padrón vehicular (secretaría de resguardo y área)', 'Catálogos → Vehículos', 'Taller / Administración'],
    ['Usuarios con su rol y su secretaría', 'Administración → Usuarios', 'Administración'],
    ['Personal de Recursos Humanos con el rol Recursos Humanos (nómina); Control Presupuestal con el permiso para validarla', 'Administración → Usuarios / Roles', 'Administración']
  ], [3800, 3460, 2100]),
  h2('6.2 Facturas (CFDI) y SAT'),
  l([
    'Cada factura se verifica en el SAT al cargarla; solo se guarda si está **vigente**. Si el SAT no responde, la factura no se guarda y se pide intentar más tarde.',
    'Se exige que el receptor sea el Municipio (`SAT_RFC_RECEPTOR`), con el régimen `SAT_REGIMEN_RECEPTOR` y un uso de CFDI de `SAT_USOS_CFDI`. Después de cambiar el .env, corre `php artisan config:cache`.',
    'Si el emisor aparece en la lista de EFOS del SAT, la factura se acepta con advertencia.'
  ]),
  h2('6.3 Nómina y capítulo 1000'),
  l([
    'El cálculo y el timbrado de la nómina siguen en Nomipac. Recursos Humanos acomoda lo que genera Nomipac en la plantilla de SIEG (**Unidad**, **Fuente**, **Proyecto**, **Partida**, **Concepto**, **Importe**; se descarga desde la pantalla de Nómina) y la importa. La unidad es la clave presupuestal (CLAVE del presupuesto); los ceros a la izquierda que pierda Excel se corrigen solos.',
    'Al validar Control Presupuestal se generan, por cada unidad, la requisición, un registro sin factura con folio `PN-AÑO-0001` y la CLC, que entra al trámite en Control Presupuestal. No necesita configuración adicional.',
    'Las partidas del capítulo 1000 (las que empiezan con 1) solo las usan quienes tienen el permiso `presupuesto-capitulo-1000` (Admin, Recursos Humanos y Control Presupuestal). Las áreas no las ven en requisiciones, trámites, contratos ni modificaciones presupuestales.'
  ]),

  // ------------------------------------------------------------------ 7
  h1('7. Respaldos'),
  p('Hay que respaldar **dos cosas**: la base de datos y la carpeta de archivos (`backend/storage/app`), que guarda los XML, los PDF y los expedientes. Una sin la otra no sirve para restaurar.'),
  h2('7.1 Respaldo diario automático'),
  p('Script `/usr/local/bin/respaldar-sieg` (con permisos de ejecución):'),
  ...codigo([
    '#!/bin/bash',
    'set -euo pipefail',
    'FECHA=$(date +%Y%m%d_%H%M)',
    'DESTINO=/var/respaldos/sieg',
    'mkdir -p "$DESTINO"',
    '# Las credenciales van en ~/.my.cnf (chmod 600), no en el script.',
    'mysqldump --single-transaction --routines --triggers --no-tablespaces sieg | gzip > "$DESTINO/base_$FECHA.sql.gz"',
    'tar -czf "$DESTINO/archivos_$FECHA.tar.gz" -C /var/www/sieg/backend/storage app',
    '# Conserva 30 días.',
    'find "$DESTINO" -type f -mtime +30 -delete'
  ].join('\n')),
  p('Programación diaria a las 2:00 (crontab del usuario de despliegue, `crontab -e`):'),
  ...codigo('0 2 * * * /usr/local/bin/respaldar-sieg >> /var/log/respaldar-sieg.log 2>&1'),
  nota('Copia los respaldos **fuera del servidor** (otro equipo, almacenamiento en la nube del Ayuntamiento o un disco externo) al menos una vez por semana, y prueba restaurarlos cada trimestre.', 'Importante'),
  h2('7.2 Respaldo antes de cada despliegue'),
  p('`desplegar.sh` deja un respaldo de la base en `~/respaldos` del usuario de despliegue antes de migrar. Bórralos de vez en cuando para no llenar el disco.'),
  h2('7.3 Restaurar'),
  ...codigo('php artisan down\ngunzip < /var/respaldos/sieg/base_20261025_0200.sql.gz | mysql sieg\ntar -xzf /var/respaldos/sieg/archivos_20261025_0200.tar.gz -C /var/www/sieg/backend/storage\nphp artisan optimize:clear && php artisan config:cache && php artisan route:cache\nphp artisan up'),

  // ------------------------------------------------------------------ 8
  h1('8. Operación y monitoreo'),
  h2('8.1 Revisiones'),
  tabla(['Qué', 'Cómo', 'Cada cuándo'], [
    ['El sistema responde', '`curl -I https://siem.gpe.gob.mx/up` debe dar 200', 'Monitoreo cada 5 minutos'],
    ['Errores de la aplicación', '`backend/storage/logs/laravel-AAAA-MM-DD.log`', 'Diario'],
    ['Errores del servidor web y PHP', '`/var/log/nginx/error.log`, log de PHP-FPM', 'Diario'],
    ['Espacio en disco', '`df -h` y `du -sh backend/storage/app /var/respaldos`', 'Semanal'],
    ['Respaldos', 'Que exista el de anoche y no esté vacío', 'Diario'],
    ['Certificado HTTPS', 'Fecha de vencimiento', 'Mensual'],
    ['Actualizaciones de seguridad', '`apt upgrade` del sistema; dependencias con el desarrollador', 'Mensual']
  ], [2600, 4400, 2360]),
  h2('8.2 Bitácora de auditoría'),
  p('Toda alta, cambio o baja de registros queda en **Administración → Bitácora de auditoría** (quién, cuándo, valores anteriores y nuevos). Sirve para revisiones del OIC y para investigar incidentes.'),
  h2('8.3 Comandos útiles'),
  tabla(['Comando (en backend/)', 'Para qué'], [
    ['`php artisan down` / `php artisan up`', 'Poner o quitar el modo mantenimiento.'],
    ['`php artisan permissions:sync`', 'Registrar permisos nuevos tras una actualización.'],
    ['`php artisan permission:cache-reset`', 'Si un usuario no ve un permiso recién asignado.'],
    ['`php artisan optimize:clear`', 'Limpiar cachés de configuración, rutas y vistas.'],
    ['`php artisan config:cache && php artisan route:cache`', 'Volver a generar las cachés (después de cambiar el .env o las rutas).'],
    ['`php artisan migrate:status`', 'Ver qué migraciones están aplicadas.'],
    ['`php artisan about`', 'Resumen del entorno (versiones, caché, drivers).']
  ], [4200, 5160]),

  // ------------------------------------------------------------------ 9
  h1('9. Seguridad'),
  l([
    'Solo **HTTPS**; el puerto 80 redirige a 443.',
    '`APP_DEBUG=false` y `APP_ENV=production`.',
    'El `.env` con permisos 640 y fuera del repositorio. Cambia las contraseñas de la base y del SMTP si alguna vez se exponen.',
    'Usuario de MySQL exclusivo de SIEG, sin acceso a otras bases; MySQL escuchando solo en localhost (o en la red interna).',
    '`backend/storage/app/private` no se publica: solo se descarga a través de la API, que revisa los permisos.',
    'Cuentas personales (una por persona), con el rol mínimo necesario. Da de baja a quien deja el puesto.',
    'Firewall: abiertos solo 443, 80 (redirección) y SSH desde redes autorizadas.',
    'Respaldos cifrados o en un lugar con acceso restringido: contienen datos fiscales y bancarios.'
  ]),

  // ------------------------------------------------------------------ 10
  h1('10. Solución de problemas'),
  tabla(['Síntoma', 'Causa probable', 'Solución'], [
    ['Página en blanco o 404 al recargar una ruta del sistema', 'El servidor web no devuelve index.html para las rutas del frontend.', 'Revisa `try_files $uri $uri/ /index.html;` en Nginx.'],
    ['Error 500 en la API', 'Ver el log del día en `storage/logs`.', 'Corrige según el log; si es por permisos de carpetas, repite el paso 3.6.'],
    ['Error 413 al subir un Excel o documento', 'Límite de tamaño.', '`client_max_body_size 25m` en Nginx y `upload_max_filesize`/`post_max_size` en PHP.'],
    ['La bitácora de combustible en PDF no descarga', 'Memoria de PHP.', 'La acción sube su límite a 1 GB; si PHP-FPM tiene un límite duro menor (`php_admin_value`), súbelo.'],
    ['«No se pudo verificar la factura en el SAT»', 'El servidor no llega al SAT o el SAT no respondió.', 'Revisa la salida a Internet (443) hacia `consultaqr.facturaelectronica.sat.gob.mx` y reintenta.'],
    ['Un usuario no ve un menú recién asignado', 'Caché de permisos.', '`php artisan permission:cache-reset` y que cierre y vuelva a iniciar sesión.'],
    ['Error de CORS en el navegador', 'El frontend está en otro dominio que la API.', 'Pon ese dominio en `FRONTEND_URL` y `php artisan config:cache`, o sirve ambos en la misma dirección.'],
    ['Tras cambiar el .env no cambia nada', 'Configuración en caché.', '`php artisan config:cache`.'],
    ['DBeaver: «Public Key Retrieval is not allowed»', 'MySQL 8 con autenticación caching_sha2.', 'En la conexión de DBeaver, propiedades del driver: `allowPublicKeyRetrieval=true` (y `useSSL=false` si no hay SSL).'],
    ['No llegan los correos de los tickets', 'SMTP sin configurar.', 'Revisa las variables `MAIL_*`; con `MAIL_MAILER=log` solo se escriben en el log.']
  ], [2800, 2800, 3760]),

  // ------------------------------------------------------------------ 11
  h1('11. Lista de verificación para salir a producción'),
  ...n([
    'Servidor con PHP, extensiones, MySQL, Nginx y HTTPS (capítulo 2).',
    'Base creada (o restaurada del SIEG anterior) y `.env` de producción revisado (APP_DEBUG=false).',
    'Migraciones, permisos y roles aplicados (3.4).',
    'Usuario Super Admin creado y probado.',
    'Frontend compilado con `--base-href /` y publicado.',
    '`https://…/up` responde 200 y se puede iniciar sesión.',
    'Prueba de punta a punta con una requisición real pequeña: captura, revisión, cotización, suficiencia, orden, factura (verificada en el SAT), CLC y pago.',
    'Prueba de nómina: Recursos Humanos importa una quincena con la plantilla, Control Presupuestal la valida y se revisan las CLC por unidad.',
    'Presupuesto, calendario, cuentas bancarias, proveedores y usuarios cargados (6.1).',
    'Respaldo diario programado, copiado fuera del servidor y restauración probada (capítulo 7).',
    'Monitoreo de `/up` y revisión de logs asignados a una persona responsable.'
  ]),
  h1('Anexo. Estructura del repositorio'),
  tabla(['Ruta', 'Contenido'], [
    ['`backend/app/Http/Controllers/Api`', 'Controladores de la API (requisiciones, órdenes, CLC, Tesorería, combustible, contratos, padrón, nómina, etc.).'],
    ['`backend/app/Services`', 'Reglas de negocio: CFDI y SAT, trámite de CLC, presupuesto, pagos, Excel, nómina (`GeneradorNomina`, `ExcelNomina`).'],
    ['`backend/database/migrations`', 'Cambios de la base de datos (se aplican con `migrate`).'],
    ['`backend/database/seeders/RolesSistemaSeeder.php`', 'Roles de los módulos nuevos.'],
    ['`backend/app/Console/Commands/SyncPermissions.php`', 'Comando `permissions:sync` (lista de permisos).'],
    ['`backend/resources/views`', 'Plantillas de los PDF (FUR, orden de compra, CLC, cédula, bitácora).'],
    ['`backend/routes/api.php`', 'Rutas de la API y el permiso de cada una.'],
    ['`frontend/src/app/pages`', 'Pantallas del sistema, una carpeta por módulo.'],
    ['`frontend/src/environments`', '`environment.prod.ts`: `apiUrl: \'/api\'`.'],
    ['`desplegar.sh`', 'Script de despliegue (capítulo 5).'],
    ['`docs/manuales/generador`', 'Generador de estos manuales (base de demostración, capturas y Word).']
  ], [4200, 5160])
];

guardar(documento('Manual de producción', contenido), process.argv[2] ?? require('path').join(__dirname, '..', '..', 'SIEG - Manual de producción.docx'));
