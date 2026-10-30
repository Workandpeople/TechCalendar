#!/usr/bin/env bash
set -Eeuo pipefail
[ -f /.dockerenv ] && [ "$(hostname -s)" = ns365601 ] && [ -f /fixtures/pilot-cache-status.json ] || exit 1
trap 'printf "CONFLICT_REVIEW_FIXTURE_FAILED_AT_LINE=%s\n" "$LINENO" >&2; tail -n 15 /fixtures/conflict-*.log 2>/dev/null || true; find /var/lib/cofrac2-production/mymar-conflict-preview-* -name inspect.stderr -exec cat {} \; 2>/dev/null || true' ERR
mkdir -m 0700 /review-package
cp /workspace/tools/deploy/mymar/conflicts/{inspect.php,collect.sh,fetch-conflicts.sh,README.txt} /review-package/
cp /workspace/app/Services/Mymar/MymarOccupancySnapshot.php /review-package/
php <<'PHP'
<?php
$state=json_decode(file_get_contents('/var/lib/cofrac2-production/RUNTIME-PREPARED.json'),true,32,JSON_THROW_ON_ERROR);
$proof=[];
foreach(['app/Models/User.php','app/Models/Appointment.php','app/Services/Crm/DossierStatusCatalog.php','app/Services/Crm/AppointmentDateTime.php',
    'app/Services/Mymar/MymarPilotSettings.php','app/Services/Mymar/MymarPilotConfiguration.php','app/Services/Mymar/MymarCachedObservation.php',
    'app/Services/Mymar/MymarObservation.php'] as $file) $proof[$file]=hash_file('sha256','/var/www/cofrac2/'.$file);
file_put_contents('/review-package/REQUEST.json',json_encode(['format'=>'cofrac.mymar-conflict-preview-request','version'=>1,
    'runtime_revision'=>$state['revision'],'application_files'=>$proof],JSON_THROW_ON_ERROR));
PHP
runuser -u cofrac2-app -- env -i PATH=/usr/sbin:/usr/bin:/sbin:/bin HOME=/var/lib/cofrac2-app php8.4 <<'PHP'
<?php
chdir('/var/www/cofrac2');require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$pilot=app(App\Services\Mymar\MymarPilotSettings::class)->load();$snapshot=Illuminate\Support\Facades\Cache::get($pilot->cacheKey());$period=$snapshot['periods'][0];
$service=App\Models\Service::create(['type'=>'COFRAC','name'=>'Readonly review fixture','average_duration_minutes'=>60]);
App\Models\Appointment::create(['service_id'=>$service->id,'technician_id'=>$period['cofrac_user_id'],'created_by'=>101,
    'customer_first_name'=>'Synthetic','customer_last_name'=>'CANARY_NOT_EXPORTED','customer_phone'=>'CANARY_PHONE','address'=>'CANARY_ADDRESS',
    'starts_at'=>App\Services\Crm\AppointmentDateTime::parse($period['starts_at_utc']),
    'ends_at'=>App\Services\Crm\AppointmentDateTime::parse($period['ends_at_utc']),
    'duration_minutes'=>$period['duration_minutes'],'status'=>'scheduled']);
PHP
snapshot() { mysql --defaults-file=/etc/mysql/debian.cnf -NBe 'CHECKSUM TABLE MYMAR.users,MYMAR.dossiers,MYMAR.dossier_rdv,cofrac2production.users,cofrac2production.appointments,cofrac2production.dossiers,cofrac2production.cache;'; }
cd /review-package; chmod 0600 *; sha256sum * > SHA256SUMS
snapshot > /fixtures/conflict-business.before
bash collect.sh > /fixtures/conflict-review.log 2>&1
snapshot > /fixtures/conflict-business.after; cmp /fixtures/conflict-business.before /fixtures/conflict-business.after
php <<'PHP'
<?php
$report=json_decode(file_get_contents(glob('/home/ubuntu/cofrac2-mymar-conflicts-report-*/report.json')[0]),true,32,JSON_THROW_ON_ERROR);
if(!$report['complete']||!$report['dry_run']||!$report['read_only_transaction']||$report['application_bootstrapped']||$report['blocking_enabled']
    ||$report['source_database_read']||$report['http_requests']||$report['native_database_writes']||$report['overlap_pairs']!==1
    ||$report['conflicting_native_appointments']!==1||$report['native_appointments_checked']!==1)exit(1);
if(preg_match('/CANARY|password|token|email|address|beneficiaire/i',json_encode($report)))exit(1);
echo 'Audit READ ONLY : un conflit detecte, aucune ecriture cache/metier, aucun secret ou contact exporte.'.PHP_EOL;
PHP
# An expired cache must be refused without deleting it; no source API fallback is allowed.
mysql --defaults-file=/etc/mysql/debian.cnf -NBe "UPDATE cofrac2production.cache SET expiration=0 WHERE \`key\` LIKE '%mymar:observation:v1:%';"
snapshot > /fixtures/conflict-expired.before
if bash collect.sh > /fixtures/conflict-expired.log 2>&1; then exit 1; fi
snapshot > /fixtures/conflict-expired.after; cmp /fixtures/conflict-expired.before /fixtures/conflict-expired.after
test ! -f /fixtures/FORBIDDEN_CALL
echo 'Cache expire : diagnostic refuse, aucune purge ni modification des rendez-vous ou services.'
