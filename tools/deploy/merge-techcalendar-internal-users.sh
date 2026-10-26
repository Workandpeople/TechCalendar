#!/usr/bin/env bash

# Dedicated V2 SQL account only. No service changes, Laravel bootstrap or original Coffrac access.
set +x
set -Eeuo pipefail
umask 077
ulimit -c 0
export LC_ALL=C
export PATH=/usr/sbin:/usr/bin:/sbin:/bin
stop() { printf 'STOP : %s\n' "$1" >&2; exit 1; }
[[ "${EUID:-$(id -u)}" = 0 && "$(hostname -s)" = ns365601 && "$#" = 2 && "$1" = --apply && "$2" =~ ^[a-f0-9]{64}$ ]] \
    || stop 'Usage root sur ns365601 : --apply MANIFEST_SHA256'
HERE=$(cd -- "$(dirname -- "$0")" && pwd -P)
STATE=/var/lib/cofrac2-production
[[ "$HERE" =~ ^/home/ubuntu/cofrac2-internal-users-merge-[A-Za-z0-9]{8}$ ]] || stop 'paquet prive sous /home/ubuntu attendu.'
[ -d "$STATE" ] && [ ! -L "$STATE" ] && [ "$(stat -c %u:%a "$STATE")" = 0:700 ] || stop 'etat prive V2 inattendu.'
for command in php8.4 sha256sum flock install gzip mysqldump mktemp; do
    command -v "$command" >/dev/null || stop "outil manquant : $command"
done
[ -f "$HERE/SHA256SUMS" ] && [ ! -L "$HERE/SHA256SUMS" ] || stop 'manifeste absent.'
[ "$(sha256sum "$HERE/SHA256SUMS" | cut -d' ' -f1)" = "$2" ] || stop 'empreinte du paquet differente.'
FILES=(merge-techcalendar-internal-users.sh merge-techcalendar-internal-users.php lib/TechCalendarInternalUsersPlan.php \
    lib/TechCalendarInternalUsersMerge.php apply-internal-users-from-mac.sh references.json.gz approved-plan.json.gz DECISIONS.json README.txt)
for file in "${FILES[@]}"; do
    [ -f "$HERE/$file" ] && [ ! -L "$HERE/$file" ] || stop 'fichier absent ou symbolique.'
done
[ -d "$HERE/lib" ] && [ ! -L "$HERE/lib" ] || stop 'bibliotheque symbolique refusee.'
(cd "$HERE" && sha256sum -c SHA256SUMS)
exec 9>"$STATE/runtime-operation.lock"
flock -n 9 || stop 'autre operation V2 en cours.'
KEY=$(sha256sum "$HERE/DECISIONS.json" | cut -d' ' -f1)
RECEIPTS="$STATE/internal-users-merge-receipts"
if [ ! -e "$RECEIPTS" ] && [ ! -L "$RECEIPTS" ]; then install -d -o root -g root -m 0700 "$RECEIPTS"; fi
[ -d "$RECEIPTS" ] && [ ! -L "$RECEIPTS" ] && [ "$(stat -c %u:%a "$RECEIPTS")" = 0:700 ] || stop 'journal de fusion non prive.'
if [ -e "$RECEIPTS/$KEY.json" ] || [ -L "$RECEIPTS/$KEY.json" ]; then
    [ -f "$RECEIPTS/$KEY.json" ] && [ ! -L "$RECEIPTS/$KEY.json" ] && [ "$(stat -c %u:%a "$RECEIPTS/$KEY.json")" = 0:600 ] || stop 'recu de fusion non protege.'
    php8.4 -r '$r=json_decode(file_get_contents($argv[1]),true,64,JSON_THROW_ON_ERROR);if(($r["format"]??null)!=="cofrac2.internal-users-merge"||($r["committed"]??null)!==true||($r["policy_sha256"]??null)!==$argv[2])exit(1);' \
        "$RECEIPTS/$KEY.json" "$KEY" || stop 'recu de fusion invalide.'
    printf 'Cette fusion a deja ete appliquee. Aucun nouveau changement, aucun compte duplique.\n'
    exit 0
