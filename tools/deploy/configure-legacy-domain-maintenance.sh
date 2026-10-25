#!/usr/bin/env bash

# Change only the two known legacy vhosts; never bootstrap or stop either application.
set -Eeuo pipefail
umask 077
export LC_ALL=C
export PATH=/usr/sbin:/usr/bin:/sbin:/bin
stop() { printf 'STOP : %s\n' "$1" >&2; exit 1; }
if [ "${EUID:-$(id -u)}" -ne 0 ] || [ "$#" -ne 2 ] || [ "$(hostname -s)" != ns365601 ]; then
    stop 'Usage : --activate|--rollback --reload-shared-apache, ou --update-page --no-reload'
fi
case "$1:$2" in
    --activate:--reload-shared-apache|--rollback:--reload-shared-apache|--update-page:--no-reload) ;;
    *) stop 'action ou consentement de rechargement inattendu.' ;;
esac
HERE=$(cd -- "$(dirname -- "$0")" && pwd)
STATE=/var/lib/cofrac2-production
HISTORY="$STATE/legacy-domain-maintenance"
WEBROOT=/var/www/cofrac-legacy-maintenance
DOMAIN=cofrac.geniuscontrole.com
SITES=(cofrac.geniuscontrole.com.conf cofrac.geniuscontrole.com-le-ssl.conf)
for executable in php8.4 apache2ctl systemctl curl sha256sum flock stat install find sort xargs cmp mktemp; do
    command -v "$executable" >/dev/null || stop "outil manquant : $executable"
done
[ -d "$STATE" ] && [ ! -L "$STATE" ] && [ "$(stat -c %u:%a "$STATE")" = 0:700 ] \
    || stop 'repertoire prive de preparation inattendu.'
if [ -e "$HISTORY" ] || [ -L "$HISTORY" ]; then
    [ -d "$HISTORY" ] && [ ! -L "$HISTORY" ] && [ "$(stat -c %u:%a "$HISTORY")" = 0:700 ] \
        || stop 'historique de maintenance inattendu.'
fi
install -d -o root -g root -m 0700 "$HISTORY"
exec 9>"$HISTORY/maintenance.lock"
flock -n 9 || stop 'operation de maintenance deja en cours.'
systemctl is-active --quiet apache2.service || stop 'Apache inactif ; aucun demarrage automatique.'
apache2ctl configtest
modules=$(apache2ctl -M 2>/dev/null)
grep -q 'rewrite_module' <<< "$modules" || stop 'mod_rewrite absent ; aucun module active automatiquement.'
grep -q 'ssl_module' <<< "$modules" || stop 'mod_ssl absent ; aucun module active automatiquement.'
grep -q 'headers_module' <<< "$modules" || stop 'mod_headers absent ; aucun module active automatiquement.'
for name in "${SITES[@]}"; do
    site="/etc/apache2/sites-available/$name"
    enabled="/etc/apache2/sites-enabled/$name"
    [ -f "$site" ] && [ ! -L "$site" ] && [ "$(stat -c %u "$site")" = 0 ] \
        || stop "configuration originale inattendue : $site"
    [ -L "$enabled" ] && [ "$(readlink -f "$enabled")" = "$site" ] \
        || stop "activation originale inattendue : $enabled"
done
if grep -RFl --exclude="${SITES[0]}" --exclude="${SITES[1]}" \
    -- "$DOMAIN" /etc/apache2/sites-enabled >/dev/null; then
    stop 'ancien domaine mentionne dans un autre vhost actif ; verifier avant de modifier.'
fi

snapshot_other_configs() {
    find -L /etc/apache2/sites-enabled /etc/apache2/conf-enabled /etc/apache2/mods-enabled \
        -type f ! -path "/etc/apache2/sites-enabled/${SITES[0]}" \
        ! -path "/etc/apache2/sites-enabled/${SITES[1]}" -print0 | sort -z | xargs -0 -r sha256sum
}
snapshot_services() {
    systemctl show apache2.service mysql.service php7.3-fpm.service php7.4-fpm.service \
        php8.3-fpm.service php8.4-fpm.service cofrac2-php-fpm.service \
        cofrac2-queue.service cofrac2-reverb.service \
        -p Id -p MainPID -p ActiveState -p ExecMainStartTimestampMonotonic
}
check_v2() {
    [ "$(curl --noproxy '*' --silent --show-error --max-time 20 \
        --resolve cofrac2.geniuscontrole.com:443:127.0.0.1 \
        -o /dev/null -w '%{http_code}' https://cofrac2.geniuscontrole.com/login)" = 200 ] \
        || stop 'connexion HTTPS V2 indisponible ; ancien domaine non valide pour cette operation.'
}
check_v2

