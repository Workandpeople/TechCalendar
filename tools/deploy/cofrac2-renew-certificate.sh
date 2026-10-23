#!/usr/bin/env bash

# Dedicated webroot renewal: never run the Apache installer or existing hooks.
set -Eeuo pipefail
export PATH=/usr/sbin:/usr/bin:/sbin:/bin
if [ "${EUID:-$(id -u)}" -ne 0 ] || [ "$#" -ne 0 ] || [ "$(hostname -s)" != ns365601 ]; then
    printf 'Refuse : renouvellement root sur ns365601 uniquement.\n' >&2
    exit 1
fi
exec /usr/bin/certbot renew --quiet --non-interactive \
    --config /dev/null --no-directory-hooks \
    --config-dir /var/lib/cofrac2-letsencrypt \
    --work-dir /var/lib/cofrac2-certbot-work \
    --logs-dir /var/log/cofrac2-certbot \
    --cert-name cofrac2.geniuscontrole.com \
    --deploy-hook '/usr/sbin/apache2ctl configtest && /usr/bin/systemctl reload apache2.service'
