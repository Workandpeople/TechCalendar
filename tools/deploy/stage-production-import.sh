#!/usr/bin/env bash

# Only the isolated V2 target. No source SQL, original application changes or service reloads.
set +x
set -Eeuo pipefail
umask 077
ulimit -c 0
export LC_ALL=C
export PATH=/usr/sbin:/usr/bin:/sbin:/bin
stop() { printf 'STOP : %s\n' "$1" >&2; exit 1; }
[[ "${EUID:-$(id -u)}" = 0 && "$(hostname -s)" = ns365601 && "$#" = 3 ]] \
    || stop 'Usage root sur ns365601 : --check|--import-staging PACKAGE_DIRECTORY MANIFEST_SHA256'
ACTION=$1
[[ "$ACTION" = --check || "$ACTION" = --import-staging ]] || stop 'action inconnue.'
[[ "$3" =~ ^[a-f0-9]{64}$ ]] || stop 'empreinte attendue invalide.'
APP=/var/www/cofrac2
STATE=/var/lib/cofrac2-production
RUNTIME=cofrac2-app
HERE=$(cd -- "$(dirname -- "$0")" && pwd -P)
[ "$HERE" = "$APP/tools/deploy" ] && [ "$(realpath "$APP")" = "$APP" ] || stop 'checkout V2 attendu.'
[ -d "$STATE" ] && [ ! -L "$STATE" ] && [ "$(stat -c %u:%a "$STATE")" = 0:700 ] || stop 'etat prive inattendu.'
[ -d "$2" ] && [ ! -L "$2" ] && [ "$(realpath "$2")" = "$2" ] || stop 'paquet absent ou symbolique.'
for executable in php8.4 runuser flock find install cp stat sha256sum mysqldump gzip git; do
    command -v "$executable" >/dev/null || stop "outil manquant : $executable"
done
exec 9>"$STATE/native-import.lock"
flock -n 9 || stop 'preparation V2 deja en cours.'
[ ! -e "$STATE/STAGING-IMPORTED.json" ] && [ ! -L "$STATE/STAGING-IMPORTED.json" ] || stop 'preparation deja importee, aucune reinitialisation automatique.'
php8.4 -d memory_limit=768M "$HERE/stage-production-import.php" --check "$2" "$3"
if [ "$ACTION" = --check ]; then
    printf 'Controle termine. Aucun import, migration, changement de droits fichier ou service.\n'
    exit 0
fi

[ -r /dev/tty ] && [ -w /dev/tty ] || stop 'terminal interactif requis pour le seul super-admin.'
[ "$(getent passwd "$RUNTIME" | cut -d: -f6,7)" = /var/lib/cofrac2-app:/usr/sbin/nologin ] || stop 'runtime dedie inattendu.'
RUN=$(mktemp -d "$STATE/native-import-$(date -u +%Y%m%dT%H%M%SZ)-XXXXXXXX")
PARENT=/srv/cofrac2-staging-imports
if [ ! -e "$PARENT" ] && [ ! -L "$PARENT" ]; then install -d -o root -g "$RUNTIME" -m 0710 "$PARENT"; fi
[ -d "$PARENT" ] && [ ! -L "$PARENT" ] && [ "$(realpath "$PARENT")" = "$PARENT" ] \
    && [ "$(stat -c %U:%G:%a "$PARENT")" = root:cofrac2-app:710 ] || stop 'parent des entrees protegees inattendu.'
