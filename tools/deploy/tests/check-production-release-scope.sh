#!/usr/bin/env bash
set -Eeuo pipefail
HERE=$(cd -- "$(dirname -- "$0")/.." && pwd -P)
WORK=$(mktemp -d)
trap 'rm -rf -- "$WORK"' EXIT
cat > "$WORK/allowed" <<'FILES'
app/Services/Crm/DossierMailDelivery.php
resources/js/dossier-mail-status.js
routes/crm.php
database/migrations/2026_10_28_200000_create_appointment_mail_deliveries_table.php
config/admin.php
config/calendar.php
vite.config.js
database/seeders/CoffracServiceAliasSeeder.php
tools/deploy/update-production-release.sh
FILES
bash "$HERE/update-production-release.sh" --check-scope "$WORK/allowed"
for file in config/database.php database/seeders/UnapprovedSeeder.php public/unapproved.php .env; do
    printf '%s\n' "$file" > "$WORK/refused"
    if bash "$HERE/update-production-release.sh" --check-scope "$WORK/refused" > "$WORK/output" 2>&1; then
        echo 'FAIL : changement hors perimetre accepte.' >&2
        exit 1
    fi
done
printf 'OK : garde-fou commun, controle avant maintenance, chemins non approuves refuses.\n'
