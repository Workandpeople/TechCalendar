#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

PROJECT=$(cd "$(dirname "$0")/../.." && pwd)
cd "$PROJECT"
test "$(git branch --show-current)" = v2
test -z "$(git status --porcelain)" || { echo 'Commiter les changements avant de preparer le paquet.' >&2; exit 1; }
REVISION=$(git rev-parse HEAD)
OUT=$(mktemp -d "$HOME/Downloads/techcalendar-v2-update-XXXXXXXX")
STAGE=$(mktemp -d)
trap 'rm -rf -- "$STAGE"' EXIT
echo "Preparation du code $REVISION dans $OUT"

npm ci --ignore-scripts
npm run build
git archive "$REVISION" app bootstrap config database lang public resources routes tools/deploy \
    artisan composer.json composer.lock package.json package-lock.json vite.config.js | tar -xf - -C "$STAGE"
mkdir -p "$STAGE/public/build"
cp -R public/build/. "$STAGE/public/build/"
# No environment, database, documents, vendor or node_modules in this package.
COPYFILE_DISABLE=1 tar -czf "$OUT/code-v2.tar.gz" -C "$STAGE" .
cp tools/deploy/update-demo.sh "$OUT/update-demo.sh"
printf '%s\n' "$REVISION" > "$OUT/revision.txt"
(cd "$OUT" && shasum -a 256 code-v2.tar.gz update-demo.sh revision.txt > SHA256SUMS)
gzip -t "$OUT/code-v2.tar.gz"
echo "PAQUET_CODE=$OUT"
