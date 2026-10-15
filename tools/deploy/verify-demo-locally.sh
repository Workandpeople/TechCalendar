#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

PROJECT=$(cd "$(dirname "$0")/../.." && pwd)
PACKAGE=${1:?Usage: bash tools/deploy/verify-demo-locally.sh PACKAGE_DIRECTORY}
PACKAGE=$(cd "$PACKAGE" && pwd)
COLLECTION="$HOME/Downloads/coffrac-migration-20261014"
WORK=$(mktemp -d "${TMPDIR:-/tmp}/techcalendar-demo-verify-XXXXXXXX")
CONTAINER="techcalendar-demo-verify-$(date +%s)-$$"
CREATED=0
cleanup() {
    if [ "$CREATED" = 1 ]; then docker rm -fv "$CONTAINER" >/dev/null; fi
    rm -rf -- "$WORK"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

(cd "$PACKAGE" && shasum -a 256 -c SHA256SUMS)
docker image inspect mysql:8.0.46 >/dev/null
docker image inspect sail-8.5/app:latest >/dev/null

# No published port or network: this container cannot reach either production.
docker run -d --name "$CONTAINER" --network none --memory=1g --cpus=2 \
    -e MYSQL_ALLOW_EMPTY_PASSWORD=yes -e MYSQL_DATABASE=techcalendar_demo_verify_simulation \
    mysql:8.0.46 >/dev/null
CREATED=1
READY=0
for attempt in $(seq 1 90); do
    if docker exec "$CONTAINER" mysql --protocol=tcp -h127.0.0.1 -uroot \
        -N -e 'SELECT 1' techcalendar_demo_verify_simulation >/dev/null 2>&1; then
        READY=1
        break
    fi
    sleep 2
done
test "$READY" = 1 || { echo 'MySQL de verification indisponible.' >&2; exit 1; }

echo 'Restauration dans une base MySQL 8.0.46 jetable, reseau coupe...'
if ! gzip -dc "$PACKAGE/database-v2.sql.gz" | docker exec -i "$CONTAINER" \
    mysql --local-infile=0 -uroot techcalendar_demo_verify_simulation \
    > "$PACKAGE/restore-output.log" 2> "$PACKAGE/restore-errors.log"; then
    echo 'Restauration en echec. Diagnostic prive dans restore-errors.log.' >&2
    exit 1
fi

php -r '
$tables = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR)["tables"];
foreach ($tables as $table => $count) {
    if (!preg_match("/^[a-zA-Z0-9_]+$/", $table)) throw new RuntimeException("Table invalide");
    echo "SELECT ".chr(39).$table.chr(39).", COUNT(*) FROM `".$table."`;\n";
}' "$PACKAGE/metadata.json" | docker exec -i "$CONTAINER" mysql -N -B -uroot \
    techcalendar_demo_verify_simulation > "$PACKAGE/restored-counts.tsv"

php -r '
$expected = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR)["tables"];
$volatile = ["sessions", "cache", "cache_locks"];
$verified = 0;
foreach (file($argv[2], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    [$table, $count] = explode("\t", $line);
    if (!array_key_exists($table, $expected)) throw new RuntimeException("Table inattendue");
    if (!in_array($table, $volatile, true) && $expected[$table] !== (int)$count) {
        throw new RuntimeException("Comptage different : ".$table);
    }
    $verified++;
}
if ($verified !== count($expected)) throw new RuntimeException("Controle incomplet");
echo "Tables verifiees : ".$verified." ; dossiers : ".$expected["dossiers"]."\n";
' "$PACKAGE/metadata.json" "$PACKAGE/restored-counts.tsv"

printf 'Restauration et comptages MySQL 8.0.46 reussis le %s.\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" \
    > "$PACKAGE/RESTORE-VERIFIED.txt"
(cd "$PACKAGE" && shasum -a 256 database-v2.sql.gz >> RESTORE-VERIFIED.txt)

# Rehearse the post-restore cleanup ONLY inside the isolated disposable database.
docker exec -i "$CONTAINER" mysql -uroot techcalendar_demo_verify_simulation <<'SQL'
DELETE FROM jobs;
DELETE FROM failed_jobs;
DELETE FROM job_batches;
DELETE FROM sessions;
DELETE FROM cache;
DELETE FROM cache_locks;
DELETE FROM password_reset_tokens;
DELETE FROM mobile_access_tokens;
DELETE FROM mobile_push_tokens;
UPDATE users SET remember_token = NULL;
SQL

mkdir -p "$WORK/app/storage/app" "$WORK/app/storage/framework/"{cache,views,sessions} "$WORK/app/storage/logs"
tar -xzf "$PACKAGE/code-v2.tar.gz" -C "$WORK/app"
tar -xzf "$PACKAGE/runtime-files.tar.gz" -C "$WORK/app/storage/app"
mkdir -p "$WORK/app/storage/app/private" "$WORK/app/bootstrap/cache"

# docker --env-file expects raw values, not dotenv quoting. Never print the secrets.
php -r '
require $argv[1]."/vendor/autoload.php";
$values = Dotenv\Dotenv::parse(file_get_contents($argv[1]."/tools/deploy/demo.env.example")."\n".file_get_contents($argv[2]."/demo-secrets.env"));
$values = array_replace($values, ["DB_DATABASE" => "techcalendar_demo_verify_simulation", "DB_USERNAME" => "root", "DB_PASSWORD" => "", "CACHE_STORE" => "array"]);
foreach ($values as $key => $value) {
    if (preg_match("/[\r\n]/", $value)) throw new RuntimeException("Valeur multiligne interdite");
    echo $key."=".$value."\n";
}' "$PROJECT" "$PACKAGE" > "$WORK/verify.env"

docker run --rm --entrypoint php --network "container:$CONTAINER" --env-file "$WORK/verify.env" \
    -v "$WORK/app:/var/www/html" -v "$PROJECT/vendor:/var/www/html/vendor:ro" \
    -v "$COLLECTION/fichiers/public/documents:/srv/techcalendar-v2-demo/documents:ro" \
    -v "$COLLECTION/selection-non-factures-20261014:/srv/techcalendar-v2-demo/selection:ro" \
    -w /var/www/html sail-8.5/app:latest artisan demo:check \
    | tee "$PACKAGE/preflight-local.log"

printf 'Preflight de configuration PHP 8.5 reussi le %s.\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" \
    | tee "$PACKAGE/PREFLIGHT-VERIFIED.txt"
(cd "$PACKAGE" && shasum -a 256 database-v2.sql.gz code-v2.tar.gz demo-secrets.env >> PREFLIGHT-VERIFIED.txt)
echo 'Le conteneur de verification et ses donnees vont etre supprimes. Sources inchangees.'
