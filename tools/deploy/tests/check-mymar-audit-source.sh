#!/usr/bin/env bash
# Disposable PHP container; the only host mount is the collector source, read-only.
set -Eeuo pipefail
[ "${MYMAR_AUDIT_TEST:-}" = 1 ] && [ "$#" = 1 ] || { echo 'MYMAR_AUDIT_TEST=1 et repertoire du test MySQL jetable requis.' >&2; exit 1; }
WORK=$(cd "$1" && pwd -P)
case "$WORK" in /tmp/mymar-audit-mysql-test-*|/private/tmp/mymar-audit-mysql-test-*) ;; *) exit 1 ;; esac
BASE=$(cd "$(dirname "$0")/.." && pwd -P)
CID=
cleanup() { if [ -n "$CID" ]; then docker rm -f -v "$CID" >/dev/null; fi; }
trap cleanup EXIT
CID=$(docker run -d --hostname ns365601 --network none --mount "type=bind,source=$BASE/mymar,target=/audit,readonly" php:8.5-fpm-bookworm sleep 600)
docker exec -i "$CID" php <<'PHP'
<?php
$paths=['routes/api.php','app/Http/Kernel.php','app/Providers/RouteServiceProvider.php','app/Http/Controllers/ApiController.php',
 'app/Http/Controllers/DossierController.php','app/Models/Dossier.php','app/Models/DossierRDV.php','app/Models/Technicien.php',
 'app/Models/User.php','app/Console/Commands/RdvCron.php','config/app.php'];
foreach($paths as $path) {
 $file='/home/ubuntu/www/MYMAR/'.$path; if(!is_dir(dirname($file))) mkdir(dirname($file),0700,true);
 file_put_contents($file,"<?php throw new RuntimeException('APPLICATION_MUST_NOT_EXECUTE'); function planning() { User::role(4)->where('enabled',1); \$q->whereIn('dossiers.status_id',[6,12])->where('is_old',0); }\n");
}
foreach(['/home/ubuntu/www/MYMAR'=>'MYMAR','/var/www/cofrac2'=>'cofrac2production'] as $root=>$db) {
 if(!is_dir($root.'/bootstrap/cache')) mkdir($root.'/bootstrap/cache',0700,true);
 file_put_contents($root.'/.env',"DB_CONNECTION=mysql\nDB_HOST=127.0.0.1\nDB_DATABASE=$db\nDB_PASSWORD=CANARY_ENV_SECRET\n");
 $cache=['app'=>['timezone'=>'Europe/Paris'],'database'=>['default'=>'mysql','connections'=>['mysql'=>[
 'driver'=>'mysql','host'=>'127.0.0.1','port'=>3306,'database'=>$db,'password'=>'CANARY_CACHE_SECRET']]]];
 if($db==='MYMAR') $cache['logging']['unrelated']='OPAQUE_MARKER';
 $literal=str_replace("'OPAQUE_MARKER'", '\\MustNotAutoload\\Formatter::__set_state(array("key" => "CANARY_OPAQUE_SECRET"))',var_export($cache,true));
 file_put_contents($root.'/bootstrap/cache/config.php','<?php return '.$literal.';');
}
$routes=['attributes'=>['test'=>['uri'=>'api/json/planning','methods'=>['GET'],
 'action'=>['middleware'=>['api','CheckKey:CANARY_MIDDLEWARE_SECRET']]]]];
file_put_contents('/home/ubuntu/www/MYMAR/bootstrap/cache/routes-v7.php',"<?php app('router')->setCompiledRoutes(".var_export($routes,true).');');
mkdir('/var/lib/cofrac2-production/mymar-readonly-Fixture',0700,true);
PHP
RUN=/var/lib/cofrac2-production/mymar-readonly-Fixture
docker exec "$CID" php -d memory_limit=128M /audit/collect.php prepare "$RUN"
docker cp "$WORK/metadata.out" "$CID:$RUN/metadata.jsonl"
docker exec "$CID" php /audit/collect.php metadata "$RUN"
docker cp "$WORK/data.out" "$CID:$RUN/database.jsonl"
docker exec "$CID" php /audit/collect.php finish "$RUN"
docker exec -i "$CID" php <<'PHP'
<?php
$run='/var/lib/cofrac2-production/mymar-readonly-Fixture';
$summary=json_decode(file_get_contents($run.'/summary.json'),true,32,JSON_THROW_ON_ERROR);
if(!$summary['sql']['complete'] || !$summary['source_unchanged_during_collection'] || $summary['application_executed']) exit(1);
foreach(['source-facts.json.gz','database-audit.json.gz'] as $file) {
 $text=gzdecode(file_get_contents($run.'/'.$file));
 if(preg_match('/CANARY|@example|APPLICATION_MUST_NOT_EXECUTE/',$text)) exit(1);
}
$source=json_decode(gzdecode(file_get_contents($run.'/source-facts.json.gz')),true,64,JSON_THROW_ON_ERROR);
if(!$source['route_cache']['decoded'] || $source['route_cache']['api_routes'][0]['middleware_names']!==['api','CheckKey']) exit(1);
if(!$source['identities']['mymar']['sql_eligible'] || $source['identities']['mymar']['opaque_objects_outside_database_and_timezone_ignored']!==1
 || $source['identities']['mymar']['runtime_configuration_confirmed']) exit(1);
