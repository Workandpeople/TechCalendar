#!/usr/bin/env bash

# Provision only the new host. Explicit consent is required for a shared graceful reload.
set -Eeuo pipefail
umask 077
export LC_ALL=C
export PATH=/usr/sbin:/usr/bin:/sbin:/bin
stop() { printf 'STOP : %s\n' "$1" >&2; exit 1; }
if [ "${EUID:-$(id -u)}" -ne 0 ] || [ "$#" -ne 2 ] \
    || [ "${2:-}" != '--reload-shared-apache' ] || [ "$(hostname -s)" != ns365601 ]; then
    stop 'Usage : sudo bash configure-production-web.sh --enable-http-challenge|--enable-https-maintenance --reload-shared-apache'
fi
case "$1" in
    --enable-http-challenge) TEMPLATE=apache-production-challenge.conf.example ;;
    --enable-https-maintenance) TEMPLATE=apache-production-https.conf.example ;;
    *) stop 'etape inconnue.' ;;
esac

HERE=$(cd -- "$(dirname -- "$0")" && pwd)
STATE=/var/lib/cofrac2-production
DOMAIN=cofrac2.geniuscontrole.com
SITE="/etc/apache2/sites-available/$DOMAIN.conf"
ENABLED="/etc/apache2/sites-enabled/$DOMAIN.conf"
MARKER="$STATE/apache-config.sha256"
WEBROOT=/var/lib/cofrac2-acme
CERTIFICATE="/var/lib/cofrac2-letsencrypt/live/$DOMAIN/fullchain.pem"
for executable in apache2ctl systemctl curl openssl sha256sum flock stat install find sort xargs cmp; do
    command -v "$executable" >/dev/null || stop "outil manquant : $executable"
done
[ -d "$STATE" ] && [ ! -L "$STATE" ] && [ "$(stat -c %u:%a "$STATE")" = 0:700 ] \
    || stop 'repertoire prive de preparation inattendu.'
[ -f "$STATE/SCHEMA-READY.json" ] || stop 'schema V2 non valide.'
[ -f "$HERE/$TEMPLATE" ] || stop 'modele Apache absent.'
exec 9>"$STATE/web-setup.lock"
flock -n 9 || stop 'preparation web deja en cours.'
systemctl is-active --quiet apache2.service || stop 'Apache n est pas actif ; aucun demarrage automatique.'
apache2ctl configtest
modules=$(apache2ctl -M 2>/dev/null)
grep -q 'rewrite_module' <<< "$modules" || stop 'mod_rewrite absent ; aucun module active automatiquement.'
if grep -RFl --exclude="$DOMAIN.conf" -- "$DOMAIN" /etc/apache2/sites-enabled >/dev/null; then
    stop 'domaine deja mentionne dans un autre vhost actif ; examiner avant toute modification.'
fi

previous_site=0
if [ "$1" = --enable-http-challenge ]; then
    for path in "$SITE" "$ENABLED" "$MARKER"; do
        [ ! -e "$path" ] && [ ! -L "$path" ] || stop "deja present : $path"
    done
else
    [ -f "$SITE" ] && [ ! -L "$SITE" ] && [ "$(stat -c %u "$SITE")" = 0 ] \
        && [ -f "$MARKER" ] || stop 'vhost HTTP prepare absent.'
    sha256sum --status -c "$MARKER" || stop 'vhost prepare modifie ; ne pas ecraser.'
    [ -L "$ENABLED" ] && [ "$(readlink -f "$ENABLED")" = "$SITE" ] \
        || stop 'activation HTTP inattendue.'
    grep -q 'ssl_module' <<< "$modules" || stop 'mod_ssl absent ; aucun module active automatiquement.'
    [ -r "$CERTIFICATE" ] || stop 'certificat dedie absent ; executer Certbot webroot avant cette etape.'
    openssl x509 -in "$CERTIFICATE" -noout -checkhost "$DOMAIN" >/dev/null \
        || stop 'certificat pour un autre nom.'
    openssl x509 -in "$CERTIFICATE" -noout -checkend 86400 >/dev/null \
        || stop 'certificat expire ou expirant sous 24 h.'
    previous_site=1
fi

