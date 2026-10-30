#!/usr/bin/env bash
# Synthetic data only, inside the dedicated disposable container initialized by the SQL test.
set -Eeuo pipefail
BASE=$(cd "$(dirname "$0")/.." && pwd -P)
CONTAINER=cofrac-mymar-audit-fixture
[ "${MYMAR_AUDIT_TEST:-}" = 1 ] || { echo 'MYMAR_AUDIT_TEST=1 requis.' >&2; exit 1; }
[ "$(docker inspect "$CONTAINER" --format '{{.Config.Image}} {{.HostConfig.NetworkMode}}')" = 'mysql:8.0.46 none' ]
[ "$(docker inspect "$CONTAINER" --format '{{len .Mounts}}')" = 1 ]
[ "$(docker inspect "$CONTAINER" --format '{{(index .Mounts 0).Type}}')" = volume ]
[ "$(docker inspect "$CONTAINER" --format '{{(index .Mounts 0).Destination}}')" = /var/lib/mysql ]
[ "$(docker exec "$CONTAINER" mysql -NBe 'SELECT CONCAT((SELECT COUNT(*) FROM MYMAR.users),":",(SELECT COUNT(*) FROM MYMAR.dossiers),":",(SELECT COUNT(*) FROM MYMAR.dossier_rdv),":",(SELECT COUNT(*) FROM cofrac2production.users))')" = '4:3:5:3' ]
WORK=$(mktemp -d /tmp/mymar-audit-mysql-test-XXXXXXXX)
php -r '
require $argv[1]; $all=Cofrac2\Deploy\MymarReadOnlyAudit::dataSql();
$begin=substr($all,0,strpos($all,"SELECT JSON_OBJECT"));
$old=preg_replace("/^--[^\n]*\n/","",file_get_contents($argv[2]));
$new=Cofrac2\Deploy\MymarReadOnlyAudit::technicianSql();
foreach(["old"=>$old,"new"=>$new] as $key=>$query) {
 file_put_contents($argv[3]."/$key.sql",$begin.$query."\nROLLBACK;\n");
 file_put_contents($argv[3]."/$key-explain.sql",$begin."EXPLAIN ".$query."\nROLLBACK;\n");
}
' "$BASE/mymar/MymarReadOnlyAudit.php" "$BASE/tests/mymar-technicians-reference.sql" "$WORK"
read_sql() {
    docker exec -i "$CONTAINER" mysql --user=audit_reader --default-character-set=utf8mb4 --batch --raw --skip-column-names < "$1"
}
read_sql "$WORK/old.sql" > "$WORK/old-small.out"
read_sql "$WORK/new.sql" > "$WORK/new-small.out"
cmp "$WORK/old-small.out" "$WORK/new-small.out"
php > "$WORK/load.sql" <<'PHP'
<?php
function insertRows(string $table, array $rows): void {
    foreach (array_chunk($rows, 500) as $batch) echo 'INSERT INTO '.$table.' VALUES '.implode(',', $batch).";\n";
}
$source=[]; $target=[]; $links=[]; $roles=[]; $dossiers=[]; $rdvs=[];
for($i=0;$i<252;$i++) {
    $id=10+$i; $email=$i<2?'NULL':"'fixture-$i@example.test'";
    $cofrac=$i%11===0?'NULL':($i%37===0?'7':(string)(10000+$i));
    $enabled=$i%17===0?0:1;
    $source[]="($id,'Technicien simulation $i',$email,'CANARY_SOURCE_PASSWORD',$enabled,$cofrac)";
    $links[]='('.(10000+$i).','.(1000+$i).')';
}
for($i=0;$i<290;$i++) {
    $emailIndex=$i<252?$i:$i-252;
    $email=$emailIndex<2?'NULL':"' FIXTURE-$emailIndex@example.test '";
    $deleted=$i%19===0?"'2026-01-01 00:00:00'":'NULL'; $external=$i%23===0?'42':'NULL';
    $target[]='('.(1000+$i).",'Tech','Simulation $i',$email,'CANARY_TARGET_PASSWORD',2,$deleted,$external)";
}
for($i=0;$i<217;$i++) {
    $id=$i<205?10+$i:250+$i-205;
    $roles[]="(4,$id,CONCAT('App',CHAR(92),'Models',CHAR(92),'User'))";
}
for($i=0;$i<5060;$i++) {
    $id=1000+$i; $status=[6,12,20][$i%3]; $tech=10+$i%240;
    $auditor=$i%29===0?(string)(250+$i%6):'NULL'; $type=$i%2;
    $dossiers[]="($id,$status,$tech,$auditor,$type)";
}
for($i=0;$i<8353;$i++) {
    $id=1000+$i; $dossier=1000+$i%5060; $day=sprintf('%02d',1+$i%28);
    $duration=$i%10===0?'NULL':(string)(30+$i%60); $old=$i%7===0?1:0;
    $rdvs[]="($id,$dossier,'2026-11-$day 10:00:00',$duration,$old,'2026-10-30 10:00:00')";
}
foreach(['MYMAR.users'=>$source,'cofrac2production.users'=>$target,'cofrac2production.coffrac_user_links'=>$links,
 'MYMAR.model_has_roles'=>$roles,'MYMAR.dossiers'=>$dossiers,'MYMAR.dossier_rdv'=>$rdvs] as $table=>$rows) insertRows($table,$rows);