$changed='/var/lib/cofrac2-production/mymar-readonly-Changed'; mkdir($changed,0700);
foreach(['source-state.private.json','database.jsonl'] as $file) copy($run.'/'.$file,$changed.'/'.$file);
$partial='/var/lib/cofrac2-production/mymar-readonly-Partial'; mkdir($partial,0700);
copy($run.'/source-state.private.json',$partial.'/source-state.private.json');
$lines=file($run.'/database.jsonl',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES); array_pop($lines);
file_put_contents($partial.'/database.jsonl',implode("\n",$lines)."\n");
copy('/home/ubuntu/www/MYMAR/bootstrap/cache/config.php','/var/lib/cofrac2-production/original-cache.private');
file_put_contents('/home/ubuntu/www/MYMAR/bootstrap/cache/config.php', '<?php return ["x"=>\\MustNotAutoload\\Config::make(["secret"=>"CANARY_INVALID_SECRET"])];');
mkdir('/var/lib/cofrac2-production/mymar-readonly-Unsupported',0700);
PHP
docker exec "$CID" php /audit/collect.php prepare /var/lib/cofrac2-production/mymar-readonly-Unsupported
docker exec -i "$CID" php <<'PHP'
<?php
$run='/var/lib/cofrac2-production/mymar-readonly-Unsupported';
$json=gzdecode(file_get_contents($run.'/source-facts.json.gz'));
$source=json_decode($json,true,64,JSON_THROW_ON_ERROR);
if($source['identities']['mymar']['sql_eligible'] || is_file($run.'/metadata.sql') || preg_match('/CANARY|MustNotAutoload|::make/',$json)
 || $source['identities']['mymar']['cache_syntax']['reason']!=='unsupported_static_expression') exit(1);
copy('/var/lib/cofrac2-production/original-cache.private','/home/ubuntu/www/MYMAR/bootstrap/cache/config.php');
echo 'Cache avec objet opaque accepte sans chargement de classe ; autre expression refusee avant SQL.'.PHP_EOL;
PHP
docker exec "$CID" php /audit/collect.php finish /var/lib/cofrac2-production/mymar-readonly-Partial
docker exec -i "$CID" php <<'PHP'
<?php
$partial='/var/lib/cofrac2-production/mymar-readonly-Partial';
$summary=json_decode(file_get_contents($partial.'/summary.json'),true,32,JSON_THROW_ON_ERROR);
if(!$summary['source_unchanged_during_collection'] || $summary['sql']['complete'] || is_file($partial.'/database-audit.json.gz')) exit(1);
file_put_contents('/home/ubuntu/www/MYMAR/app/Http/Controllers/ApiController.php',"\n// concurrent change\n",FILE_APPEND);
PHP
docker exec "$CID" php /audit/collect.php finish /var/lib/cofrac2-production/mymar-readonly-Changed
docker exec -i "$CID" php <<'PHP'
<?php
$run='/var/lib/cofrac2-production/mymar-readonly-Changed';
$report=json_decode(file_get_contents($run.'/summary.json'),true,32,JSON_THROW_ON_ERROR);
if($report['source_unchanged_during_collection'] || $report['sql']['complete'] || is_file($run.'/database-audit.json.gz')) exit(1);
echo 'Collecte complete simulee : aucun bootstrap, secrets masques, modifications concurrentes detectees ; resultat incomplet non publie.'.PHP_EOL;
PHP

