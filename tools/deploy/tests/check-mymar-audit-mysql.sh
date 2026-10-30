#!/usr/bin/env bash
# Dedicated disposable Docker container only. No host mounts, ports or production access.
set -Eeuo pipefail
BASE=$(cd "$(dirname "$0")/.." && pwd -P)
CONTAINER=cofrac-mymar-audit-fixture
[ "${MYMAR_AUDIT_TEST:-}" = 1 ] || { echo 'MYMAR_AUDIT_TEST=1 requis, conteneur jetable uniquement.' >&2; exit 1; }
[ "$(docker inspect "$CONTAINER" --format '{{.Config.Image}} {{.HostConfig.NetworkMode}}')" = 'mysql:8.0.46 none' ]
[ "$(docker inspect "$CONTAINER" --format '{{len .Mounts}}')" = 1 ] # Only the image's anonymous /var/lib/mysql volume.
[ "$(docker inspect "$CONTAINER" --format '{{(index .Mounts 0).Type}}')" = volume ]
[ "$(docker inspect "$CONTAINER" --format '{{(index .Mounts 0).Destination}}')" = /var/lib/mysql ]
[ -z "$(docker exec "$CONTAINER" mysql -NBe "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME IN ('MYMAR','cofrac2production')")" ]
WORK=$(mktemp -d /tmp/mymar-audit-mysql-test-XXXXXXXX)
docker exec -i "$CONTAINER" mysql < "$BASE/tests/mymar-audit-fixture.sql"
snapshot() {
    docker exec "$CONTAINER" mysql -NBe 'CHECKSUM TABLE MYMAR.status,MYMAR.roles,MYMAR.model_has_roles,MYMAR.users,MYMAR.dossiers,MYMAR.dossier_rdv,cofrac2production.users,cofrac2production.coffrac_user_links;'
}
snapshot > "$WORK/before"
for mode in metadata data explain; do
    php -r 'require $argv[1]; echo Cofrac2\Deploy\MymarReadOnlyAudit::{$argv[2]."Sql"}();' "$BASE/mymar/MymarReadOnlyAudit.php" "$mode" > "$WORK/$mode.sql"
    docker exec -i "$CONTAINER" mysql --user=audit_reader --default-character-set=utf8mb4 --batch --raw --skip-column-names < "$WORK/$mode.sql" > "$WORK/$mode.out"
done
snapshot > "$WORK/after"
cmp "$WORK/before" "$WORK/after"
php -r '
require $argv[1];
$read = fn ($path) => array_map(fn ($line) => json_decode($line,true,32,JSON_THROW_ON_ERROR), file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
Cofrac2\Deploy\MymarReadOnlyAudit::validateMetadata($read($argv[2]."/metadata.out"));
$rows=$read($argv[2]."/data.out"); $sections=[]; foreach($rows as $r) $sections[$r["section"]][]=$r;
if((int)$sections["clock"][0]["transaction_read_only"]!==1 || count($sections["technician"])!==3) exit(1);
if(array_sum(array_column($sections["rdv_group"],"rdvs"))!==5) exit(1);
$tech=array_column($sections["technician"],null,"mymar_id");
if($tech[1]["historical_v2_id"]!==101 || $tech[1]["historical_email_matches"]!==1 || $tech[1]["historical_source_duplicates"]!==2) exit(1);
if($tech[2]["email_candidates"]!==2 || $tech[2]["unique_email_v2_id"]!==null || $tech[3]["historical_email_matches"]!==0) exit(1);
if(preg_match("/CANARY|@example|Non technicien/",file_get_contents($argv[2]."/data.out"))) exit(1);
echo "MySQL 8.0.46 : SELECT seul, source/V2 inchanges, tous les RDV comptes, ambiguites identite conservees, aucun secret exporte.".PHP_EOL;
' "$BASE/mymar/MymarReadOnlyAudit.php" "$WORK"
printf 'Diagnostic local jetable : %s\n' "$WORK"
