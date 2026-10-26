#!/bin/bash
# Servidor de demostración en http://127.0.0.1:8090: el frontend compilado y la API contra sieg_demo, en el mismo origen.
# En Windows (Git Bash) lo sirve js/proxy.js, con la API por php-cgi (ver ahí por qué).
# Uso: ./servidor.sh [--sin-build]
set -euo pipefail
# En Git Bash (Windows), pwd -W da la ruta como la entiende PHP (C:/…), no /c/….
D="$(cd "$(dirname "$0")" && { pwd -W 2>/dev/null || pwd; })"
RAIZ="$(cd "$D/../../.." && pwd)"
if [[ "${1:-}" != "--sin-build" ]]; then
    # MSYS_NO_PATHCONV: en Git Bash, "/" se convertiría en la carpeta de Git (C:/Program Files/Git/).
    (cd "$RAIZ/frontend" && MSYS_NO_PATHCONV=1 npx ng build --configuration production --base-href / --output-path "$D/dist")
fi
mkdir -p "$D/storage/framework/"{cache/data,sessions,views} "$D/storage/logs" "$D/storage/app/private"
export DB_DATABASE=sieg_demo LARAVEL_STORAGE_PATH="$D/storage" CACHE_STORE=array APP_URL=http://127.0.0.1:8090 FRONTEND_URL=http://127.0.0.1:8090
case "$(uname -s)" in
    MINGW* | MSYS* | CYGWIN*)
        PHP_CGI="${PHP_CGI:-$(dirname "$(command -v php)")/php-cgi.exe}"
        exec node "$D/js/proxy.js" "$D/dist/browser" 8090 "$(cygpath -m "$PHP_CGI")" "$(cd "$RAIZ/backend/public" && pwd -W)"
        ;;
    *)
        exec php -d memory_limit=1G -S 127.0.0.1:8090 "$D/router.php"
        ;;
esac