PHP
docker exec -i "$CONTAINER" mysql < "$WORK/load.sql"
snapshot() {
    docker exec "$CONTAINER" mysql -NBe 'CHECKSUM TABLE MYMAR.status,MYMAR.roles,MYMAR.model_has_roles,MYMAR.users,MYMAR.dossiers,MYMAR.dossier_rdv,cofrac2production.users,cofrac2production.coffrac_user_links;'
}
snapshot > "$WORK/before"
read_sql "$WORK/old-explain.sql" > "$WORK/old-explain.out"
read_sql "$WORK/new-explain.sql" > "$WORK/new-explain.out"
! rg -q 'DEPENDENT' "$WORK/new-explain.out"
START=$(php -r 'echo hrtime(true);')
OLD_RC=0
read_sql "$WORK/old.sql" > "$WORK/old-large.out" 2> "$WORK/old-large.err" || OLD_RC=$?
OLD_MS=$(php -r 'echo (int)((hrtime(true)-(int)$argv[1])/1000000);' "$START")
START=$(php -r 'echo hrtime(true);')
read_sql "$WORK/new.sql" > "$WORK/new-large.out"
NEW_MS=$(php -r 'echo (int)((hrtime(true)-(int)$argv[1])/1000000);' "$START")
if [ "$OLD_RC" = 0 ]; then
    cmp "$WORK/old-large.out" "$WORK/new-large.out"
else
    rg -q '^ERROR 3024 \(HY000\)' "$WORK/old-large.err"
fi
snapshot > "$WORK/after-dense"
cmp "$WORK/before" "$WORK/after-dense"
# A sparse technician population forces the old IN/UNION predicate to scan dossiers for non-candidates.
# These fixture writes run only in the isolated disposable container, never in the operator's package.
docker exec "$CONTAINER" mysql -e '
 UPDATE MYMAR.dossiers SET technicien_id=10+MOD(id,12),auditeur_id=IF(MOD(id,29)=0,22+MOD(id,4),NULL) WHERE id>=1000;
 UPDATE MYMAR.model_has_roles SET role_id=5 WHERE model_id>=10;
 UPDATE MYMAR.model_has_roles SET role_id=4 WHERE model_id IN (250,251);'
snapshot > "$WORK/before-sparse"
SPARSE_OLD_RC=0
START=$(php -r 'echo hrtime(true);')
read_sql "$WORK/old.sql" > "$WORK/old-sparse.out" 2> "$WORK/old-sparse.err" || SPARSE_OLD_RC=$?
SPARSE_OLD_MS=$(php -r 'echo (int)((hrtime(true)-(int)$argv[1])/1000000);' "$START")
START=$(php -r 'echo hrtime(true);')
read_sql "$WORK/new.sql" > "$WORK/new-sparse.out"
SPARSE_NEW_MS=$(php -r 'echo (int)((hrtime(true)-(int)$argv[1])/1000000);' "$START")
if [ "$SPARSE_OLD_RC" = 0 ]; then
    cmp "$WORK/old-sparse.out" "$WORK/new-sparse.out"
else
    rg -q '^ERROR 3024 \(HY000\)' "$WORK/old-sparse.err"
fi
for mode in metadata data explain; do
    php -r 'require $argv[1]; echo Cofrac2\Deploy\MymarReadOnlyAudit::{$argv[2]."Sql"}();' "$BASE/mymar/MymarReadOnlyAudit.php" "$mode" > "$WORK/$mode.sql"
    read_sql "$WORK/$mode.sql" > "$WORK/$mode.out"
done
snapshot > "$WORK/after"
cmp "$WORK/before-sparse" "$WORK/after"
php -r '
$rows=array_map(fn($line)=>json_decode($line,true,32,JSON_THROW_ON_ERROR),file($argv[1]."/new-large.out",FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES));
$sparse=array_map(fn($line)=>json_decode($line,true,32,JSON_THROW_ON_ERROR),file($argv[1]."/new-sparse.out",FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES));
if(count($rows)!==255 || preg_match("/CANARY|@example/",file_get_contents($argv[1]."/new-large.out"))) exit(1);
if(count($sparse)!==21 || preg_match("/CANARY|@example/",file_get_contents($argv[1]."/new-sparse.out"))) exit(1);
$byId=array_column($rows,null,"mymar_id");
if($byId[10]["historical_source_duplicates"]!==0 || $byId[10]["historical_email_matches"]!==null
 || $byId[12]["email_candidates"]!==2 || $byId[12]["unique_email_v2_id"]!==null) exit(1);
echo "Charge synthetique : 256 utilisateurs MYMAR, 293 V2, 5063 dossiers, 8358 RDV ; 255 candidats denses / 21 clairsemes, sans ecriture lors des lectures.".PHP_EOL;
' "$WORK"
printf 'ANCIENNE_REQUETE_RC=%s WALL_MS=%s\nNOUVELLE_REQUETE_WALL_MS=%s\n' "$OLD_RC" "$OLD_MS" "$NEW_MS"
printf 'POPULATION_SPARSE_ANCIENNE_RC=%s WALL_MS=%s\nPOPULATION_SPARSE_NOUVELLE_WALL_MS=%s\n' "$SPARSE_OLD_RC" "$SPARSE_OLD_MS" "$SPARSE_NEW_MS"
printf 'Equivalence verifiee sur la fixture de reference ; aucun DEPENDENT dans le nouveau plan. Limite serveur toujours 1500 ms.\n'
printf 'Diagnostic local jetable : %s\n' "$WORK"
