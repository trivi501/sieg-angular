#!/bin/bash
# Vuelve a copiar la base sieg en sieg_demo y vacía el storage de demostración.
#   MYSQL_DIR       carpeta de mysql y mysqldump (por omisión, la del PATH o la de DBngin)
#   MYSQL_OPCIONES  conexión (por omisión: -uroot -h127.0.0.1)
set -euo pipefail
M="${MYSQL_DIR:-$(dirname "$(command -v mysql 2>/dev/null || echo /Users/Shared/DBngin/mysql/8.0.33/bin/mysql)")}"
O="${MYSQL_OPCIONES:--uroot -h127.0.0.1}"
D="$(cd "$(dirname "$0")" && pwd)"
"$M/mysql" $O -e "DROP DATABASE IF EXISTS sieg_demo; CREATE DATABASE sieg_demo CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
"$M/mysqldump" $O --single-transaction --routines --triggers sieg | "$M/mysql" $O sieg_demo
rm -rf "$D/storage/app/private/"* "$D/archivos/"*
echo "sieg_demo lista"