INPUT_RUN=$(mktemp -d "$PARENT/import-XXXXXXXX")
cleanup() {
    result=$?
    trap - EXIT
    rm -f -- "$INPUT_RUN/admin.json" "$RUN/mysql.cnf"
    chmod 0700 "$INPUT_RUN"
    if [ "$result" -ne 0 ]; then
        printf 'Preparation interrompue. Diagnostic prive : %s. Aucun effacement automatique de base.\n' "$RUN" >&2
    fi
    exit "$result"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
chown root:"$RUNTIME" "$INPUT_RUN"
chmod 0750 "$INPUT_RUN"
install -d -o root -g root -m 0700 "$INPUT_RUN/inputs"
cp -a --no-preserve=ownership "$2/." "$INPUT_RUN/inputs/"
[ -z "$(find "$INPUT_RUN/inputs" ! -type d ! -type f -print -quit)" ] || stop 'entree non reguliere dans la copie.'
find "$INPUT_RUN/inputs" -type d -exec chown root:"$RUNTIME" {} + -exec chmod 0750 {} +
find "$INPUT_RUN/inputs" -type f -exec chown root:"$RUNTIME" {} + -exec chmod 0440 {} +
php8.4 -d memory_limit=768M "$HERE/stage-production-import.php" --check "$INPUT_RUN/inputs" "$3" "$RUN/mysql.cnf" > "$RUN/preflight.json"

printf 'Email du seul super-admin V2 (distinct des comptes Coffrac importes) : ' >/dev/tty
IFS= read -r ADMIN_EMAIL </dev/tty
printf 'Mot de passe unique, 8 caracteres minimum (saisie masquee) : ' >/dev/tty
IFS= read -r -s ADMIN_PASSWORD </dev/tty
printf '\nConfirmation (saisie masquee) : ' >/dev/tty
IFS= read -r -s ADMIN_CONFIRM </dev/tty
printf '\n' >/dev/tty
printf '%s\0%s\0%s\0%s' "$3" "$ADMIN_EMAIL" "$ADMIN_PASSWORD" "$ADMIN_CONFIRM" |
    php8.4 -r '
        $v=explode("\0", stream_get_contents(STDIN));
        if (count($v)!==4 || !filter_var(trim($v[1]),FILTER_VALIDATE_EMAIL) || strlen($v[2])<8 || trim($v[2])==="" || !hash_equals($v[2],$v[3])) {fwrite(STDERR,"STOP : identifiants ou confirmation invalides.\n");exit(1);}
        $h=fopen($argv[1],"x");fwrite($h,json_encode(["package_sha256"=>$v[0],"email"=>strtolower(trim($v[1])),"password"=>$v[2]],JSON_THROW_ON_ERROR));fclose($h);
    ' "$INPUT_RUN/admin.json"
unset ADMIN_PASSWORD ADMIN_CONFIRM ADMIN_EMAIL
chown root:"$RUNTIME" "$INPUT_RUN/admin.json"
chmod 0440 "$INPUT_RUN/admin.json"

printf 'Sauvegarde de la base V2 encore vide, avec son seul compte SQL limite.\n'
mysqldump --defaults-file="$RUN/mysql.cnf" --single-transaction --skip-lock-tables --no-tablespaces \
    --set-gtid-purged=OFF --column-statistics=0 --skip-triggers cofrac2production \
    2>"$RUN/backup.stderr" | gzip -1 > "$RUN/database-before.sql.gz"
gzip -t "$RUN/database-before.sql.gz"
rm -- "$RUN/mysql.cnf"
sha256sum "$APP/.env" > "$RUN/env.before.sha256"

# Give the runtime read access to new Git files only; no shared app or public storage traversal.
for directory in app config database tools; do
    [ -d "$APP/$directory" ] && [ ! -L "$APP/$directory" ] || stop 'repertoire de code inattendu.'
    find "$APP/$directory" -type d -exec chgrp "$RUNTIME" {} + -exec chmod 2750 {} +
    find "$APP/$directory" -type f -exec chgrp "$RUNTIME" {} + -exec chmod u+rw,g=r,o= {} +
done
app_cli() {
    runuser -u "$RUNTIME" -- env -i PATH="$PATH" HOME=/var/lib/cofrac2-app USER="$RUNTIME" LOGNAME="$RUNTIME" \
        php8.4 -d memory_limit=1536M "$@"
}
cd "$APP"
printf 'Migration V2 manquante uniquement, puis import transactionnel. Aucun service ne sera lance.\n'
app_cli artisan migrate --force --no-interaction --no-ansi > "$RUN/migration.log" 2>&1
printf 'Import en cours (plusieurs minutes possibles). Diagnostic prive : %s\n' "$RUN"
app_cli "$HERE/stage-production-import.php" --run "$INPUT_RUN/inputs" "$3" > "$RUN/report.json" 2> "$RUN/import.stderr"
sha256sum --status -c "$RUN/env.before.sha256" || stop '.env change pendant la preparation ; examiner avant ouverture.'
php8.4 -r '
    $r=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
    if (($r["staging_import"]["package_sha256"]??null)!==$argv[2] || ($r["staging_import"]["purpose"]??null)!=="isolated_production_preparation") exit(1);
    $h=fopen($argv[3],"x");fwrite($h,json_encode(["report"=>$argv[1],"package_sha256"=>$argv[2],"counts"=>$r["counts"],"final_cutover_ready"=>false],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n");fclose($h);
    echo json_encode($r["counts"],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
' "$RUN/report.json" "$3" "$STATE/STAGING-IMPORTED.json"
(cd "$RUN"; sha256sum database-before.sql.gz preflight.json report.json > SHA256SUMS)
printf 'Preparation V2 importee et verifiee. Rapport prive : %s/report.json\n' "$RUN"
printf 'Coffrac original inchange. cofrac2 reste en maintenance statique ; aucun service, portail ou envoi active.\n'
