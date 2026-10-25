#!/bin/bash
# Reduce las capturas (capturas/*.png) a 1600 px en JPEG para el Word (imgj/) y anota sus medidas. Usa sips (macOS).
set -euo pipefail
D="$(cd "$(dirname "$0")" && pwd)"
mkdir -p "$D/imgj"
: > "$D/imgj/medidas.txt"
for f in "$D"/capturas/*.png; do
    n="$(basename "${f%.png}")"
    sips --resampleWidth 1600 -s format jpeg -s formatOptions 70 "$f" --out "$D/imgj/$n.jpg" >/dev/null
    echo "$n $(sips -g pixelWidth -g pixelHeight "$D/imgj/$n.jpg" | awk '/pixel/{print $2}' | tr '\n' ' ')" >> "$D/imgj/medidas.txt"
done
echo "$(ls "$D"/imgj/*.jpg | wc -l | tr -d ' ') imágenes en imgj/"
