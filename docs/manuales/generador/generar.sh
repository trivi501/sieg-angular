#!/bin/bash
# Regenera todo: base de demostración, capturas e imágenes, y los dos manuales en docs/manuales/.
set -euo pipefail
D="$(cd "$(dirname "$0")" && pwd)"
[[ -d "$D/js/node_modules" ]] || (cd "$D/js" && npm install --silent)
"$D/todo.sh"
"$D/servidor.sh" > "$D/servidor.log" 2>&1 &
SERVIDOR=$!
trap 'kill $SERVIDOR 2>/dev/null || true' EXIT
for i in $(seq 1 120); do curl -sf -o /dev/null http://127.0.0.1:8090/up && break; sleep 2; done
(cd "$D/js" && node capturas.js)
"$D/imagenes.sh"
(cd "$D/js" && node manual_usuario.js && node manual_produccion.js)
