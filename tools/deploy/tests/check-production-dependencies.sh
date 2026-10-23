#!/usr/bin/env bash

# Run only in a disposable Docker container with no network or application mounts.
set -Eeuo pipefail
if [ "${EUID:-$(id -u)}" -ne 0 ] || [ ! -f /.dockerenv ] \
    || [ "${COFRAC2_DEPENDENCY_TEST:-}" != 1 ] || [ "$(hostname -s)" != ns365601 ]; then
    printf 'Refuse : conteneur Docker jetable root, hostname ns365601 et COFRAC2_DEPENDENCY_TEST=1 requis.\n' >&2
    exit 1
fi
test ! -e /var/www/cofrac2
test ! -e /home/ubuntu/www/coffrac_preprod
SCRIPT=$(cd -- "$(dirname -- "$0")/.." && pwd)/install-production-dependencies.sh

useradd --create-home --user-group cofrac2-deploy
install -d -o cofrac2-deploy -g cofrac2-deploy -m 0700 /var/www/cofrac2
runuser -u cofrac2-deploy -- git -C /var/www/cofrac2 init --initial-branch=v2 >/dev/null
runuser -u cofrac2-deploy -- bash <<'FILES'
printf 'vendor/\n' > /var/www/cofrac2/.gitignore
printf '{}\n' > /var/www/cofrac2/composer.lock
git -C /var/www/cofrac2 add .gitignore composer.lock
git -C /var/www/cofrac2 -c user.name=Fixture -c user.email=fixture@example.invalid commit -m fixture >/dev/null
FILES
mkdir -p /etc/apache2/{sites-available,sites-enabled,conf-enabled,mods-enabled} /home/ubuntu/www/coffrac_preprod/public
printf 'ORIGINAL APP FIXTURE\n' > /home/ubuntu/www/coffrac_preprod/public/index.php
printf 'ORIGINAL VHOST FIXTURE\n' > /etc/apache2/sites-available/cofrac.conf
ln -s ../sites-available/cofrac.conf /etc/apache2/sites-enabled/cofrac.conf
sha256sum /home/ubuntu/www/coffrac_preprod/public/index.php /etc/apache2/sites-available/cofrac.conf > /tmp/cofrac2-original.sha256
install -o cofrac2-deploy -g cofrac2-deploy -m 0600 /dev/null /tmp/cofrac2-composer-calls

cat > /usr/local/bin/systemctl <<'SYSTEMCTL'
#!/usr/bin/env bash
case "$1" in
    is-active) exit 3 ;;
    show) printf 'Id=apache2.service\nActiveState=active\nMainPID=123\nExecReloadStartTimestampMonotonic=456\n' ;;
    *) touch /tmp/cofrac2-unexpected-service-action; exit 90 ;;
esac
SYSTEMCTL
cat > /usr/local/bin/apt-get <<'APT'
#!/usr/bin/env bash
set -Eeuo pipefail
scenario=$(cat /tmp/cofrac2-apt-scenario)
case " $* " in
    *' --simulate '*)
        printf 'Inst php8.4-fpm (8.4.27-1+ubuntu22.04.1+deb.sury.org+1 PPA [amd64])\n'
        printf 'Inst php8.4-intl (8.4.27-1+ubuntu22.04.1+deb.sury.org+1 PPA [amd64])\n'
        printf 'Inst php8.4-bcmath (8.4.27-1+ubuntu22.04.1+deb.sury.org+1 PPA [amd64])\n'
        if [ "$scenario" = unexpected-upgrade ]; then
            printf 'Inst php8.4-common [8.4.26] (8.4.27 PPA [amd64])\n'
        fi
        ;;
    *' --download-only '*) touch /tmp/cofrac2-downloaded ;;
    *' --no-download '*)
        test -e /tmp/cofrac2-downloaded
        test "${NEEDRESTART_MODE:-}" = l
        test "${NEEDRESTART_SUSPEND:-}" = 1
        test "${DEBIAN_FRONTEND:-}" = noninteractive
        policy_status=0
        /usr/sbin/policy-rc.d apache2 restart || policy_status=$?
        test "$policy_status" = 101
        policy_status=0
        /usr/sbin/policy-rc.d php8.4-fpm start || policy_status=$?
        test "$policy_status" = 101
        if [ "$scenario" = apt-failure ]; then exit 42; fi
        ;;
    *) exit 91 ;;
