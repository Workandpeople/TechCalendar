#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

# Run only in a disposable Linux container with GNU rsync, root and www-data.
test "$EUID" -eq 0
command -v rsync >/dev/null
id www-data >/dev/null
SCRIPT=$(cd "$(dirname "$0")/.." && pwd)/update-demo.sh
FIXTURE=$(mktemp -d)
trap 'rm -rf -- "$FIXTURE"' EXIT
chmod 0755 "$FIXTURE"
export STAGE="$FIXTURE/source" APP="$FIXTURE/app"
mkdir -p "$STAGE/public" "$STAGE/bootstrap/cache" "$APP/public/build" "$APP/bootstrap/cache" "$APP/storage"
printf 'old\n' > "$APP/artisan"
printf 'new artisan\n' > "$STAGE/artisan"
printf '<?php echo "ok";\n' > "$STAGE/public/index.php"
printf 'local secret\n' > "$APP/.env"
printf 'private cache\n' > "$APP/bootstrap/cache/config.php"
printf 'private document\n' > "$APP/storage/document.pdf"
printf 'old asset\n' > "$APP/public/build/previous.js"
printf 'must stay excluded\n' > "$STAGE/bootstrap/cache/config.php"
chown -R www-data:www-data "$APP"

# Execute the actual production copy command, not a reimplementation of its flags.
transfer=$(sed -n '/^rsync -r/,/^    "\$STAGE\/" "\$APP\/"/p' "$SCRIPT")
test -n "$transfer"
bash -c "${transfer/rsync -rpt/rsync -rt}"
if runuser -u www-data -- test -r "$APP/artisan"; then
    echo 'FAIL: the old permissions bug was not reproduced.' >&2
    exit 1
fi
echo 'Reproduced: old rsync flags make artisan unreadable by www-data.'
bash -c "$transfer"

runuser -u www-data -- test -r "$APP/artisan"
runuser -u www-data -- test -r "$APP/public/index.php"
test "$(stat -c %a "$APP")" = 755
test "$(stat -c %a "$APP/artisan")" = 644
test "$(stat -c %a "$APP/public/index.php")" = 644
test "$(stat -c %U "$APP/artisan")" = root
test "$(stat -c %a "$APP/.env")" = 600
test "$(stat -c %U "$APP/.env")" = www-data
test "$(cat "$APP/.env")" = 'local secret'
test "$(stat -c %a "$APP/bootstrap/cache/config.php")" = 600
test "$(stat -c %U "$APP/bootstrap/cache/config.php")" = www-data
test "$(cat "$APP/bootstrap/cache/config.php")" = 'private cache'
test "$(cat "$APP/storage/document.pdf")" = 'private document'
test -f "$APP/public/build/previous.js"
echo 'PASS: copied code readable by www-data under umask 077; secrets, cache and storage unchanged.'
