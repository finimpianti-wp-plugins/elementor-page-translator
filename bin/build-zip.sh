#!/usr/bin/env bash
# Crea lo zip installabile del plugin in dist/<slug>-<versione>.zip, senza i file di sviluppo
# (elencati in .distignore). Usato in locale e dalla release automatica di GitHub.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
MAIN="$(grep -l -m1 '^[[:space:]]*\*[[:space:]]*Plugin Name:' ./*.php | head -1)"
SLUG="$(basename "$MAIN" .php)"
VERSION="$(grep -m1 -E '^[[:space:]]*\*[[:space:]]*Version:' "$MAIN" | sed -E 's/.*Version:[[:space:]]*//; s/[[:space:]]+$//')"
echo "== $SLUG $VERSION"
find . -name '*.php' -not -path './lib/*' -not -path './dist/*' -print0 | xargs -0 -n1 php -l >/dev/null
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
rsync -a --exclude-from=.distignore ./ "$TMP/$SLUG/"
mkdir -p dist
rm -f "dist/$SLUG-$VERSION.zip"
(cd "$TMP" && zip -qr "$ROOT/dist/$SLUG-$VERSION.zip" "$SLUG")
echo "$ROOT/dist/$SLUG-$VERSION.zip"
