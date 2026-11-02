#!/usr/bin/env bash
# Disposable Linux fixture only, with the audit source mounted read-only.
set -Eeuo pipefail
[ "$EUID" = 0 ] && [ -f /.dockerenv ] && [ "$(hostname -s)" = ns365601 ] \
    && [ "${COFRAC_DISK_AUDIT_TEST:-}" = 1 ] || exit 1
SCRIPT=/audit/audit-production-disk.sh
if ! id ubuntu >/dev/null 2>&1; then useradd -M ubuntu; fi
install -d -m 0700 /var/lib/cofrac2-production
install -d -m 0755 /home/ubuntu/www/coffrac_preprod/public/documents /var/www/cofrac2/storage /srv/cofrac2-document-archive /var/lib/mysql /etc/apache2/sites-enabled
install -d /etc/mysql
install -m 0600 /dev/null /etc/mysql/debian.cnf
# Replace the client only inside this disposable container: no real SQL is run.
cat > /usr/bin/mysql <<'MYSQL'
#!/usr/bin/env bash
set -eu
case "$*" in
    *'SHOW BINARY LOGS'*) printf 'binlog.000001\t600000000\tNo\nbinlog.000002\t600000000\tNo\n' ;;
    *'START TRANSACTION READ ONLY;'*) printf 'database_name\ttables_count\testimated_bytes\nfixture\t2\t123456\n' ;;
    *) exit 42 ;;
esac
MYSQL
chmod 0755 /usr/bin/mysql
printf 'SECRET_FIXTURE_MUST_NOT_APPEAR\n' > /var/www/cofrac2/.env
printf 'DOCUMENT_CONTENT_MUST_NOT_APPEAR\n' > /home/ubuntu/www/coffrac_preprod/public/documents/important.pdf
printf 'LOG_CONTENT_MUST_NOT_APPEAR\n' > /var/log/audit-sentinel.log
printf 'DocumentRoot /var/www/cofrac2/public\n' > /etc/apache2/sites-enabled/cofrac.conf
truncate -s 600M /var/lib/cofrac2-production/sparse-backup.sql
sha256sum /var/www/cofrac2/.env /home/ubuntu/www/coffrac_preprod/public/documents/important.pdf /var/log/audit-sentinel.log > /fixture-checksums
if bash "$SCRIPT" --delete > /tmp/refused.log 2>&1; then exit 1; fi
if runuser -u nobody -- bash "$SCRIPT" --audit > /tmp/refused-user.log 2>&1; then exit 1; fi
bash "$SCRIPT" --audit > /tmp/collected.log
REPORT=$(sed -n 's/^REPORT_DIR=//p' /tmp/collected.log)
[[ "$REPORT" =~ ^/home/ubuntu/cofrac2-disk-report-[A-Za-z0-9]+$ ]]
(cd "$REPORT" && sha256sum -c SHA256SUMS)
grep -q 'CAPACITE_FINALE' "$REPORT/sections.tsv"
grep -q 'sparse-backup.sql' "$REPORT/audit.txt"
grep -q '/var/www/cofrac2/public' "$REPORT/audit.txt"
grep -q 'binlog_files=2' "$REPORT/audit.txt"
grep -q 'binlog_bytes=1200000000' "$REPORT/audit.txt"
! grep -Eq 'SECRET_FIXTURE_MUST_NOT_APPEAR|DOCUMENT_CONTENT_MUST_NOT_APPEAR|LOG_CONTENT_MUST_NOT_APPEAR' "$REPORT/audit.txt"
test "$(stat -c %a "$REPORT")" = 700
test "$(stat -c %a "$REPORT/audit.txt")" = 600
test "$(stat -c %U "$REPORT/audit.txt")" = ubuntu
sha256sum -c /fixture-checksums
echo 'Audit disque : fixture OK, contenu applicatif inchange et non exporte.'
