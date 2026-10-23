#!/usr/bin/env bash

# Initial dependency installation only. Never bootstrap Laravel or start a service.
set -Eeuo pipefail
umask 077
export LC_ALL=C

ACTION=${1:-}
if [ "${EUID:-$(id -u)}" -ne 0 ] || [ "$#" -ne 1 ] \
    || { [ "$ACTION" != '--install-v2-dependencies' ] && [ "$ACTION" != '--composer-only' ]; }; then
    printf 'Usage : sudo bash install-production-dependencies.sh --install-v2-dependencies|--composer-only\n' >&2
    exit 1
fi
if [ "$(hostname -s)" != ns365601 ]; then
    printf 'STOP : cible attendue ns365601.\n' >&2
    exit 1
fi

APP=/var/www/cofrac2
DEPLOY_USER=cofrac2-deploy
VERSION=8.4.27-1+ubuntu22.04.1+deb.sury.org+1
PACKAGES=("php8.4-fpm=$VERSION" "php8.4-intl=$VERSION" "php8.4-bcmath=$VERSION")
POLICY=/usr/sbin/policy-rc.d
POLICY_ACTIVE=0

stop() {
    printf 'STOP : %s\n' "$1" >&2
    exit 1
}

cd / || exit 1
for executable in apt-get dpkg-query runuser flock systemctl stat cmp sha256sum php8.4; do
    command -v "$executable" >/dev/null || stop "outil manquant : $executable"
done
exec 9>/run/lock/cofrac2-dependencies.lock
flock -n 9 || stop 'une preparation V2 est deja en cours.'
test -d "$APP/.git"
test -f "$APP/composer.lock"
test -f /usr/local/bin/composer
[ ! -L "$APP" ] || stop 'repertoire applicatif symbolique refuse.'
[ "$(stat -c %U "$APP")" = "$DEPLOY_USER" ] || stop 'proprietaire applicatif inattendu.'
[ "$(runuser -u "$DEPLOY_USER" -- git -C "$APP" branch --show-current)" = v2 ] || stop 'branche V2 attendue.'
[ -z "$(runuser -u "$DEPLOY_USER" -- git -C "$APP" status --porcelain)" ] || stop 'checkout modifie, examiner avant installation.'
[ ! -e "$APP/.env" ] && [ ! -L "$APP/.env" ] || stop 'configuration deja presente ; ce script cible la preparation initiale.'
if systemctl is-active --quiet php8.4-fpm.service; then
    stop 'PHP 8.4 FPM est deja utilise ; ne pas intervenir automatiquement.'
fi

WORK=$(mktemp -d /var/tmp/cofrac2-dependencies-XXXXXXXX)
printf 'Diagnostic prive : %s\n' "$WORK"

snapshot_services() {
    systemctl show apache2.service mysql.service php7.3-fpm.service php7.4-fpm.service php8.3-fpm.service \
        --property=Id,ActiveState,MainPID,ExecMainStartTimestampMonotonic,ExecReload
}

compare_services() {
    # A manager reload can clear old ExecReload results without running it.
    # Keep command definitions and process identity strict; reject new results.
    awk '
        BEGIN { RS = ""; FS = "\n" }
        {
            id = ""; state = ""; reload = ""
            for (i = 1; i <= NF; i++) {
                line = $i
                if (line ~ /^Id=/) id = line
                if (line ~ /^ExecReload=/) {
                    reload = line
                    sub(/ ; start_time=.*/, " }", line)
                }
                state = state line "\n"
            }
            if (id == "") exit 1
            if (FNR == NR) {
                states[id] = state; histories[id] = reload; before_count++
                next
            }
            after_count++
            if (!(id in states) || states[id] != state) exit 1
            if (reload != histories[id] && reload !~ / ; start_time=\[n\/a\] ; stop_time=\[n\/a\] ; pid=0 ; code=\(null\) ; status=0\/0 }$/) exit 1
        }
        END { if (before_count != after_count) exit 1 }
    ' "$WORK/services.before" "$WORK/services.after" \
        || stop 'etat des services existants modifie ; examiner le diagnostic.'
}

snapshot_apache() {
    find /etc/apache2/sites-enabled /etc/apache2/conf-enabled /etc/apache2/mods-enabled \
        -mindepth 1 -maxdepth 1 -printf '%p -> %l\n' | sort
    find -L /etc/apache2/sites-enabled /etc/apache2/conf-enabled /etc/apache2/mods-enabled \
        -mindepth 1 -maxdepth 1 -type f -exec sha256sum -- {} + | sort
}

snapshot_services > "$WORK/services.before"
snapshot_apache > "$WORK/apache.before"