fi
RUN=$(mktemp -d "$STATE/internal-users-merge-$(date -u +%Y%m%dT%H%M%SZ)-XXXXXXXX")
cleanup() {
    status=$?
    trap - EXIT
    rm -f -- "$RUN/mysql.cnf"
    if [ "$status" != 0 ]; then
        printf 'Operation non validee. Ne pas restaurer ou reinitialiser automatiquement. Diagnostic prive : %s\n' "$RUN" >&2
    fi
    exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
install -d -m 0700 "$RUN/inputs" "$RUN/inputs/lib"
for file in "${FILES[@]}" SHA256SUMS; do install -o root -g root -m 0600 "$HERE/$file" "$RUN/inputs/$file"; done
[ "$(sha256sum "$RUN/inputs/SHA256SUMS" | cut -d' ' -f1)" = "$2" ] || stop 'manifeste change pendant la copie.'
[ "$(sha256sum "$RUN/inputs/DECISIONS.json" | cut -d' ' -f1)" = "$KEY" ] || stop 'decisions changees pendant la copie.'
(cd "$RUN/inputs" && sha256sum -c SHA256SUMS)
printf '=== Controle sans ecriture SQL ===\n'
php8.4 -d memory_limit=512M "$RUN/inputs/merge-techcalendar-internal-users.php" --check "$RUN/inputs" "$RUN/preflight.json"
printf '\nDecisions confirmees : statuts du CT repris, comptes de test conserves desactives, mots de passe Cofrac2 conserves.\n'
printf 'Les habilitations anciennes sans correspondance ne seront pas accordees ; elles restent dans le journal prive.\n'
printf 'Le compteur active_legacy_habilitation_warnings indique les comptes actifs dont une ancienne habilitation restera a revalider.\n'
printf 'Les codes primaires invalides des comptes inactifs sont retires, sans changer leurs secteurs explicites.\n'
printf 'Nouveaux comptes : mot de passe aleatoire inconnu, aucun email envoye ; a definir via la gestion des utilisateurs.\n'
[ -r /dev/tty ] && [ -w /dev/tty ] || stop 'terminal interactif requis.'
printf 'Taper FUSIONNER pour sauvegarder puis appliquer, ou Ctrl-C pour annuler : ' >/dev/tty
IFS= read -r CONFIRM </dev/tty
[ "$CONFIRM" = FUSIONNER ] || stop 'fusion non confirmee, aucune ecriture SQL.'
php8.4 -r 'if(disk_free_space($argv[1])<5*1024**3){fwrite(STDERR,"Marge disque insuffisante pour la sauvegarde.\n");exit(1);}' "$STATE"
printf '\nSauvegarde de la seule base Cofrac2 avec son compte SQL limite...\n'
mysqldump --defaults-file="$RUN/mysql.cnf" --single-transaction --skip-lock-tables --no-tablespaces \
    --set-gtid-purged=OFF --hex-blob cofrac2production 2>"$RUN/backup.stderr" | gzip -1 > "$RUN/database-before.sql.gz"
gzip -t "$RUN/database-before.sql.gz"
test -s "$RUN/database-before.sql.gz"
php8.4 -r '$h=fopen($argv[1]."/backup.json","x");fwrite($h,json_encode(["sha256"=>hash_file("sha256",$argv[1]."/database-before.sql.gz"),"policy_sha256"=>hash_file("sha256",$argv[1]."/inputs/DECISIONS.json")],JSON_THROW_ON_ERROR));fclose($h);' "$RUN"
rm -- "$RUN/mysql.cnf"
printf 'Application transactionnelle, puis verification des mots de passe et des comptes hors perimetre...\n'
php8.4 -d memory_limit=512M "$RUN/inputs/merge-techcalendar-internal-users.php" --apply "$RUN/inputs" "$RUN/report.json"
install -o root -g root -m 0600 "$RUN/report.json" "$RECEIPTS/$KEY.json"
(cd "$RUN" && sha256sum database-before.sql.gz backup.json preflight.json report.json > SHA256SUMS)
printf 'Fusion appliquee et verifiee. Aucun service redemarre, aucune invitation ni SMS envoye.\n'
printf 'Sauvegarde et rapport prives : %s\n' "$RUN"
