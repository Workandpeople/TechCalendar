#!/usr/bin/env bash

# Both hostnames use the existing V2. Never import SQL or touch original app files.
set +x
set -Eeuo pipefail
umask 077
export LC_ALL=C
export PATH=/usr/sbin:/usr/bin:/sbin:/bin
stop() { printf 'STOP : %s\n' "$1" >&2; exit 1; }
[ "${EUID:-$(id -u)}" = 0 ] && [ "$(hostname -s)" = ns365601 ] && [ "$#" = 2 ] \
    && [ "$2" = --reload-shared-apache ] || stop 'root sur ns365601 et consentement au rechargement Apache requis.'
case "$1" in --activate|--rollback) ;; *) stop 'Usage : --activate|--rollback --reload-shared-apache' ;; esac
ACTION=$1
APP=/var/www/cofrac2
STATE=/var/lib/cofrac2-production
HERE=$(cd -- "$(dirname -- "$0")" && pwd -P)
[ "$HERE" = "$APP/tools/deploy" ] && [ "$(realpath "$APP")" = "$APP" ] || stop 'checkout V2 attendu.'
[ -d "$STATE" ] && [ ! -L "$STATE" ] && [ "$(stat -c %u:%a "$STATE")" = 0:700 ] || stop 'etat prive inattendu.'
for command in php8.4 runuser systemctl git sha256sum flock install cmp curl apache2ctl find sort xargs; do
    command -v "$command" >/dev/null || stop "outil manquant : $command"
done
exec 8>"$STATE/production-domains.lock"
flock -n 8 || stop 'operation de domaine deja en cours.'
git_app() { runuser -u cofrac2-deploy -- env HOME=/home/cofrac2-deploy git -C "$APP" "$@"; }
[ "$(git_app branch --show-current)" = v2 ] && [ -z "$(git_app status --porcelain)" ] || stop 'checkout V2 propre requis.'
REV=$(git_app rev-parse HEAD)
[ -f "$STATE/INTERNAL-SITE-OPEN.json" ] && [ ! -L "$STATE/INTERNAL-SITE-OPEN.json" ] || stop 'V2 ouverte attendue.'
for path in "$APP/.env" "$STATE/RUNTIME-PREPARED.json"; do
    [ -f "$path" ] && [ ! -L "$path" ] && [ "$(stat -c %u "$path")" = 0 ] || stop 'configuration privee attendue.'
done
[ "$(stat -c %U:%G:%a "$APP/.env")" = root:cofrac2-app:640 ] \
    && [ "$(stat -c %a "$STATE/RUNTIME-PREPARED.json")" = 600 ] || stop 'permissions de configuration inattendues.'
for unit in apache2.service mysql.service cofrac2-php-fpm.service cofrac2-queue.service cofrac2-reverb.service; do
    systemctl is-active --quiet "$unit" || stop "service inactif : $unit"
done
app_cli() { runuser -u cofrac2-app -- env -i PATH="$PATH" HOME=/var/lib/cofrac2-app USER=cofrac2-app LOGNAME=cofrac2-app php8.4 -d memory_limit=768M "$@"; }
runtime() { bash "$HERE/configure-production-runtime.sh" "$1" --reload-shared-apache 8>&-; }
check_https() {
    local code
    code=$(curl --noproxy '*' --silent --show-error --max-time 25 \
        --resolve "$1:443:127.0.0.1" -o /dev/null -w '%{http_code}' "https://$1$2")
    [ "$code" = "$3" ] || [ "$code" = "${4:-}" ] || stop "controle HTTPS refuse : $1$2 HTTP $code"
}
SITES=(cofrac.geniuscontrole.com.conf cofrac.geniuscontrole.com-le-ssl.conf)
for name in "${SITES[@]}"; do
    path="/etc/apache2/sites-available/$name"
    [ -f "$path" ] && [ ! -L "$path" ] && [ "$(stat -c %u "$path")" = 0 ] \
        && [ "$(realpath "/etc/apache2/sites-enabled/$name")" = "$path" ] || stop 'vhost historique actif inattendu.'
done
if grep -RFl --exclude="${SITES[0]}" --exclude="${SITES[1]}" -- cofrac.geniuscontrole.com /etc/apache2/sites-enabled >/dev/null; then
    stop 'domaine historique mentionne dans un autre vhost actif.'
fi
apache2ctl configtest
check_https cofrac2.geniuscontrole.com /login 200
SOURCE=$(php8.4 "$HERE/production-domain.php" --runtime-info)
if [ "$ACTION" = --activate ] && [ -n "$SOURCE" ]; then
    app_cli "$HERE/production-runtime.php" --check >/dev/null
    check_https cofrac.geniuscontrole.com /login 200
    printf 'Les deux domaines utilisent deja la meme V2. Aucun changement.\n'
    exit 0
fi
if [ "$ACTION" = --activate ]; then
    HISTORY="$STATE/legacy-domain-maintenance"
    [ -f "$HISTORY/active-run" ] && [ ! -L "$HISTORY/active-run" ] || stop 'maintenance historique non tracee.'
    IFS= read -r previous < "$HISTORY/active-run"
    [[ "$previous" = "$HISTORY"/run-* ]] && [ "$(dirname "$previous")" = "$HISTORY" ] \
        && [ -d "$previous" ] && [ ! -L "$previous" ] && [ "$(stat -c %u:%a "$previous")" = 0:700 ] \
        || stop 'historique de maintenance inattendu.'
    sha256sum --status -c "$previous/active.sha256" || stop 'maintenance historique modifiee ; ne pas ecraser.'
    check_https cofrac.geniuscontrole.com /login 503
