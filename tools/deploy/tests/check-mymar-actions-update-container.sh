#!/usr/bin/env bash
# Synthetic container only: an installed writer with its existing configuration and SQL account.
set -Eeuo pipefail
[ -f /.dockerenv ] && [ -f /fixtures/actions-fpm.pid ] && [ -d /reader-update-package ] || exit 1
mkdir -m 700 /actions-update-package
cp /workspace/tools/deploy/mymar/booking-http/update-code.{php,sh} /actions-update-package/
php /workspace/tools/deploy/tests/mymar-reader-update-package-fixture.php --actions
cd /actions-update-package
seal() { find . -type f ! -name SHA256SUMS -print0 | sort -z | xargs -0 sha256sum > SHA256SUMS; }
apply() { bash ./update-code.sh --apply "$(sha256sum SHA256SUMS | cut -d' ' -f1)"; }
tree() { find /var/lib/cofrac-mymar-actions-gateway/code -type f -exec sha256sum {} + | sort; }
mysql --defaults-file=/etc/mysql/debian.cnf -NBe 'CHECKSUM TABLE MYMAR.users,MYMAR.clients,MYMAR.dossiers,MYMAR.dossier_rdv,MYMAR.dossier_historiques,cofrac_mymar_integration.booking_commands' > /fixtures/actions-update.before
tree > /fixtures/actions-code.before
seal
touch /fixtures/actions-restart-fail-once
if apply > /fixtures/actions-update-recovery.log 2>&1; then exit 1; fi
grep -q 'Ancien lecteur restaure et verifie' /fixtures/actions-update-recovery.log
tree > /fixtures/actions-code.after; cmp /fixtures/actions-code.before /fixtures/actions-code.after
apply > /fixtures/actions-update-success.log 2>&1
mysql --defaults-file=/etc/mysql/debian.cnf -NBe 'CHECKSUM TABLE MYMAR.users,MYMAR.clients,MYMAR.dossiers,MYMAR.dossier_rdv,MYMAR.dossier_historiques,cofrac_mymar_integration.booking_commands' > /fixtures/actions-update.after
cmp /fixtures/actions-update.before /fixtures/actions-update.after
php -d zend.assertions=1 -d assert.exception=1 <<'PHP'
<?php
$reports=array_filter(glob('/var/lib/cofrac2-production/mymar-reader-update-*/summary.json'),fn($p)=>(json_decode(file_get_contents($p),true)['target']??null)==='actions');
assert(count($reports)===1);
$r=json_decode(file_get_contents(array_values($reports)[0]),true);
assert($r['complete']===true&&$r['configuration_unchanged']===true&&$r['sql_writes']===false);
assert(!file_exists('/fixtures/FORBIDDEN_CALL'));
PHP
echo 'Writer code-only upgrade and rollback verified: no SQL, account, configuration, source or application changes.'
