#!/usr/bin/env bash
# Invoked only inside the disposable SQL fixture. All credentials and source rows are synthetic.
set -Eeuo pipefail
umask 077
[ -f /.dockerenv ] && [ "${MYMAR_DATES_UPDATE_FIXTURE:-}" = 1 ] && [ "$(hostname -s)" = ns365601 ] || exit 1
SCRIPT=/var/www/cofrac2/tools/deploy/update-mymar-document-reader-dates.php
USER=cofrac_mymar_dr_abcdef01
sql() { mysql --defaults-file=/etc/mysql/debian.cnf --batch --skip-column-names -e "$1"; }
id cofrac2-app >/dev/null 2>&1 || useradd --system --user-group --no-create-home --shell /usr/sbin/nologin cofrac2-app
[ -x /usr/bin/php8.4 ] || ln -s "$(command -v php)" /usr/bin/php8.4
install -d -o root -g root -m 0700 /var/lib/cofrac2-production
install -d -o root -g cofrac2-app -m 0750 /etc/cofrac2-mymar-documents
install -d -o root -g root -m 0700 /etc/mysql
printf '[client]\nuser=root\nprotocol=socket\nsocket=/var/run/mysqld/mysqld.sock\n' > /etc/mysql/debian.cnf
php <<'PHP'
<?php
require '/workspace/vendor/autoload.php';
file_put_contents('/etc/cofrac2-mymar-documents/reader.json', json_encode([
    'format'=>'cofrac.mymar-document-reader','version'=>1,'enabled'=>true,'writes_enabled'=>false,
    'username'=>'cofrac_mymar_dr_abcdef01','password'=>'Md4!'.str_repeat('a',64),
    'uploads_root'=>App\Services\Mymar\MymarDocumentSettings::UPLOADS,
], JSON_THROW_ON_ERROR));
PHP
chgrp cofrac2-app /etc/cofrac2-mymar-documents/reader.json
chmod 0640 /etc/cofrac2-mymar-documents/reader.json
sha256sum /etc/cofrac2-mymar-documents/reader.json > /fixtures/document-date-config.sha
sql 'CHECKSUM TABLE MYMAR.dossiers,MYMAR.dossier_rdv,MYMAR.dossier_documents,MYMAR.dossier_historiques,MYMAR.users,MYMAR.clients,MYMAR.roles,MYMAR.model_has_roles,MYMAR.status' > /fixtures/document-dates.before
sql "REVOKE SELECT (created_at) ON MYMAR.dossier_documents FROM '$USER'@'localhost';"
php8.4 "$SCRIPT" --apply > /fixtures/document-dates-first.log
php8.4 "$SCRIPT" --apply > /fixtures/document-dates-repeat.log
php -d zend.assertions=1 -d assert.exception=1 <<'PHP'
<?php
$reports=array_map(fn($p)=>json_decode(file_get_contents($p),true,8,JSON_THROW_ON_ERROR), glob('/var/lib/cofrac2-production/mymar-document-dates-*/report.json'));
assert(count($reports)===2);
assert(count(array_filter($reports,fn($r)=>$r['new_column_granted']))===1);
PHP

# An unrelated privilege must stop the upgrade before a date grant.
sql "REVOKE SELECT (created_at) ON MYMAR.dossier_documents FROM '$USER'@'localhost'; GRANT SELECT (password) ON MYMAR.users TO '$USER'@'localhost';"
if php8.4 "$SCRIPT" --apply > /fixtures/document-dates-unsafe.log 2>&1; then exit 1; fi
sql "REVOKE SELECT (password) ON MYMAR.users FROM '$USER'@'localhost';"

# A failed runtime probe must revoke just the column this execution added.
chgrp root /etc/cofrac2-mymar-documents/reader.json
if php8.4 "$SCRIPT" --apply > /fixtures/document-dates-rollback.log 2>&1; then exit 1; fi
chgrp cofrac2-app /etc/cofrac2-mymar-documents/reader.json
php -d zend.assertions=1 -d assert.exception=1 <<'PHP'
<?php
require '/workspace/tools/deploy/mymar/documents/MymarDocumentFailure.php';
require '/workspace/tools/deploy/mymar/documents/MymarDocumentSqlAccess.php';
$p=new PDO('mysql:unix_socket=/var/run/mysqld/mysqld.sock','cofrac_mymar_dr_abcdef01','Md4!'.str_repeat('a',64),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
assert(Cofrac2\Deploy\Mymar\MymarDocumentSqlAccess::validateGrants($p->query('SHOW GRANTS FOR CURRENT_USER()')->fetchAll(PDO::FETCH_COLUMN))===false);
PHP
php8.4 "$SCRIPT" --apply > /fixtures/document-dates-final.log
sha256sum -c /fixtures/document-date-config.sha
sql 'CHECKSUM TABLE MYMAR.dossiers,MYMAR.dossier_rdv,MYMAR.dossier_documents,MYMAR.dossier_historiques,MYMAR.users,MYMAR.clients,MYMAR.roles,MYMAR.model_has_roles,MYMAR.status' > /fixtures/document-dates.after
cmp /fixtures/document-dates.before /fixtures/document-dates.after
echo 'Mise a jour des dates : idempotence, refus de privileges etendus, retour cible apres echec ; donnees et configuration inchangees.'
