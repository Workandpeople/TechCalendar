#!/usr/bin/env bash

set -Eeuo pipefail
umask 077
export PATH=/usr/sbin:/usr/bin:/sbin:/bin
stop() { printf 'STOP : %s\n' "$1" >&2; exit 1; }
if [ "${EUID:-$(id -u)}" -ne 0 ] || [ "$#" -ne 1 ] \
    || [ "${1:-}" != '--enable-dedicated-renewal' ] || [ "$(hostname -s)" != ns365601 ]; then
    stop 'Usage sur ns365601 : sudo bash enable-production-certificate-renewal.sh --enable-dedicated-renewal'
fi
HERE=$(cd -- "$(dirname -- "$0")" && pwd)
STATE=/var/lib/cofrac2-production
[ -d "$STATE" ] && [ ! -L "$STATE" ] && [ "$(stat -c %u:%a "$STATE")" = 0:700 ] \
    || stop 'repertoire prive de preparation inattendu.'
exec 9>"$STATE/web-setup.lock"
flock -n 9 || stop 'preparation web deja en cours.'
[ -f "$STATE/apache-config.sha256" ] && sha256sum --status -c "$STATE/apache-config.sha256" \
    || stop 'vhost V2 non valide ou modifie.'
grep -q 'SSLEngine on' /etc/apache2/sites-available/cofrac2.geniuscontrole.com.conf \
    || stop 'vhost HTTPS non active.'
[ -f /var/lib/cofrac2-letsencrypt/renewal/cofrac2.geniuscontrole.com.conf ] \
    || stop 'configuration de renouvellement dediee absente.'
[ -x /usr/bin/certbot ] || stop 'Certbot attendu absent.'
apache2ctl configtest
systemctl is-active --quiet apache2.service || stop 'Apache inactif.'
TARGETS=(/usr/local/sbin/cofrac2-renew-certificate \
    /etc/systemd/system/cofrac2-certificate-renewal.service \
    /etc/systemd/system/cofrac2-certificate-renewal.timer)
for path in "${TARGETS[@]}"; do
    [ ! -e "$path" ] && [ ! -L "$path" ] || stop "deja present : $path"
done
success=0
created=()
cleanup() {
    if [ "$success" != 1 ]; then
        systemctl disable --now cofrac2-certificate-renewal.timer >/dev/null 2>&1 || true
        for path in "${created[@]}"; do rm -f -- "$path"; done
        systemctl daemon-reload
        printf 'Renouvellement dedie non valide ; ses seuls fichiers ont ete retires.\n' >&2
    fi
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
for index in 0 1 2; do
    case "$index" in
        0) source=cofrac2-renew-certificate.sh; mode=0755 ;;
        1) source=cofrac2-certificate-renewal.service.example; mode=0644 ;;
        2) source=cofrac2-certificate-renewal.timer.example; mode=0644 ;;
    esac
    created+=("${TARGETS[$index]}")
    install -o root -g root -m "$mode" "$HERE/$source" "${TARGETS[$index]}"
done
systemd-analyze verify "${TARGETS[1]}" "${TARGETS[2]}"
systemctl daemon-reload
systemctl enable --now cofrac2-certificate-renewal.timer
systemctl is-active --quiet cofrac2-certificate-renewal.timer
success=1
printf 'Renouvellement V2 dedie actif. Aucun certificat existant ni timer Certbot global modifie.\n'
