#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
HERE=$(cd -- "$(dirname -- "$0")" && pwd -P)
REPO=$(cd -- "$HERE/../.." && pwd -P)
cd "$REPO"
[ "$#" = 1 ] && [[ "$1" =~ ^[a-f0-9]{40}$ ]] || { echo 'Usage : prepare-production-update.sh REVISION_RUNTIME_ACTUELLE' >&2; exit 1; }
[ "$(git branch --show-current)" = v2 ] && [ -z "$(git status --porcelain)" ] || { echo 'Checkout V2 propre requis.' >&2; exit 1; }
REVISION=$(git rev-parse HEAD)
git merge-base --is-ancestor "$1" "$REVISION"
OUT=$(mktemp -d "$HOME/Downloads/cofrac2-update-XXXXXXXX")
BUILD=$(mktemp -d)
trap 'rm -rf -- "$BUILD"' EXIT
git archive "$REVISION" app resources routes public package.json package-lock.json vite.config.js | tar -xf - -C "$BUILD"
# Never build with the developer's .env, API keys or localhost VITE_* overrides.
(cd "$BUILD" && env -i HOME="$HOME" PATH="$PATH" npm ci --ignore-scripts --include=dev \
    && env -i HOME="$HOME" PATH="$PATH" NODE_ENV=production npm run build) > "$OUT-build.log" 2>&1
install -d -m 0700 "$OUT/build"
cp -R "$BUILD/public/build/." "$OUT/build/"
find "$OUT" -type d -exec chmod 0700 {} +
find "$OUT" -type f -exec chmod 0600 {} +
php -r '
    require $argv[1]."/tools/deploy/lib/ProductionAssetUpdate.php";
    $sha=Cofrac2\Deploy\ProductionAssetUpdate::seal($argv[2],$argv[3],$argv[4]);
    echo "PACKAGE=".$argv[2]."\nSHA256=".$sha."\nPaquet assets uniquement, sans .env, secret, base ou document.\n";
' "$REPO" "$OUT" "$REVISION" "$1"
