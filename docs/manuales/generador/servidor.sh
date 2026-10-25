#!/bin/bash
# Servidor de demostración en http://127.0.0.1:8090: el frontend compilado y la API contra sieg_demo, en el mismo origen.
# Uso: ./servidor.sh [--sin-build]
set -euo pipefail
D="$(cd "$(dirname "$0")" && pwd)"
RAIZ="$(cd "$D/../../.." && pwd)"
if [[ "${1:-}" != "--sin-build" ]]; then
    (cd "$RAIZ/frontend" && npx ng build --configuration production --base-href / --output-path "$D/dist")
fi
mkdir -p "$D/storage/framework/"{cache/data,sessions,views} "$D/storage/logs" "$D/storage/app/private"
DB_DATABASE=sieg_demo LARAVEL_STORAGE_PATH="$D/storage" CACHE_STORE=array APP_URL=http://127.0.0.1:8090 FRONTEND_URL=http://127.0.0.1:8090 \
    exec php -d memory_limit=1G -S 127.0.0.1:8090 "$D/router.php"
