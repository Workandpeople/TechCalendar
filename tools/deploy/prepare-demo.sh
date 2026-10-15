#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

PROJECT=$(cd "$(dirname "$0")/../.." && pwd)
cd "$PROJECT"
test "$(git branch --show-current)" = v2 || { echo 'Utiliser la branche v2.' >&2; exit 1; }
OUT=$(mktemp -d "$HOME/Downloads/techcalendar-v2-ready-XXXXXXXX")
trap 'echo "Preparation interrompue. Ne pas deployer le paquet incomplet : $OUT" >&2' ERR
printf 'Preparation privee : %s\n' "$OUT"

docker compose exec -T laravel.test php tools/deploy/export-demo.php metadata > "$OUT/metadata.json"
docker compose exec -T laravel.test php tools/deploy/export-demo.php secrets > "$OUT/demo-secrets.env"
git rev-parse HEAD > "$OUT/source-revision.txt"
git status --porcelain=v1 > "$OUT/source-worktree.txt"

echo 'Export SQL transactionnel, aucune modification de la base source...'
docker compose exec -T mysql sh -ec '
    export MYSQL_PWD="${MYSQL_ROOT_PASSWORD:?Mot de passe local manquant}"
    exec mysqldump --user=root --single-transaction --quick --skip-lock-tables \
        --skip-add-locks --skip-add-drop-table --no-tablespaces \
        --set-gtid-purged=OFF --column-statistics=0 --hex-blob \
        --default-character-set=utf8mb4 techcalendar_v2_simulation
' 2> "$OUT/dump.stderr" | gzip -1 > "$OUT/database-v2.sql.gz.part"
gzip -t "$OUT/database-v2.sql.gz.part"
mv "$OUT/database-v2.sql.gz.part" "$OUT/database-v2.sql.gz"

echo 'Compilation et paquet du code courant, y compris les fichiers V2 non commites...'
npm run build
COPYFILE_DISABLE=1 tar -czf "$OUT/code-v2.tar.gz.part" \
    --exclude='bootstrap/cache/*.php' --exclude='public/hot' \
    --exclude='public/storage' --exclude='.DS_Store' \
    app bootstrap config database lang public resources routes tools/deploy \
    artisan composer.json composer.lock package.json package-lock.json vite.config.js
gzip -t "$OUT/code-v2.tar.gz.part"
mv "$OUT/code-v2.tar.gz.part" "$OUT/code-v2.tar.gz"

RUNTIME_PATHS=(public)
for path in private/crm private/lot-appointment-documents private/lot-imports private/lot-import-previews; do
    if [ -d "$PROJECT/storage/app/$path" ]; then RUNTIME_PATHS+=("$path"); fi
done
COPYFILE_DISABLE=1 tar -czf "$OUT/runtime-files.tar.gz.part" \
    --exclude='.DS_Store' -C "$PROJECT/storage/app" "${RUNTIME_PATHS[@]}"
gzip -t "$OUT/runtime-files.tar.gz.part"
mv "$OUT/runtime-files.tar.gz.part" "$OUT/runtime-files.tar.gz"

(
    cd "$OUT"
    shasum -a 256 database-v2.sql.gz code-v2.tar.gz runtime-files.tar.gz \
        demo-secrets.env metadata.json source-revision.txt source-worktree.txt > SHA256SUMS
)
printf 'Paquet pret, sans les 26 Go deja en transfert.\nEXPORT_DIR=%s\n' "$OUT"
echo 'demo-secrets.env est confidentiel : ne pas afficher, publier ou commiter.'