else
    [ -n "$SOURCE" ] || stop 'aucune ouverture du domaine historique a restaurer.'
    php8.4 -r '$r=json_decode(file_get_contents($argv[1]),true,32,JSON_THROW_ON_ERROR);if($r["revision"]!==$argv[2])exit(1);' \
        "$STATE/PRODUCTION-DOMAINS.json" "$REV" || stop 'une release ulterieure est installee ; retour cible a preparer.'
    cmp -s "$APP/.env" "$SOURCE/env.next" || stop 'configuration modifiee depuis la bascule ; ne pas ecraser.'
    check_https cofrac.geniuscontrole.com /login 200
fi
WORK=$(mktemp -d "$STATE/domains-$(date -u +%Y%m%dT%H%M%SZ)-XXXXXXXX")
printf 'Diagnostic et retour arriere prives : %s\n' "$WORK"
snapshot_shared() {
    systemctl show apache2.service mysql.service php7.3-fpm.service php7.4-fpm.service php8.3-fpm.service php8.4-fpm.service \
        --property=Id,ActiveState,MainPID,ExecMainStartTimestampMonotonic
    find -L /etc/apache2/sites-enabled /etc/apache2/conf-enabled /etc/apache2/mods-enabled -type f \
        ! -name cofrac2.geniuscontrole.com.conf ! -name cofrac2-runtime-modules.conf \
        ! -path "/etc/apache2/sites-enabled/${SITES[0]}" ! -path "/etc/apache2/sites-enabled/${SITES[1]}" \
        -print0 | sort -z | xargs -0 -r sha256sum
}
snapshot_shared > "$WORK/shared.before"
cp -p "$APP/.env" "$WORK/env.before"
cp -p "$STATE/RUNTIME-PREPARED.json" "$WORK/prepared.before.json"
[ ! -e "$APP/bootstrap/cache/config.php" ] || cp -p "$APP/bootstrap/cache/config.php" "$WORK/cache.before.php"
[ ! -f "$STATE/PRODUCTION-DOMAINS.json" ] || cp -p "$STATE/PRODUCTION-DOMAINS.json" "$WORK/domains.before.json"
app_cli "$HERE/production-runtime.php" --check > "$WORK/application.before.json"
if [ "$ACTION" = --activate ]; then php8.4 "$HERE/production-domain.php" --stage "$WORK" "$REV"; fi
closed=0
configuration_written=0
success=0
cleanup() {
    result=$?
    trap - EXIT
    if [ "$success" != 1 ] && [ "$closed" = 1 ]; then
        if [ "$configuration_written" = 1 ]; then
            install -o root -g cofrac2-app -m 0640 "$WORK/env.before" "$APP/.env"
            install -o root -g root -m 0600 "$WORK/prepared.before.json" "$STATE/RUNTIME-PREPARED.json"
            if [ -f "$WORK/domains.before.json" ]; then
                install -o root -g root -m 0600 "$WORK/domains.before.json" "$STATE/PRODUCTION-DOMAINS.json"
            else rm -f -- "$STATE/PRODUCTION-DOMAINS.json"; fi
            if [ -f "$WORK/cache.before.php" ]; then
                install -o cofrac2-app -g cofrac2-app -m 0600 "$WORK/cache.before.php" "$APP/bootstrap/cache/config.php"
            else rm -f -- "$APP/bootstrap/cache/config.php"; fi
        fi
        if app_cli "$HERE/production-runtime.php" --check > "$WORK/restored-check.json" 2>&1; then
            runtime --open > "$WORK/restored-open.log" 2>&1 \
                || printf 'ATTENTION : reouverture a verifier dans %s/restored-open.log\n' "$WORK" >&2
        fi
        printf 'Bascule non validee. Configuration precedente restauree, sans restauration SQL. Diagnostic : %s\n' "$WORK" >&2
    fi
    exit "$result"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
runtime --maintenance
closed=1
configuration_written=1
if [ "$ACTION" = --activate ]; then
    php8.4 "$HERE/production-domain.php" --apply "$WORK" "$REV"
else
    install -o root -g cofrac2-app -m 0640 "$SOURCE/env.before" "$APP/.env"
    install -o root -g root -m 0600 "$SOURCE/prepared.before.json" "$STATE/RUNTIME-PREPARED.json"
    rm -- "$STATE/PRODUCTION-DOMAINS.json"
fi
app_cli "$APP/artisan" config:cache --no-interaction --no-ansi > "$WORK/cache.log" 2>&1
app_cli "$HERE/production-runtime.php" --check > "$WORK/application.after.json"
runtime --open
closed=0
check_https cofrac2.geniuscontrole.com /login 200
if [ "$ACTION" = --activate ]; then
    check_https cofrac.geniuscontrole.com /login 200
    check_https cofrac.geniuscontrole.com /v2/dossiers 302
    for path in /.env /.git/config /phpmyadmin /storage/anything.php; do
        check_https cofrac.geniuscontrole.com "$path" 403 404
    done
else check_https cofrac.geniuscontrole.com /login 503; fi
snapshot_shared > "$WORK/shared.after"
cmp -s "$WORK/shared.before" "$WORK/shared.after" || stop 'autre configuration Apache ou processus partage modifie.'
success=1
if [ "$ACTION" = --activate ]; then
    printf 'https://cofrac.geniuscontrole.com et https://cofrac2.geniuscontrole.com servent la meme V2.\n'
    printf 'Meme base cofrac2production, memes documents, memes comptes. Adresse principale : cofrac.geniuscontrole.com.\n'
else printf 'Domaine historique remis en maintenance ; V2 conservee sur cofrac2. Ancienne application jamais reactivee.\n'; fi
printf 'Modes mail/SMS conserves. Aucun email de test, envoi, import ou changement DNS/certificat.\n'
printf 'Code, base, documents et processus de l ancien Coffrac inchanges.\n'