WORK=$(mktemp -d "$HISTORY/run-$(date -u +%Y%m%dT%H%M%SZ)-XXXXXXXX")
snapshot_other_configs > "$WORK/other-configs.before"
snapshot_services > "$WORK/services.before"
pid_before=$(systemctl show apache2.service --property=MainPID --value)
[ "$pid_before" -gt 0 ] || stop 'PID Apache inattendu.'
install -d -m 0700 "$WORK/before" "$WORK/next"
for name in "${SITES[@]}"; do
    cp -p -- "/etc/apache2/sites-available/$name" "$WORK/before/$name"
done

ACTIVE_RUN=
if [ "$1" != --activate ] || [ -e "$HISTORY/active-run" ]; then
    [ -f "$HISTORY/active-run" ] && [ ! -L "$HISTORY/active-run" ] \
        || stop 'aucune activation precedente a restaurer.'
    IFS= read -r ACTIVE_RUN < "$HISTORY/active-run"
    [[ "$ACTIVE_RUN" = "$HISTORY"/run-* ]] && [ "$(dirname -- "$ACTIVE_RUN")" = "$HISTORY" ] \
        && [ -d "$ACTIVE_RUN" ] && [ ! -L "$ACTIVE_RUN" ] \
        && [ "$(stat -c %u:%a "$ACTIVE_RUN")" = 0:700 ] \
        || stop 'marqueur de retour arriere inattendu.'
    sha256sum --status -c "$ACTIVE_RUN/active.sha256" \
        || stop 'vhosts de maintenance modifies depuis leur activation ; ne pas ecraser.'
    (cd "$ACTIVE_RUN/before" && sha256sum --status -c SHA256SUMS) \
        || stop 'sauvegarde originale modifiee ; aucun retour arriere automatique.'
    if [ "$1" = --activate ]; then
        printf 'Maintenance deja active. Retour arriere : sudo bash %s/rollback.sh\n' "$HISTORY"
        exit 0
    fi
    if [ "$1" = --update-page ]; then
        [ -d "$WEBROOT" ] && [ ! -L "$WEBROOT" ] && [ "$(stat -c %u:%a "$WEBROOT")" = 0:755 ] \
            && [ -f "$WEBROOT/index.html" ] && [ ! -L "$WEBROOT/index.html" ] \
            && [ "$(stat -c %u:%a "$WEBROOT/index.html")" = 0:644 ] \
            && [ "$(find "$WEBROOT" -mindepth 1 -maxdepth 1 | wc -l)" -eq 1 ] \
            || stop 'page de maintenance active inattendue.'
        [ -f "$HERE/legacy-domain-maintenance.html" ] && [ ! -L "$HERE/legacy-domain-maintenance.html" ] \
            || stop 'nouvelle page absente ou indirecte.'
        install -o root -g root -m 0600 "$HERE/legacy-domain-maintenance.html" "$WORK/page.next"
        grep -q 'data-cofrac-maintenance="true"' "$WORK/page.next" \
            && grep -q '<h1>Maintenance en cours</h1>' "$WORK/page.next" \
            || stop 'nouvelle page de maintenance inattendue.'
        if cmp -s "$WEBROOT/index.html" "$WORK/page.next"; then
            printf 'Page deja a jour. Aucun rechargement ou service modifie.\n'
            exit 0
        fi
        install -o root -g root -m 0600 "$WEBROOT/index.html" "$WORK/page.before"
        cp -p "$ACTIVE_RUN/active.sha256" "$WORK/active.before.sha256"
        {
            for name in "${SITES[@]}"; do sha256sum "/etc/apache2/sites-available/$name"; done
            printf '%s  %s\n' "$(sha256sum "$WORK/page.next" | cut -d ' ' -f 1)" "$WEBROOT/index.html"
        } > "$WORK/active.next.sha256"
        page_written=0
        page_success=0
        page_temporary=
        cleanup_page() {
            result=$?
            trap - EXIT
            [ -z "$page_temporary" ] || rm -f -- "$page_temporary"
            if [ "$page_success" != 1 ] && [ "$page_written" = 1 ]; then
                if cmp -s "$WEBROOT/index.html" "$WORK/page.next" \
                    && { cmp -s "$ACTIVE_RUN/active.sha256" "$WORK/active.next.sha256" \
                        || cmp -s "$ACTIVE_RUN/active.sha256" "$WORK/active.before.sha256"; }; then
                    install -o root -g root -m 0644 "$WORK/page.before" "$WEBROOT/index.html"
                    install -o root -g root -m 0600 "$WORK/active.before.sha256" "$ACTIVE_RUN/active.sha256"
                    printf 'Page precedente et empreinte restaurees, maintenance conservee.\n' >&2
                else
                    printf 'ATTENTION : modification concurrente ; ne pas ecraser. Diagnostic prive : %s\n' "$WORK" >&2
                fi
            fi
            exit "$result"
        }
        trap cleanup_page EXIT
        trap 'exit 130' INT
        trap 'exit 143' TERM
        sha256sum --status -c "$ACTIVE_RUN/active.sha256" || stop 'maintenance modifiee pendant la preparation.'
        page_temporary=$(mktemp "$WEBROOT/.page-XXXXXXXX")
        install -o root -g root -m 0644 "$WORK/page.next" "$page_temporary"
        page_written=1
        mv -f -- "$page_temporary" "$WEBROOT/index.html"
        page_temporary=
        page_temporary=$(mktemp "$ACTIVE_RUN/.active-XXXXXXXX")
        install -o root -g root -m 0600 "$WORK/active.next.sha256" "$page_temporary"
        mv -f -- "$page_temporary" "$ACTIVE_RUN/active.sha256"
        page_temporary=
        code=$(curl --noproxy '*' --silent --show-error --max-time 20 \
            --resolve "$DOMAIN:443:127.0.0.1" -o "$WORK/page.response" -w '%{http_code}' "https://$DOMAIN/")
        [ "$code" = 503 ] && cmp -s "$WORK/page.next" "$WORK/page.response" || stop 'nouvelle page HTTPS non validee.'
        check_v2
        sha256sum --status -c "$ACTIVE_RUN/active.sha256" || stop 'empreintes de maintenance non conformes.'
        snapshot_other_configs > "$WORK/other-configs.after"
        snapshot_services > "$WORK/services.after"
        cmp -s "$WORK/other-configs.before" "$WORK/other-configs.after" \
            && cmp -s "$WORK/services.before" "$WORK/services.after" || stop 'configuration ou service modifie pendant la mise a jour.'
        page_success=1
        printf 'Page de maintenance mise a jour. Aucun lien ou redirection ajoute. Retour arriere conserve.\n'
        printf 'Aucune configuration Apache ou V2 modifiee ; aucun rechargement. Diagnostic prive : %s\n' "$WORK"
        exit 0
    fi
    for name in "${SITES[@]}"; do cp -p -- "$ACTIVE_RUN/before/$name" "$WORK/next/$name"; done
