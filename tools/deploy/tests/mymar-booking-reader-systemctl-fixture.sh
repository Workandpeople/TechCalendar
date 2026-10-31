#!/usr/bin/env bash
# Test-only PID manager; any command touching an existing service is forbidden.
set -Eeuo pipefail
case "$*" in
    'show cofrac-mymar-booking-fpm.service -p LoadState --value'|'show cofrac-mymar-booking-http.service -p LoadState --value')
        if [ -f "/etc/systemd/system/$2" ]; then echo loaded; else echo not-found; fi ;;
    'daemon-reload') echo daemon-reload >> /fixtures/reader-unit-actions ;;
    'start cofrac-mymar-booking-fpm.service cofrac-mymar-booking-http.service')
        (exec 8>&- 9>&-; exec /usr/sbin/php-fpm8.4 --nodaemonize --fpm-config /etc/cofrac-mymar-booking-gateway/php-fpm.conf) > /fixtures/reader-fpm.log 2>&1 &
        echo "$!" > /fixtures/reader-fpm.pid
        if [ -f /fixtures/reader-failstart ]; then exit 1; fi
        for _ in $(seq 1 30); do [ ! -S /run/cofrac-mymar-booking-fpm/php.sock ] || break; sleep 0.1; done
        (exec 8>&- 9>&-; exec /usr/sbin/apache2 -f /etc/cofrac-mymar-booking-gateway/apache.conf -DFOREGROUND) > /fixtures/reader-http.log 2>&1 &
        echo "$!" > /fixtures/reader-http.pid ;;
    'disable --now cofrac-mymar-booking-http.service cofrac-mymar-booking-fpm.service')
        for name in http fpm; do
            if [ -f "/fixtures/reader-$name.pid" ]; then kill -TERM "$(cat "/fixtures/reader-$name.pid")" 2>/dev/null || true; fi
        done
        echo stopped >> /fixtures/reader-unit-actions ;;
    'enable cofrac-mymar-booking-fpm.service cofrac-mymar-booking-http.service') echo enabled >> /fixtures/reader-unit-actions ;;
    'show apache2.service mysql.service php7.3-fpm.service php7.4-fpm.service php8.3-fpm.service php8.4-fpm.service cofrac2-php-fpm.service cofrac2-queue.service cofrac2-reverb.service cofrac-mymar-fpm.service cofrac-mymar-http.service cofrac2-mymar-observation.timer -p Id -p MainPID -p ExecMainStartTimestampMonotonic -p ActiveState -p SubState')
        echo 'Existing services, occupation gateway and timer unchanged (fixture)' ;;
    *) printf 'FORBIDDEN systemctl %s\n' "$*" > /fixtures/FORBIDDEN_CALL; exit 99 ;;
esac