esac
APT
cat > /usr/local/bin/php8.4 <<'PHP'
#!/usr/bin/env bash
set -Eeuo pipefail
case "$1" in
    -r) printf 'Extension verification fixture: OK\n' ;;
    /usr/local/bin/composer)
        test "$(id -un)" = cofrac2-deploy
        [[ " $* " == *' --no-scripts '* ]]
        [[ " $* " == *' --no-plugins '* ]]
        [[ " $* " == *' --no-dev '* ]]
        printf 'composer as deployment user\n' >> /tmp/cofrac2-composer-calls
        mkdir -p /var/www/cofrac2/vendor
        printf 'FAKE AUTOLOAD\n' > /var/www/cofrac2/vendor/autoload.php
        ;;
    *) exit 92 ;;
esac
PHP
cat > /usr/local/bin/php-fpm8.4 <<'FPM'
#!/usr/bin/env bash
test "$#" = 1 && test "$1" = -t
FPM
touch /usr/local/bin/composer
chmod 0755 /usr/local/bin/{systemctl,apt-get,php8.4,php-fpm8.4}

# The only policy altered here belongs to the disposable fixture filesystem.
if [ -e /usr/sbin/policy-rc.d ] || [ -L /usr/sbin/policy-rc.d ]; then
    mv /usr/sbin/policy-rc.d /tmp/cofrac2-fixture-base-policy
fi
printf 'success\n' > /tmp/cofrac2-apt-scenario
bash "$SCRIPT" --install-v2-dependencies > /tmp/cofrac2-success.log
test ! -e /usr/sbin/policy-rc.d
test ! -e /var/www/cofrac2/.env
test -f /var/www/cofrac2/vendor/autoload.php
test ! -e /tmp/cofrac2-unexpected-service-action
sha256sum -c /tmp/cofrac2-original.sha256

printf '#!/bin/sh\n# Original fixture policy\nexit 0\n' > /usr/sbin/policy-rc.d
chmod 0751 /usr/sbin/policy-rc.d
sha256sum /usr/sbin/policy-rc.d > /tmp/cofrac2-policy.sha256
calls=$(wc -l < /tmp/cofrac2-composer-calls)
printf 'apt-failure\n' > /tmp/cofrac2-apt-scenario
if bash "$SCRIPT" --install-v2-dependencies > /tmp/cofrac2-apt-failure.log 2>&1; then exit 1; fi
sha256sum -c /tmp/cofrac2-policy.sha256
test "$(stat -c %a /usr/sbin/policy-rc.d)" = 751
test "$calls" = "$(wc -l < /tmp/cofrac2-composer-calls)"

mv /usr/sbin/policy-rc.d /tmp/cofrac2-external-policy
ln -s /tmp/cofrac2-external-policy /usr/sbin/policy-rc.d
sha256sum /tmp/cofrac2-external-policy > /tmp/cofrac2-external-policy.sha256
printf 'success\n' > /tmp/cofrac2-apt-scenario
bash "$SCRIPT" --install-v2-dependencies > /tmp/cofrac2-symlink.log
test -L /usr/sbin/policy-rc.d
test "$(readlink /usr/sbin/policy-rc.d)" = /tmp/cofrac2-external-policy
sha256sum -c /tmp/cofrac2-external-policy.sha256

printf 'unexpected-upgrade\n' > /tmp/cofrac2-apt-scenario
calls=$(wc -l < /tmp/cofrac2-composer-calls)
if bash "$SCRIPT" --install-v2-dependencies > /tmp/cofrac2-plan-refused.log 2>&1; then exit 1; fi
test "$calls" = "$(wc -l < /tmp/cofrac2-composer-calls)"
test -L /usr/sbin/policy-rc.d
sha256sum -c /tmp/cofrac2-external-policy.sha256
sha256sum -c /tmp/cofrac2-original.sha256
test ! -e /tmp/cofrac2-unexpected-service-action
printf 'OK : politique restauree (absence, fichier, symlink), echec APT, plan refuse, Composer non-root, aucun service ni ancien fichier modifie.\n'
