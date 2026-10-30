#!/usr/bin/env bash
# Test-only PID manager. Only the two NEW services can be started/stopped; no actual systemd daemon is used.
set -Eeuo pipefail
case "$*" in
    'show cofrac-mymar-fpm.service -p LoadState --value'|'show cofrac-mymar-http.service -p LoadState --value')
        if [ -f "/etc/systemd/system/$2" ]; then echo loaded; else echo not-found; fi ;;
    'daemon-reload') echo daemon-reload >> /fixtures/unit-actions ;;
    'start cofrac-mymar-fpm.service cofrac-mymar-http.service')
        (exec 8>&- 9>&-; exec /usr/sbin/php-fpm8.4 --nodaemonize --fpm-config /etc/cofrac-mymar-gateway/php-fpm.conf) > /fixtures/fpm.log 2>&1 &
        echo "$!" > /fixtures/fpm.pid
        if [ -f /fixtures/failstart ]; then exit 1; fi
        for _ in $(seq 1 30); do [ ! -S /run/cofrac-mymar-fpm/php.sock ] || break; sleep 0.1; done
        (exec 8>&- 9>&-; exec /usr/sbin/apache2 -f /etc/cofrac-mymar-gateway/apache.conf -DFOREGROUND) > /fixtures/http.log 2>&1 &
        echo "$!" > /fixtures/http.pid ;;
    'disable --now cofrac-mymar-http.service cofrac-mymar-fpm.service')
        for name in http fpm; do
            if [ -f "/fixtures/$name.pid" ]; then
                kill -TERM "$(cat "/fixtures/$name.pid")" 2>/dev/null || true
            fi
        done
        echo stopped >> /fixtures/unit-actions ;;
    'enable cofrac-mymar-fpm.service cofrac-mymar-http.service') echo enabled >> /fixtures/unit-actions ;;
    'show apache2.service mysql.service php7.3-fpm.service php7.4-fpm.service php8.3-fpm.service php8.4-fpm.service cofrac2-php-fpm.service cofrac2-queue.service cofrac2-reverb.service -p Id -p MainPID -p ExecMainStartTimestampMonotonic -p ActiveState -p SubState')
        echo 'Existing services unchanged (fixture)' ;;
    *) printf 'FORBIDDEN systemctl %s\n' "$*" > /fixtures/FORBIDDEN_CALL; exit 99 ;;
esac
