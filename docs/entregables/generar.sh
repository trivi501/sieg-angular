#!/bin/bash
# Arma el expediente de entregables de SIEG en docs/entregables/SIEG - Expediente de entregables/ y su ZIP:
#   01 Acta de entrega-recepción · 02 Matriz de cumplimiento · 03 Documentación técnica · 04 Manuales ·
#   05 Código fuente (el commit actual) · 06 Base de datos (estructura y catálogos, sin datos de operación)
# Variables:
#   ORIGEN          base de la que se toma la estructura (por omisión, DB_DATABASE de backend/.env)
#   MYSQL_DIR       carpeta de mysql y mysqldump (por omisión, la del PATH, la de Laragon o la de DBngin)
#   MYSQL_OPCIONES  conexión (por omisión: -uroot -h127.0.0.1)
# El código fuente sale de git (HEAD): haz commit antes de generarlo.
set -euo pipefail
D="$(cd "$(dirname "$0")" && { pwd -W 2>/dev/null || pwd; })"
RAIZ="$(cd "$D/../.." && pwd)"
SAL="$D/SIEG - Expediente de entregables"
LARAGON="$(ls -d /c/laragon/bin/mysql/mysql-*/bin 2>/dev/null | tail -1 || true)"
M="${MYSQL_DIR:-$(dirname "$(command -v mysqldump 2>/dev/null || { [[ -n "$LARAGON" ]] && echo "$LARAGON/mysqldump"; } || echo /Users/Shared/DBngin/mysql/8.0.33/bin/mysqldump)")}"
O="${MYSQL_OPCIONES:--uroot -h127.0.0.1}"
ORIGEN="${ORIGEN:-$(grep -E '^DB_DATABASE=' "$RAIZ/backend/.env" | cut -d= -f2 | tr -d '\r"')}"
COMMIT="$(git -C "$RAIZ" rev-parse --short HEAD)"
[[ -z "$(git -C "$RAIZ" status --porcelain -- backend frontend)" ]] || echo "Aviso: hay cambios sin commit en backend/frontend; el código fuente del expediente es el commit $COMMIT."

echo "Expediente de $COMMIT con la estructura de $ORIGEN"
rm -rf "$SAL"
mkdir -p "$SAL/04 Manuales" "$SAL/05 Código fuente" "$SAL/06 Base de datos"
[[ -d "$RAIZ/docs/manuales/generador/js/node_modules" ]] || (cd "$RAIZ/docs/manuales/generador/js" && npm install --silent --no-audit --no-fund)

echo "1. Datos del sistema"
DB_DATABASE="$ORIGEN" php "$D/datos.php" > "$D/datos.json"
export DATOS_JSON="$D/datos.json" SALIDA="$SAL"

echo "2. Matriz de cumplimiento y documentación técnica"
node "$D/js/generar.js" documentos

echo "3. Manuales"
cp "$RAIZ/docs/manuales/"*.docx "$SAL/04 Manuales/"

echo "4. Código fuente"
git -C "$RAIZ" archive --format=zip --prefix=sieg-angular/ -o "$SAL/05 Código fuente/sieg-angular-$COMMIT.zip" HEAD

echo "5. Base de datos"
# Catálogos (cat_*), roles y permisos, migraciones y tipos de documento: lo necesario para arrancar sin datos de operación.
CATALOGOS="$("$M/mysql" $O -N -e "SELECT GROUP_CONCAT(table_name ORDER BY table_name SEPARATOR ' ') FROM information_schema.tables
  WHERE table_schema = '$ORIGEN' AND (table_name LIKE 'cat\\_%' OR table_name IN ('migrations', 'permissions', 'roles', 'role_has_permissions', 'tipos_documento'))")"
"$M/mysqldump" $O --no-data --routines --triggers --skip-comments "$ORIGEN" | sed -E 's/ AUTO_INCREMENT=[0-9]+//' > "$SAL/06 Base de datos/sieg_1_estructura.sql"
"$M/mysqldump" $O --no-create-info --skip-triggers --complete-insert --skip-comments "$ORIGEN" $CATALOGOS > "$SAL/06 Base de datos/sieg_2_catalogos.sql"
cat > "$SAL/06 Base de datos/LEEME.txt" <<EOF
Base de datos de SIEG (versión $COMMIT)

  sieg_1_estructura.sql  Todas las tablas, vistas, rutinas y disparadores, sin datos.
  sieg_2_catalogos.sql   Catálogos (cat_*), roles, permisos, tipos de documento y el registro de migraciones.

No incluye datos de operación ni personales (usuarios, presupuesto, requisiciones, facturas, pagos, proveedores).

Para crear una base nueva:
  mysql -u <usuario> -p -e "CREATE DATABASE sieg CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
  mysql -u <usuario> -p sieg < sieg_1_estructura.sql
  mysql -u <usuario> -p sieg < sieg_2_catalogos.sql
Después, en backend/: php artisan migrate --force && php artisan permissions:sync
y crea el primer administrador (Manual de producción, 3.5).
EOF

echo "6. Acta de entrega-recepción"
node "$D/js/generar.js" acta
cat > "$SAL/LEEME.txt" <<EOF
Expediente de entregables de SIEG · Sistema Integral de Egresos Municipales · Municipio de Guadalupe, Zacatecas
Versión $COMMIT

01 Acta de entrega-recepción.docx   Relación de entregables con la huella SHA-256 de cada archivo, observaciones y firmas.
02 Matriz de cumplimiento.docx       Cada punto de la propuesta (v2.3) contra lo entregado.
03 Documentación técnica.docx        Arquitectura, módulos, modelo de datos, API, seguridad y desarrollo.
04 Manuales                          Manual de usuario y Manual de producción.
05 Código fuente                     Repositorio sieg-angular (backend Laravel, frontend Angular).
06 Base de datos                     Estructura y catálogos, sin datos de operación (ver su LEEME.txt).

Al abrir los documentos de Word, acepta actualizar los campos para llenar el índice.
EOF

echo "7. ZIP"
php "$D/comprimir.php" "$SAL" "$D/SIEG - Expediente de entregables.zip"
