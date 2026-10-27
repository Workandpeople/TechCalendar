#!/usr/bin/env bash

# Explicit one-way approval of real email only. Never dispatch or retry a message.
set +x
set -Eeuo pipefail
umask 077
export PATH=/usr/sbin:/usr/bin:/sbin:/bin
export LC_ALL=C
stop() { printf 'STOP : %s\n' "$1" >&2; exit 1; }
[ "${EUID:-$(id -u)}" = 0 ] && [ "$(hostname -s)" = ns365601 ] && [ "$#" = 1 ] \
    && [ "$1" = --enable-real-mail ] || stop 'sudo sur ns365601 avec --enable-real-mail requis.'
APP=/var/www/cofrac2
STATE=/var/lib/cofrac2-production
HERE=$(cd -- "$(dirname -- "$0")" && pwd -P)
[ "$HERE" = "$APP/tools/deploy" ] && [ "$(realpath "$APP")" = "$APP" ] \
    && [ ! -L "$STATE" ] && [ "$(stat -c %u:%a "$STATE")" = 0:700 ] || stop 'chemins V2 inattendus.'
for command in php8.4 runuser git systemctl flock install cmp cp sha256sum curl; do
    command -v "$command" >/dev/null || stop "outil manquant : $command"
done
exec 8>"$STATE/mail-enablement.lock"
flock -n 8 || stop 'activation mail deja en cours.'
git_app() { runuser -u cofrac2-deploy -- git -C "$APP" "$@"; }
app_cli() { runuser -u cofrac2-app -- env -i PATH="$PATH" HOME=/var/lib/cofrac2-app USER=cofrac2-app LOGNAME=cofrac2-app php8.4 -d memory_limit=768M "$@"; }
[ "$(git_app branch --show-current)" = v2 ] && [ -z "$(git_app status --porcelain)" ] || stop 'checkout V2 propre requis.'
REV=$(git_app rev-parse HEAD)
[ -f "$STATE/INTERNAL-SITE-OPEN.json" ] && [ ! -L "$STATE/INTERNAL-SITE-OPEN.json" ] || stop 'V2 ouverte attendue avant activation.'
for name in php-fpm queue reverb; do
    systemctl is-active --quiet "cofrac2-$name.service" || stop "service V2 inactif : $name"
done
[ ! -L "$APP/.env" ] && [ "$(stat -c %U:%G:%a "$APP/.env")" = root:cofrac2-app:640 ] \
    && [ ! -L "$STATE/RUNTIME-PREPARED.json" ] && [ "$(stat -c %u:%a "$STATE/RUNTIME-PREPARED.json")" = 0:600 ] \
    || stop 'configuration privee attendue.'
php8.4 -r '
    $r=json_decode(file_get_contents($argv[1]),true,64,JSON_THROW_ON_ERROR);
    if($r["revision"]!==$argv[2] || !hash_equals($r["env_sha256"],hash_file("sha256",$argv[3])))exit(1);
' "$STATE/RUNTIME-PREPARED.json" "$REV" "$APP/.env" || stop 'revision ou configuration non preparee.'

WORK=$(mktemp -d "$STATE/mail-enablement-$(date -u +%Y%m%dT%H%M%SZ)-XXXXXXXX")
printf 'Controle avant maintenance ; aucun email envoye. Diagnostic prive : %s\n' "$WORK"
app_cli "$HERE/production-runtime.php" --check > "$WORK/runtime.before.json"
if php8.4 -r '$r=json_decode(file_get_contents($argv[1]),true,32,JSON_THROW_ON_ERROR);exit($r["mail_delivery_enabled"]===true?0:1);' "$WORK/runtime.before.json"; then
    printf 'Emails reels deja actives et verifies. SMS desactives/en simulation ; aucune modification ni aucun envoi.\n'
    exit 0
