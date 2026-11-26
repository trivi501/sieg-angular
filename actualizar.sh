#!/usr/bin/env bash
#
# Actualiza SIEG en el servidor (correr EN la VM): respaldo de la base, código de GitHub, dependencias, migraciones
# (solo agregan), permisos y roles, cachés, build del frontend y copia a la carpeta que sirve nginx.
# Se detiene en el primer error; si algo falla con el sitio en mantenimiento, lo vuelve a levantar.
#
# Uso:   bash /var/www/sieg/actualizar.sh
#        bash /var/www/sieg/actualizar.sh --sin-respaldo     # no hace el mysqldump
#        bash /var/www/sieg/actualizar.sh --solo-frontend    # solo build y copia del frontend
#
# Se puede cambiar con variables: SIEG_REPO, SIEG_PUBLICO, SIEG_RESPALDOS, SIEG_PHP, SIEG_NODE.

set -euo pipefail

# Todo va dentro de main: bash lee el script completo antes de correrlo, así el git pull puede actualizar este mismo archivo.
main() {

REPO="${SIEG_REPO:-/var/www/sieg}"
PUBLICO="${SIEG_PUBLICO:-/var/www/sieg-web}"
RESPALDOS="${SIEG_RESPALDOS:-$HOME/respaldos-sieg}"
PHP="${SIEG_PHP:-php}"
NODE_VERSION="${SIEG_NODE:-24}"
FECHA="$(date +%Y%m%d_%H%M%S)"

RESPALDO=1
SOLO_FRONT=0
for a in "$@"; do
    case "$a" in
        --sin-respaldo) RESPALDO=0 ;;
        --solo-frontend) SOLO_FRONT=1 ;;
        *) echo "Opción desconocida: $a"; exit 1 ;;
    esac
done

paso() { printf '\n\033[1;34m▶ %s\033[0m\n' "$1"; }
ok() { printf '\033[32m✔ %s\033[0m\n' "$1"; }
falla() { printf '\n\033[31m✘ %s\033[0m\n' "$1" >&2; exit 1; }

EN_MANTENIMIENTO=0
al_salir() {
    local codigo=$?
    if (( EN_MANTENIMIENTO )); then
        (cd "$REPO/backend" && $PHP artisan up >/dev/null 2>&1) || true
        echo "Se volvió a levantar el sitio (artisan up)."
    fi
    if (( codigo != 0 )); then
        printf '\n\033[31m✘ La actualización falló (código %s). Revisa el mensaje de arriba.\033[0m\n' "$codigo" >&2
        [[ -n "${ARCHIVO_RESPALDO:-}" ]] && echo "Respaldo de la base: $ARCHIVO_RESPALDO"
    fi
}
trap al_salir EXIT

[[ -d "$REPO/.git" ]] || falla "No encuentro el repositorio en $REPO (cambia SIEG_REPO)."
[[ -f "$REPO/backend/.env" ]] || falla "No encuentro $REPO/backend/.env."
cd "$REPO"

# --- Código ---
paso "Bajando cambios de GitHub"
# Los archivos de dependencias que npm modifica al instalar no deben bloquear el pull.
git checkout -- frontend/package.json frontend/package-lock.json frontend/yarn.lock 2>/dev/null || true
if [[ -n "$(git status --porcelain --untracked-files=no)" ]]; then
    git status --short --untracked-files=no
    falla "Hay archivos modificados en el servidor (arriba). Revísalos o descártalos con: git checkout -- <archivo>"
fi
ANTES="$(git rev-parse --short HEAD)"
git pull --ff-only
DESPUES="$(git rev-parse --short HEAD)"
if [[ "$ANTES" == "$DESPUES" ]]; then ok "Ya estaba al día ($DESPUES)"; else ok "$ANTES → $DESPUES"; git log --oneline "$ANTES..$DESPUES"; fi

if (( ! SOLO_FRONT )); then
    cd "$REPO/backend"

    # --- Respaldo ---
    if (( RESPALDO )); then
        paso "Respaldando la base de datos"
        leer_env() { grep -E "^$1=" .env | tail -1 | cut -d= -f2- | sed -E 's/^"(.*)"$/\1/; s/^'"'"'(.*)'"'"'$/\1/'; }
        DB_HOST="$(leer_env DB_HOST)"; DB_PORT="$(leer_env DB_PORT)"; DB_DATABASE="$(leer_env DB_DATABASE)"
        DB_USERNAME="$(leer_env DB_USERNAME)"; DB_PASSWORD="$(leer_env DB_PASSWORD)"
        [[ -n "$DB_DATABASE" ]] || falla "No pude leer DB_DATABASE del .env (usa --sin-respaldo si el .env está cifrado)."
        mkdir -p "$RESPALDOS"
        ARCHIVO_RESPALDO="$RESPALDOS/${DB_DATABASE}_${FECHA}.sql.gz"
        MYSQL_PWD="$DB_PASSWORD" mysqldump -h "${DB_HOST:-127.0.0.1}" -P "${DB_PORT:-3306}" -u "$DB_USERNAME" \
            --single-transaction --routines --no-tablespaces "$DB_DATABASE" | gzip > "$ARCHIVO_RESPALDO"
        ok "Respaldo: $ARCHIVO_RESPALDO ($(du -h "$ARCHIVO_RESPALDO" | cut -f1))"
    fi

    # --- Backend ---
    paso "Dependencias de PHP"
    composer install --no-dev --optimize-autoloader --no-interaction

    paso "Migraciones (solo agregan lo que falte)"
    $PHP artisan down --retry=30 || true
    EN_MANTENIMIENTO=1
    $PHP artisan migrate --force
    $PHP artisan permissions:sync
    $PHP artisan db:seed --class=RolesSistemaSeeder --force

    paso "Cachés"
    $PHP artisan optimize:clear >/dev/null
    $PHP artisan config:cache
    $PHP artisan route:cache
    $PHP artisan view:cache
    if grep -qE '^APP_DEBUG=true' .env; then
        printf '\033[33m⚠ APP_DEBUG=true en producción: cámbialo a false en backend/.env\033[0m\n'
    fi
    $PHP artisan up
    EN_MANTENIMIENTO=0
    ok "Backend actualizado"
fi

# --- Frontend ---
paso "Compilando el frontend (Node $NODE_VERSION)"
cd "$REPO/frontend"
export NVM_DIR="${NVM_DIR:-$HOME/.nvm}"
# shellcheck disable=SC1091
[[ -s "$NVM_DIR/nvm.sh" ]] && source "$NVM_DIR/nvm.sh" && nvm use "$NODE_VERSION" >/dev/null
node -v
rm -rf dist .angular
npm ci --no-audit --no-fund
npx ng build --configuration production
[[ -f dist/index.html ]] || falla "El build no generó dist/index.html."
git checkout -- package.json package-lock.json yarn.lock 2>/dev/null || true

paso "Copiando el frontend a $PUBLICO (carpeta de nginx)"
sudo mkdir -p "$PUBLICO"
sudo rsync -a --delete dist/ "$PUBLICO/"
ok "Frontend publicado"

paso "Listo"
echo "Versión: $(git -C "$REPO" log --oneline -1)"
echo "Recarga el navegador con Ctrl + Shift + R."
}

main "$@"