WORK=$(mktemp -d "$STATE/web-setup-$(date -u +%Y%m%dT%H%M%SZ)-XXXXXXXX")
snapshot_other_configs() {
    find -L /etc/apache2/sites-enabled /etc/apache2/conf-enabled /etc/apache2/mods-enabled \
        -type f ! -name "$DOMAIN.conf" -print0 | sort -z | xargs -0 -r sha256sum
}
snapshot_other_configs > "$WORK/other-configs.before"
pid_before=$(systemctl show apache2.service --property=MainPID --value)
[ "$pid_before" -gt 0 ] || stop 'PID Apache inattendu.'
if [ "$previous_site" = 1 ]; then cp -p -- "$SITE" "$WORK/site.before"; fi
site_written=0
link_created=0
reload_attempted=0
success=0
probe=
next_site=
cleanup() {
    if [ -n "$probe" ]; then rm -f -- "$probe"; fi
    if [ -n "$next_site" ]; then rm -f -- "$next_site"; fi
    if [ "$success" != 1 ] && [ "$site_written" = 1 ]; then
        if [ "$previous_site" = 1 ]; then
            install -o root -g root -m 0644 "$WORK/site.before" "$SITE"
        else
            rm -f -- "$SITE"
        fi
        if [ "$link_created" = 1 ]; then rm -f -- "$ENABLED"; fi
        if [ "$reload_attempted" = 1 ]; then
            if ! { apache2ctl configtest && systemctl reload apache2.service; }; then
                printf 'ATTENTION : retour de la seule configuration V2 a verifier manuellement.\n' >&2
            fi
        fi
        printf 'Etape non validee ; configuration du seul vhost V2 restauree. Diagnostic : %s\n' "$WORK" >&2
    fi
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

for directory in "$WEBROOT" "$WEBROOT/.well-known" "$WEBROOT/.well-known/acme-challenge"; do
    if [ -e "$directory" ] || [ -L "$directory" ]; then
        [ -d "$directory" ] && [ ! -L "$directory" ] && [ "$(stat -c %u "$directory")" = 0 ] \
            || stop "repertoire webroot inattendu : $directory"
    fi
    install -d -o root -g root -m 0755 "$directory"
done
for directory in /var/lib/cofrac2-letsencrypt /var/lib/cofrac2-certbot-work /var/log/cofrac2-certbot; do
    if [ -e "$directory" ] || [ -L "$directory" ]; then
        [ -d "$directory" ] && [ ! -L "$directory" ] && [ "$(stat -c %u "$directory")" = 0 ] \
            || stop "repertoire Certbot inattendu : $directory"
    fi
    install -d -o root -g root -m 0700 "$directory"
done
next_site=$(mktemp /etc/apache2/sites-available/.cofrac2-XXXXXXXX)
install -o root -g root -m 0644 "$HERE/$TEMPLATE" "$next_site"
site_written=1
mv -f -- "$next_site" "$SITE"
next_site=
if [ "$previous_site" = 0 ]; then
    ln -s -- "$SITE" "$ENABLED"
    link_created=1
fi
apache2ctl configtest
printf 'Rechargement gracieux du service Apache partage, sans restart...\n'
reload_attempted=1
systemctl reload apache2.service
[ "$(systemctl show apache2.service --property=MainPID --value)" = "$pid_before" ] \
    || stop 'PID Apache different apres rechargement ; examiner les services.'

token="cofrac2-check-$(openssl rand -hex 16)"
probe="$WEBROOT/.well-known/acme-challenge/$token"
printf '%s' "$token" > "$probe"
chmod 0644 "$probe"
[ "$(curl --noproxy '*' --fail --silent --show-error --max-time 15 \
    --resolve "$DOMAIN:80:127.0.0.1" "http://$DOMAIN/.well-known/acme-challenge/$token")" = "$token" ] \
    || stop 'challenge HTTP local inaccessible.'
if [ "$1" = --enable-http-challenge ]; then
    protocol=http
    port=80
else
    protocol=https
    port=443
fi
code=$(curl --noproxy '*' --silent --show-error --max-time 15 \
    --resolve "$DOMAIN:$port:127.0.0.1" -o "$WORK/page.body" -w '%{http_code}' "$protocol://$DOMAIN/")
[ "$code" = 503 ] && grep -q 'Cofrac 2 est en preparation' "$WORK/page.body" \
    || stop 'page d attente V2 inattendue.'
snapshot_other_configs > "$WORK/other-configs.after"
cmp -s "$WORK/other-configs.before" "$WORK/other-configs.after" \
    || stop 'une configuration Apache autre que le vhost V2 a change ; examiner le diagnostic.'
sha256sum "$SITE" > "$MARKER"
success=1
printf 'Vhost V2 valide : %s://%s, page d attente HTTP 503.\n' "$protocol" "$DOMAIN"
printf 'Autres configurations actives inchangees. Aucun PHP, SQL, worker ou Reverb demarre.\n'
printf 'Controle local uniquement ; verifier aussi l acces depuis l exterieur.\n'