fi
app_cli "$HERE/production-mail.php" --inspect-disabled > "$WORK/inspection.before.json"
cp -p "$APP/.env" "$WORK/env.before"
cp -p "$STATE/RUNTIME-PREPARED.json" "$WORK/prepared.before.json"
if [ -f "$APP/bootstrap/cache/config.php" ] && [ ! -L "$APP/bootstrap/cache/config.php" ]; then
    cp -p "$APP/bootstrap/cache/config.php" "$WORK/config.before.php"
else stop 'cache de configuration V2 attendu.'; fi
(cd "$WORK" && sha256sum env.before prepared.before.json config.before.php > BACKUP-SHA256SUMS)
maintenance=0
config_changed=0
success=0
cleanup() {
    result=$?
    trap - EXIT
    exec 9>&-
    if [ "$success" != 1 ] && [ "$maintenance" = 1 ]; then
        if [ "$config_changed" = 1 ]; then
            install -o root -g cofrac2-app -m 0640 "$WORK/env.before" "$APP/.env"
            install -o root -g root -m 0600 "$WORK/prepared.before.json" "$STATE/RUNTIME-PREPARED.json"
            cp -p "$WORK/config.before.php" "$APP/bootstrap/cache/config.php"
        fi
        # Reopen only after proving the original disabled-mail configuration was restored.
        if cmp -s "$WORK/env.before" "$APP/.env" \
            && cmp -s "$WORK/prepared.before.json" "$STATE/RUNTIME-PREPARED.json" \
            && app_cli "$HERE/production-runtime.php" --check > "$WORK/runtime.restored.json" 2> "$WORK/runtime.restored.stderr"; then
            bash "$HERE/configure-production-runtime.sh" --open --reload-shared-apache 8>&- > "$WORK/reopen-restored.log" 2>&1 \
                && printf 'Activation annulee ; V2 rouverte avec les emails toujours bloques.\n' >&2 \
                || printf 'Verifier la reouverture V2 dans %s/reopen-restored.log.\n' "$WORK" >&2
        else printf 'Restauration non validee : V2 maintenue fermee ; consulter %s.\n' "$WORK" >&2; fi
    fi
    if [ "$result" != 0 ]; then printf 'Diagnostic prive : %s. Aucun job rejoue ou supprime.\n' "$WORK" >&2; fi
    exit "$result"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

bash "$HERE/configure-production-runtime.sh" --maintenance --reload-shared-apache 8>&-
maintenance=1
exec 9>"$STATE/runtime-operation.lock"
flock -n 9 || stop 'operation runtime concurrente.'
for name in php-fpm queue reverb; do
    ! systemctl is-active --quiet "cofrac2-$name.service" || stop 'services V2 encore actifs.'
done
[ "$(git_app rev-parse HEAD)" = "$REV" ] && [ -z "$(git_app status --porcelain)" ] || stop 'code modifie pendant le controle.'
cmp -s "$WORK/env.before" "$APP/.env" && cmp -s "$WORK/prepared.before.json" "$STATE/RUNTIME-PREPARED.json" \
    || stop 'configuration modifiee pendant le controle.'
app_cli "$HERE/production-mail.php" --inspect-disabled > "$WORK/inspection.closed.json"
config_changed=1
php8.4 "$HERE/production-mail.php" --enable "$WORK" "$REV"
app_cli "$APP/artisan" config:cache --no-interaction --no-ansi > "$WORK/config-cache.log" 2>&1
app_cli "$HERE/production-mail.php" --inspect-enabled > "$WORK/inspection.after.json"
app_cli "$HERE/production-runtime.php" --check > "$WORK/runtime.after.json"
exec 9>&-
bash "$HERE/configure-production-runtime.sh" --open --reload-shared-apache 8>&-
success=1
cat "$WORK/inspection.after.json"
printf 'Emails reels actives pour les prochains envois. SMS desactives/en simulation.\n'
printf 'Aucun email de test, aucune simulation rejouee, aucun job echoue relance. V2 rouverte et verifiee.\n'
