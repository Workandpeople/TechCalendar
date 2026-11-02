#!/usr/bin/env bash
# Real reader update/recovery in the existing disposable MySQL/Apache/FPM fixture.
set -Eeuo pipefail
[ -f /.dockerenv ] && [ -d /reader-update-package ] && [ -d /var/lib/cofrac-mymar-booking-gateway/code ] || exit 1
cd /reader-update-package
seal() { find . -type f ! -name SHA256SUMS -print0 | sort -z | xargs -0 sha256sum > SHA256SUMS; }
apply() { bash ./update-code.sh --apply "$(sha256sum SHA256SUMS | cut -d' ' -f1)"; }
tree() { find /var/lib/cofrac-mymar-booking-gateway/code -type f -exec sha256sum {} + | sort; }
# Simulate the deployed reader before calendar labels existed (no optional callback or window helper).
systemctl stop cofrac-mymar-booking-http.service cofrac-mymar-booking-fpm.service
php <<'PHP'
<?php
$path='/var/lib/cofrac-mymar-booking-gateway/code/entry.php';
$body=file_get_contents($path);
$body=str_replace("'MymarReadWindow', ", '', $body, $count);if($count!==1)exit(1);
$body=str_replace(",\n        static fn (\$window, \$now) => \$reader()->calendarLabels(\$window, \$now)", '', $body, $count);if($count!==1)exit(1);
file_put_contents($path,$body);
unlink(dirname($path).'/MymarReadWindow.php');
PHP
systemctl start cofrac-mymar-booking-fpm.service cofrac-mymar-booking-http.service
sleep 0.5
cp REQUEST.json /fixtures/reader-update.request
tree > /fixtures/reader-code.before
cp /fixtures/reader-unit-actions /fixtures/reader-units.before
# Wrong target release and altered manifests fail before stopping the reader.
php -r '$p="REQUEST.json";$r=json_decode(file_get_contents($p),true);$r["revision"]=str_repeat("b",40);file_put_contents($p,json_encode($r));'
seal
if apply > /fixtures/reader-update-wrong-release.log 2>&1; then exit 1; fi
tree > /fixtures/reader-code.after; cmp /fixtures/reader-code.before /fixtures/reader-code.after
cmp /fixtures/reader-units.before /fixtures/reader-unit-actions
cp /fixtures/reader-update.request REQUEST.json; seal
if bash ./update-code.sh --apply "$(printf '%064d' 0)" > /fixtures/reader-update-wrong-manifest.log 2>&1; then exit 1; fi
cmp /fixtures/reader-units.before /fixtures/reader-unit-actions
# A failed restart must restore the exact old code and make its GET endpoint usable.
touch /fixtures/reader-restart-fail-once
if apply > /fixtures/reader-update-recovery.log 2>&1; then exit 1; fi
grep -q 'Ancien lecteur restaure et verifie' /fixtures/reader-update-recovery.log
tree > /fixtures/reader-code.after; cmp /fixtures/reader-code.before /fixtures/reader-code.after
apply > /fixtures/reader-update-success.log 2>&1
php -d zend.assertions=1 -d assert.exception=1 <<'PHP'
<?php
$complete=[];
foreach(glob('/var/lib/cofrac2-production/mymar-reader-update-*/summary.json')as$file){
 $r=json_decode(file_get_contents($file),true,16,JSON_THROW_ON_ERROR);
 assert($r['complete']===true&&$r['calendar_labels_ready']===true&&$r['configuration_unchanged']===true&&$r['sql_writes']===false);
 $complete[]=$r;
}
assert(count($complete)===1);
assert(!file_exists('/fixtures/FORBIDDEN_CALL')&&!file_exists('/fixtures/APPLICATION_EXECUTED'));
PHP
echo 'Reader code upgrade: pinned release, tamper refusal, failed restart rollback and real GET verified; no SQL/config/source writes.'
