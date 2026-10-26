#!/bin/bash
# Vuelve a copiar la base de origen (sieg) en sieg_demo y vacía el storage de demostración.
#   ORIGEN          base que se copia (por omisión: sieg; en la máquina de desarrollo con Laragon: sieg_angular)
#   MYSQL_DIR       carpeta de mysql y mysqldump (por omisión, la del PATH, la de Laragon o la de DBngin)
#   MYSQL_OPCIONES  conexión (por omisión: -uroot -h127.0.0.1)
set -euo pipefail
LARAGON="$(ls -d /c/laragon/bin/mysql/mysql-*/bin 2>/dev/null | tail -1 || true)"
M="${MYSQL_DIR:-$(dirname "$(command -v mysql 2>/dev/null || { [[ -n "$LARAGON" ]] && echo "$LARAGON/mysql"; } || echo /Users/Shared/DBngin/mysql/8.0.33/bin/mysql)")}"
O="${MYSQL_OPCIONES:--uroot -h127.0.0.1}"
ORIGEN="${ORIGEN:-sieg}"
D="$(cd "$(dirname "$0")" && pwd)"
"$M/mysql" $O -e "DROP DATABASE IF EXISTS sieg_demo; CREATE DATABASE sieg_demo CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
"$M/mysqldump" $O --single-transaction --routines --triggers "$ORIGEN" | "$M/mysql" $O sieg_demo
rm -rf "$D/storage/app/private/"* "$D/archivos/"*
echo "sieg_demo lista (copia de $ORIGEN)"