else
    [ -f "$STATE/INTERNAL-SITE-OPEN.json" ] || stop 'ouverture interne V2 non validee.'
    for file in legacy-domain-maintenance.php legacy-domain-maintenance.html configure-legacy-domain-maintenance.sh; do
        [ -f "$HERE/$file" ] && [ ! -L "$HERE/$file" ] || stop "paquet incomplet : $file"
        install -o root -g root -m 0600 "$HERE/$file" "$WORK/$file"
    done
    (cd "$WORK/before" && sha256sum "${SITES[@]}" > SHA256SUMS)
    php8.4 "$WORK/legacy-domain-maintenance.php" "$WORK/before/${SITES[0]}" 80 > "$WORK/next/${SITES[0]}"
    php8.4 "$WORK/legacy-domain-maintenance.php" "$WORK/before/${SITES[1]}" 443 > "$WORK/next/${SITES[1]}"
    if [ -e "$WEBROOT" ] || [ -L "$WEBROOT" ]; then
        [ -d "$WEBROOT" ] && [ ! -L "$WEBROOT" ] && [ "$(stat -c %u:%a "$WEBROOT")" = 0:755 ] \
            && [ -f "$WEBROOT/index.html" ] && [ ! -L "$WEBROOT/index.html" ] \
            && [ "$(stat -c %u:%a "$WEBROOT/index.html")" = 0:644 ] \
            && [ "$(find "$WEBROOT" -mindepth 1 -maxdepth 1 | wc -l)" -eq 1 ] \
            && cmp -s "$WEBROOT/index.html" "$WORK/legacy-domain-maintenance.html" \
            || stop 'webroot de maintenance deja present et different ; ne pas ecraser.'
    else
        install -d -o root -g root -m 0755 "$WEBROOT"
        install -o root -g root -m 0644 "$WORK/legacy-domain-maintenance.html" "$WEBROOT/index.html"
    fi
fi