# Exercise the full shell publisher with deterministic SQL outputs (the actual SQL was tested separately on MySQL).
docker exec "$CID" sh -c 'mkdir -p /fixtures /package /etc/mysql; useradd -M ubuntu; ln -s /usr/local/bin/php /usr/bin/php8.4; printf "[client]\n" > /etc/mysql/debian.cnf; chmod 600 /etc/mysql/debian.cnf'
docker cp "$WORK/metadata.out" "$CID:/fixtures/metadata.out"
docker cp "$WORK/data.out" "$CID:/fixtures/data.out"
docker cp "$WORK/explain.out" "$CID:/fixtures/explain.out"
docker exec -i "$CID" sh <<'SH'
set -eu
cat > /usr/bin/mysql <<'MYSQL'
#!/bin/sh
sql=$(cat)
case "$sql" in
  *information_schema.TABLES*) cat /fixtures/metadata.out ;;
  *'EXPLAIN SELECT'*) cat /fixtures/explain.out ;;
  *"'section','counts'"*)
      cat /fixtures/data.out
      if [ -f /fixtures/fail-data ]; then
          printf 'ERROR 3024 (HY000) at line 14: CANARY_PRIVATE_SQL_ERROR private@example.test\n' >&2
          exit 1
      fi
      ;;
  *) exit 99 ;;
esac
MYSQL
chmod 700 /usr/bin/mysql
for file in MymarReadOnlyAudit.php collect.php collect.sh fetch-audit.sh README.txt; do cp "/audit/$file" "/package/$file"; chmod 600 "/package/$file"; done
cd /package
sha256sum MymarReadOnlyAudit.php collect.php collect.sh fetch-audit.sh README.txt > SHA256SUMS
find /home/ubuntu/www/MYMAR /var/www/cofrac2 -type f -exec sha256sum {} + | sort > /fixtures/apps.before
bash ./collect.sh > /fixtures/wrapper.log
find /home/ubuntu/www/MYMAR /var/www/cofrac2 -type f -exec sha256sum {} + | sort > /fixtures/apps.after
cmp /fixtures/apps.before /fixtures/apps.after
SH
docker exec -i "$CID" php <<'PHP'
<?php
$log=file_get_contents('/fixtures/wrapper.log');
if(!preg_match('~^REPORT_DIR=(/home/ubuntu/cofrac2-mymar-readonly-report-[A-Za-z0-9]+)$~m',$log,$m)) exit(1);
$report=$m[1]; $allowed=['SHA256SUMS','source-facts.json.gz','database-audit.json.gz','summary.json','explain.tsv'];
foreach(new DirectoryIterator($report) as $entry) {
 if($entry->isDot()) continue;
 if(!in_array($entry->getFilename(),$allowed,true) || $entry->isLink() || ($entry->getPerms()&0777)!==0600 || $entry->getOwner()!==posix_getpwnam('ubuntu')['uid']) exit(1);
 if(str_ends_with($entry->getFilename(),'.gz') && preg_match('/CANARY|@example/',gzdecode(file_get_contents($entry->getPathname())))) exit(1);
}
$summary=json_decode(file_get_contents($report.'/summary.json'),true,32,JSON_THROW_ON_ERROR);
if(!$summary['sql']['complete'] || !$summary['source_unchanged_during_collection']) exit(1);
if($summary['sql']['stages']!==['metadata_sql'=>0,'metadata_validation'=>0,'data_sql'=>0,'explain_sql'=>0]
 || $summary['sql']['errors']!==[]) exit(1);
echo 'Pipeline shell et publication verifies : applications factices inchangees, rapports prives, SQL/erreurs/secrets exclus du telechargement.'.PHP_EOL;
PHP
docker exec "$CID" sh -c 'touch /fixtures/fail-data; cd /package; bash ./collect.sh > /fixtures/failure-wrapper.log'
docker exec -i "$CID" php <<'PHP'
<?php
$log=file_get_contents('/fixtures/failure-wrapper.log');
if(!preg_match('~^REPORT_DIR=(/home/ubuntu/cofrac2-mymar-readonly-report-[A-Za-z0-9]+)$~m',$log,$match)) exit(1);
$dir=$match[1]; $json=file_get_contents($dir.'/summary.json');
$summary=json_decode($json,true,32,JSON_THROW_ON_ERROR);
if($summary['sql']['complete'] || is_file($dir.'/database-audit.json.gz') || $summary['sql']['stages']['data_sql']!==1
 || $summary['sql']['errors']!==[['stage'=>'database','mysql_code'=>3024,'sqlstate'=>'HY000','statement_line'=>14]]
 || preg_match('/CANARY|@example|PRIVATE_SQL_ERROR/',$json.$log)) exit(1);
echo 'Echec SQL simule : code 3024 et etape publies sans erreur brute, resultat partiel non publie.'.PHP_EOL;
PHP