if [ "$ACTION" = '--install-v2-dependencies' ]; then
    # Refuse any dependency plan beyond the three packages already reviewed.
    apt-get --simulate --no-remove --no-install-recommends install "${PACKAGES[@]}" > "$WORK/apt-plan.txt"
    cat "$WORK/apt-plan.txt"
    awk '
        $1 == "Remv" || $1 == "Purg" { exit 1 }
        $1 == "Inst" {
            package = $2
            sub(/:.*/, "", package)
            if (package != "php8.4-fpm" && package != "php8.4-intl" && package != "php8.4-bcmath") exit 1
            if ($3 ~ /^\[/) exit 1
        }
    ' "$WORK/apt-plan.txt" || stop 'plan APT different : aucune installation effectuee.'

    # Download before the temporary service policy, to keep its duration short.
    apt-get --download-only --assume-yes --no-remove --no-install-recommends install "${PACKAGES[@]}"

    restore_policy() {
        if [ "$POLICY_ACTIVE" = 1 ]; then
            if [ -e "$POLICY" ] || [ -L "$POLICY" ]; then
                [ -f "$POLICY" ] && [ ! -L "$POLICY" ] && cmp -s "$WORK/policy-deny" "$POLICY" \
                    || { printf 'STOP : policy-rc.d a change, examiner %s avant toute intervention.\n' "$WORK" >&2; return 1; }
                rm -- "$POLICY"
            fi
            if [ -e "$WORK/policy.original" ] || [ -L "$WORK/policy.original" ]; then
                mv -T -- "$WORK/policy.original" "$POLICY"
            fi
            POLICY_ACTIVE=0
        fi
    }

    cleanup() {
        local result=$?
        trap - EXIT
        if ! restore_policy; then result=1; fi
        if [ "$result" != 0 ]; then
            printf 'Installation interrompue. Diagnostic conserve : %s\n' "$WORK" >&2
        fi
        exit "$result"
    }
    trap cleanup EXIT
    trap 'exit 130' INT
    trap 'exit 143' TERM

    printf '#!/bin/sh\n# Temporary Coffrac 2 dependency installation: %s\nexit 101\n' "$(basename "$WORK")" > "$WORK/policy-deny"
    POLICY_ACTIVE=1
    if [ -e "$POLICY" ] || [ -L "$POLICY" ]; then
        mv -T -- "$POLICY" "$WORK/policy.original"
    fi
    install -o root -g root -m 0755 "$WORK/policy-deny" "$POLICY"

    # Package lifecycle helpers must not start/restart services. needrestart is
    # suspended for this command only; its persistent configuration is untouched.
    DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=l NEEDRESTART_SUSPEND=1 \
        apt-get --no-download --assume-yes --no-remove --no-install-recommends install "${PACKAGES[@]}"
    restore_policy
else
    for package in "${PACKAGES[@]}"; do
        name=${package%%=*}
        [ "$(dpkg-query --show --showformat='${db:Status-Status} ${Version}' "$name")" = "installed $VERSION" ] \
            || stop "paquet absent ou version inattendue : $name"
    done
    printf 'Paquets PHP deja installes et verifies. Reprise Composer uniquement, sans APT.\n'
fi

snapshot_services > "$WORK/services.after"
snapshot_apache > "$WORK/apache.after"
compare_services
cmp -s "$WORK/apache.before" "$WORK/apache.after" || stop 'configuration Apache active differente ; examiner le diagnostic.'
if systemctl is-active --quiet php8.4-fpm.service; then
    stop 'PHP 8.4 FPM a demarre, examiner avant de continuer.'
fi

php-fpm8.4 -t
php8.4 -r '
if (PHP_VERSION_ID < 80401) {
    fwrite(STDERR, "PHP >= 8.4.1 obligatoire.\n");
    exit(1);
}
$missing = false;
foreach (["pdo_mysql", "mbstring", "dom", "curl", "zip", "intl", "bcmath", "gd", "pcntl"] as $extension) {
    $loaded = extension_loaded($extension);
    echo $extension.": ".($loaded ? "OK" : "MANQUANT").PHP_EOL;
    $missing = $missing || ! $loaded;
}
exit($missing ? 1 : 0);
'

# Install locked production dependencies as the deployment user, never as root.
# No plugins, project scripts, key generation, migrations or application boot.
runuser -u "$DEPLOY_USER" -- php8.4 /usr/local/bin/composer \
    --working-dir="$APP" --no-plugins --no-scripts install \
    --no-dev --prefer-dist --no-interaction --optimize-autoloader --audit
runuser -u "$DEPLOY_USER" -- php8.4 /usr/local/bin/composer \
    --working-dir="$APP" --no-plugins --no-scripts check-platform-reqs --no-dev

snapshot_services > "$WORK/services.after"
snapshot_apache > "$WORK/apache.after"
compare_services
cmp -s "$WORK/apache.before" "$WORK/apache.after" || stop 'configuration Apache active differente ; examiner le diagnostic.'

printf 'Dependances V2 installees dans %s.\n' "$APP"
printf 'Configuration Apache et services existants inchanges. PHP 8.4 FPM non demarre.\n'
printf 'Aucun .env, aucune commande Laravel, aucun acces SQL ni migration.\n'