written=()
reload_attempted=0
success=0
temporary=
cleanup() {
    result=$?
    [ -z "$temporary" ] || rm -f -- "$temporary"
    if [ "$success" != 1 ] && [ "${#written[@]}" -gt 0 ]; then
        restored=1
        for name in "${written[@]}"; do
            if cmp -s "/etc/apache2/sites-available/$name" "$WORK/next/$name"; then
                install -o root -g root -m 0644 "$WORK/before/$name" "/etc/apache2/sites-available/$name"
            else
                printf 'ATTENTION : modification concurrente de %s ; restauration manuelle requise.\n' "$name" >&2
                restored=0
            fi
        done
        if [ "$reload_attempted" = 1 ] && [ "$restored" = 1 ]; then
            if ! { apache2ctl configtest && systemctl reload apache2.service; }; then
                printf 'ATTENTION : rechargement de retour arriere a verifier manuellement.\n' >&2
            fi
        fi
        printf 'Operation non validee. Retour aux deux configurations precedentes tente. Diagnostic prive : %s\n' "$WORK" >&2
    fi
    exit "$result"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
for name in "${SITES[@]}"; do
    cmp -s "$WORK/before/$name" "/etc/apache2/sites-available/$name" \
        || stop 'configuration modifiee pendant la preparation ; ne pas ecraser.'
    temporary=$(mktemp /etc/apache2/sites-available/.cofrac-legacy-XXXXXXXX)
    install -o root -g root -m 0644 "$WORK/next/$name" "$temporary"
    written+=("$name")
    mv -f -- "$temporary" "/etc/apache2/sites-available/$name"
    temporary=
done
apache2ctl configtest
printf 'Rechargement gracieux Apache ; aucun service redemarre...\n'
reload_attempted=1
systemctl reload apache2.service

if [ "$1" = --activate ]; then
    for port in 80 443; do
        protocol=http; [ "$port" != 443 ] || protocol=https
        for host in "$DOMAIN" "www.$DOMAIN"; do
            for path in / /login /dossier/list/new /index.php /index.html /.env /phpmyadmin /javascript; do
                ready=0
                for attempt in {1..10}; do
                    code=$(curl --noproxy '*' --silent --show-error --max-time 15 \
                        --resolve "$DOMAIN:$port:127.0.0.1" -H "Host: $host" \
                        -D "$WORK/response.headers" -o "$WORK/response.body" -w '%{http_code}' \
                        "$protocol://$DOMAIN$path")
                    if [ "$code" = 503 ] && grep -q 'data-cofrac-maintenance="true"' "$WORK/response.body" \
                        && grep -qi '^Cache-Control:.*no-store' "$WORK/response.headers"; then
                        ready=1; break
                    fi
                    sleep 1
                done
                [ "$ready" = 1 ] || stop 'page de maintenance HTTP/HTTPS ou chemin profond inattendu.'
            done
        done
    done
fi
check_v2
[ "$(systemctl show apache2.service --property=MainPID --value)" = "$pid_before" ] \
    || stop 'PID Apache modifie ; examiner les services.'
snapshot_other_configs > "$WORK/other-configs.after"
snapshot_services > "$WORK/services.after"
cmp -s "$WORK/other-configs.before" "$WORK/other-configs.after" \
    || stop 'une autre configuration Apache a change pendant cette operation.'
cmp -s "$WORK/services.before" "$WORK/services.after" \
    || stop 'etat ou PID d un service modifie pendant cette operation.'
if [ "$1" = --activate ]; then
    for name in "${SITES[@]}"; do sha256sum "/etc/apache2/sites-available/$name"; done > "$WORK/active.sha256"
    sha256sum "$WEBROOT/index.html" >> "$WORK/active.sha256"
    printf '#!/usr/bin/env bash\nset -Eeuo pipefail\nexec bash %q --rollback --reload-shared-apache\n' \
        "$WORK/configure-legacy-domain-maintenance.sh" > "$HISTORY/rollback.sh"
    chmod 0700 "$HISTORY/rollback.sh"
    printf '%s\n' "$WORK" > "$HISTORY/active-run"
    success=1
    printf 'Ancien domaine en maintenance : https://%s (HTTP 503).\n' "$DOMAIN"
    printf 'Page de maintenance affichee. Aucun email ou SMS active.\n'
    printf 'Retour arriere : sudo bash %s/rollback.sh\n' "$HISTORY"
else
    mv -- "$HISTORY/active-run" "$WORK/restored-activation"
    success=1
    printf 'Deux vhosts originaux restaures. Cofrac 2 inchange.\n'
fi
printf 'Code, base, documents et processus Coffrac originaux non modifies.\n'
printf 'Autres configurations Apache et services verifies inchanges. Diagnostic prive : %s\n' "$WORK"
