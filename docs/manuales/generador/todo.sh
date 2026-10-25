#!/bin/bash
# Reconstruye la base de demostración completa.
set -e
D="$(cd "$(dirname "$0")" && pwd)"
"$D/reset.sh"
cd "$D/../../../backend"
for s in "$D"/0*.php; do echo "=== $(basename "$s")"; php -d memory_limit=2G "$s"; done
php "$D/ids.php" > "$D/ids.json"
echo "ids.json listo"
