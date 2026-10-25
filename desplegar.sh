#!/usr/bin/env bash
#
# Despliega SIEG a producción por SSH: respaldo de la base, código de main, dependencias, migraciones, cachés y
# el build del frontend. Se detiene en el primer error; si algo falla con el sitio en mantenimiento, lo vuelve a
# levantar y dice dónde quedó el respaldo.
#
# Uso (los datos del servidor van en variables, no en el repo):
#
#   SIEG_HOST=mi-servidor.com SIEG_USUARIO=deploy SIEG_REPO=/var/www/sieg \
#   SIEG_PUBLICO=/var/www/sieg-web SIEG_URL=https://sieg.guadalupe.gob.mx ./desplegar.sh
#
#   ./desplegar.sh --simular   # muestra lo que haría, sin conectarse ni cambiar nada
#
# Variables:
#   SIEG_HOST       servidor (obligatoria)
#   SIEG_USUARIO    usuario SSH (obligatoria)
#   SIEG_PUERTO     puerto SSH (22 por omisión)
#   SIEG_REPO       ruta del clon de este repositorio en el servidor (obligatoria); el backend es $SIEG_REPO/backend
#   SIEG_PUBLICO    carpeta que publica el servidor web para el frontend (obligatoria)
#   SIEG_URL        dirección del sistema, para revisar que responda al final (opcional)
#   SIEG_BASE_HREF  ruta del frontend en el dominio ("/" por omisión; p. ej. "/sieg/")
#   SIEG_PHP        php del servidor ("php" por omisión; p. ej. "php8.4")
#   SIEG_COMPOSER   composer del servidor ("composer" por omisión)
#
# Requisitos en el servidor: el repositorio ya clonado en SIEG_REPO con su backend/.env de producción, PHP, Composer
# y mysqldump. En esta máquina: node con las dependencias del frontend instaladas, ssh y rsync.

set -euo pipefail

SIMULAR=0
[[ "${1:-}" == "--simular" ]] && SIMULAR=1

RAIZ="$(cd "$(dirname "$0")" && pwd)"
PUERTO="${SIEG_PUERTO:-22}"
BASE_HREF="${SIEG_BASE_HREF:-/}"
PHP="${SIEG_PHP:-php}"
COMPOSER="${SIEG_COMPOSER:-composer}"
FECHA="$(date +%Y%m%d_%H%M%S)"

paso() { printf '\n\033[1m▶ %s\033[0m\n' "$1"; }
falla() { printf '\n\033[31m✘ %s\033[0m\n' "$1" >&2; exit 1; }

faltan=()
for v in SIEG_HOST SIEG_USUARIO SIEG_REPO SIEG_PUBLICO; do
    [[ -n "${!v:-}" ]] || faltan+=("$v")
done
if (( ${#faltan[@]} )) && (( ! SIMULAR )); then
    falla "Faltan variables: ${faltan[*]}. Ve el encabezado de este script."
fi
HOST="${SIEG_HOST:-<SIEG_HOST>}"
USUARIO="${SIEG_USUARIO:-<SIEG_USUARIO>}"
REPO="${SIEG_REPO:-<SIEG_REPO>}"
PUBLICO="${SIEG_PUBLICO:-<SIEG_PUBLICO>}"
DESTINO="${USUARIO}@${HOST}"

# Lo que se corre en el servidor. Las variables se resuelven aquí; \$ se deja para el servidor.
REMOTO=$(cat <<EOF
set -euo pipefail
cd "${REPO}/backend"

echo "▶ Respaldo de la base"
set -a; . ./.env; set +a
mkdir -p ~/respaldos
RESPALDO=~/respaldos/sieg_\${DB_DATABASE}_${FECHA}.sql.gz
MYSQL_PWD="\${DB_PASSWORD}" mysqldump --single-transaction --routines --triggers --no-tablespaces \
    -h "\${DB_HOST:-127.0.0.1}" -P "\${DB_PORT:-3306}" -u "\${DB_USERNAME}" "\${DB_DATABASE}" | gzip > "\${RESPALDO}"
[ -s "\${RESPALDO}" ] || { echo "El respaldo quedó vacío: no se sigue."; exit 1; }
echo "  \${RESPALDO} (\$(du -h "\${RESPALDO}" | cut -f1))"

echo "▶ Código de main"
cd "${REPO}"
git fetch origin
git checkout main
git pull --ff-only origin main
cd backend

echo "▶ Mantenimiento"
${PHP} artisan down --retry=60
trap 'echo "Falló: el sitio vuelve a estar arriba. Respaldo de la base: \${RESPALDO}"; ${PHP} artisan up' ERR

echo "▶ Dependencias"
${COMPOSER} install --no-dev --optimize-autoloader --no-interaction

echo "▶ Migraciones"
${PHP} artisan migrate --force

echo "▶ Cachés"
${PHP} artisan optimize:clear
${PHP} artisan config:cache
${PHP} artisan route:cache
${PHP} artisan view:cache

trap - ERR
${PHP} artisan up
echo "▶ Backend listo. Respaldo de la base: \${RESPALDO}"
EOF
)

paso "Revisión local"
cd "$RAIZ"
rama="$(git branch --show-current)"
[[ "$rama" == "main" ]] || falla "Estás en la rama '${rama}': se despliega main."
git fetch -q origin main
[[ "$(git rev-parse HEAD)" == "$(git rev-parse origin/main)" ]] || falla "main local y origin/main no coinciden: sube o actualiza main antes de desplegar."
echo "  main en $(git log --oneline -1)"

paso "Build de producción del frontend (base-href ${BASE_HREF})"
if (( SIMULAR )); then
    echo "  (simulado) npx ng build --configuration production --base-href ${BASE_HREF}"
else
    (cd frontend && npx ng build --configuration production --base-href "${BASE_HREF}")
fi

paso "Servidor ${DESTINO} (puerto ${PUERTO}): respaldo, código, dependencias, migraciones y cachés"
if (( SIMULAR )); then
    echo "  (simulado) ssh -p ${PUERTO} ${DESTINO} bash -s <<'REMOTO'"
    printf '%s\n' "$REMOTO" | sed 's/^/    /'
else
    ssh -p "$PUERTO" "$DESTINO" bash -s <<<"$REMOTO"
fi

paso "Frontend → ${PUBLICO}"
# Sin --delete: no se borra nada que ya esté en la carpeta pública (otros archivos del sitio).
if (( SIMULAR )); then
    echo "  (simulado) rsync -az -e 'ssh -p ${PUERTO}' frontend/dist/ ${DESTINO}:${PUBLICO}/"
else
    rsync -az -e "ssh -p ${PUERTO}" frontend/dist/ "${DESTINO}:${PUBLICO}/"
fi

if [[ -n "${SIEG_URL:-}" ]]; then
    paso "Revisión de ${SIEG_URL}"
    if (( SIMULAR )); then
        echo "  (simulado) curl ${SIEG_URL} y ${SIEG_URL%/}/up"
    else
        web=$(curl -s -o /dev/null -w '%{http_code}' "${SIEG_URL}")
        salud=$(curl -s -o /dev/null -w '%{http_code}' "${SIEG_URL%/}/up")
        echo "  Frontend: HTTP ${web} · backend (/up): HTTP ${salud}"
        [[ "$web" == "200" && "$salud" == "200" ]] || falla "El sistema no respondió 200: revísalo."
    fi
fi

printf '\n\033[32m✔ Despliegue %s\033[0m\n' "$( (( SIMULAR )) && echo 'simulado' || echo 'terminado')"
