#!/usr/bin/env bash
# After the isolated runtime/domain fixture only. Git remote and public commune API are local fixtures.
set -Eeuo pipefail
[ -f /.dockerenv ] && [ "${COFRAC2_RUNTIME_TEST:-}" = 1 ] && [ "$(hostname -s)" = ns365601 ] \
    && [ -f /etc/mysql/fixture.cnf ] && [ -f /tmp/runtime-ca.key ] || exit 1
APP=/var/www/cofrac2
STATE=/var/lib/cofrac2-production
PACKAGE=/home/ubuntu/cofrac-correctifs-launcher-fixture
git_app() { runuser -u cofrac2-deploy -- git -C "$APP" "$@"; }
sql() { mysql --defaults-file=/etc/mysql/fixture.cnf --batch --skip-column-names -e "$1"; }
trap 'printf "LAUNCHER_FIXTURE_FAILED_AT_LINE=%s\n" "$LINENO" >&2; tail -35 /tmp/correctifs-launcher-valid.log 2>/dev/null || true; find /var/lib/cofrac2-production -name "*.log" -path "*/release-*/*" | while read -r file; do echo "=== $file ==="; tail -8 "$file"; done >&2' ERR
# This disposable fixture proves deployment keeps both admins, including their hashes and permissions.
PRIMARY=$(sql 'SELECT id FROM cofrac2production.users WHERE admin=1 AND deleted_at IS NULL;')
[[ "$PRIMARY" =~ ^[0-9]+$ ]]
sql "INSERT INTO cofrac2production.users (id,first_name,last_name,email,password,role,admin,created_at,updated_at)
 VALUES (77777,'Second','Admin fixture','second-admin@example.invalid','fixture-no-login-hash',0,1,NOW(),NOW());"
runtime_check() { runuser -u cofrac2-app -- php8.4 "$APP/tools/deploy/production-runtime.php" --check; }
runtime_check > /tmp/correctifs-two-admins.json
cat "$APP/tools/deploy/production-runtime.php" | runuser -u cofrac2-app -- php8.4 -- --check > /tmp/correctifs-two-admins-stdin.json
cmp /tmp/correctifs-two-admins.json /tmp/correctifs-two-admins-stdin.json
php8.4 -r '$r=json_decode(file_get_contents($argv[1]),true,32,JSON_THROW_ON_ERROR);if($r["active_super_admins"]!==2||$r["single_super_admin"]!==false||$r["disabled_super_admins"]!==0)exit(1);' /tmp/correctifs-two-admins.json
for role in 1 2; do
    sql "UPDATE cofrac2production.users SET role=$role WHERE id=77777;"
    runtime_check > /tmp/correctifs-internal-role.json
    cmp /tmp/correctifs-two-admins.json /tmp/correctifs-internal-role.json
done
sql 'UPDATE cofrac2production.users SET role=0 WHERE id=77777;'
sql 'UPDATE cofrac2production.users SET deleted_at=NOW() WHERE id=77777;'
runtime_check > /tmp/correctifs-disabled-admin.json
php8.4 -r '$r=json_decode(file_get_contents($argv[1]),true,32,JSON_THROW_ON_ERROR);if($r["active_super_admins"]!==1||$r["disabled_super_admins"]!==1||$r["single_super_admin"]!==true)exit(1);' /tmp/correctifs-disabled-admin.json
sql "UPDATE cofrac2production.users SET admin=0 WHERE id=$PRIMARY;"
if runtime_check > /tmp/correctifs-no-admin.json 2>/tmp/correctifs-no-admin.err; then exit 1; fi
grep -q 'Au moins un super-admin interne actif' /tmp/correctifs-no-admin.err
sql "UPDATE cofrac2production.users SET admin=1 WHERE id=$PRIMARY; UPDATE cofrac2production.users SET deleted_at=NULL,role=3 WHERE id=77777;"
if runtime_check > /tmp/correctifs-external-admin.json 2>/tmp/correctifs-external-admin.err; then exit 1; fi
grep -q 'profil externe ou invalide' /tmp/correctifs-external-admin.err
sql 'UPDATE cofrac2production.users SET role=99 WHERE id=77777;'
if runtime_check > /tmp/correctifs-invalid-admin.json 2>/tmp/correctifs-invalid-admin.err; then exit 1; fi
grep -q 'profil externe ou invalide' /tmp/correctifs-invalid-admin.err
ORGANISATION=$(sql 'SELECT id FROM cofrac2production.external_delegataires ORDER BY id LIMIT 1;')
[[ "$ORGANISATION" =~ ^[0-9]+$ ]]
sql "UPDATE cofrac2production.users SET role=0,external_client_id=$ORGANISATION WHERE id=77777;"
if runtime_check > /tmp/correctifs-linked-admin.json 2>/tmp/correctifs-linked-admin.err; then exit 1; fi
grep -q 'profil externe ou invalide' /tmp/correctifs-linked-admin.err
sql 'UPDATE cofrac2production.users SET external_client_id=NULL WHERE id=77777;'
runtime_check > /tmp/correctifs-two-admins-restored.json
cmp /tmp/correctifs-two-admins.json /tmp/correctifs-two-admins-restored.json
BASE=$(git_app rev-parse HEAD)
ORIGIN=/tmp/correctifs-origin.git
WRITER=/tmp/correctifs-writer
runuser -u cofrac2-deploy -- git clone --bare "$APP" "$ORIGIN" >/dev/null 2>&1
runuser -u cofrac2-deploy -- git clone "$ORIGIN" "$WRITER" >/dev/null 2>&1
printf '\n/* Compiled frontend fixture. */\n' >> "$WRITER/resources/css/app.css"
php8.4 -r '$p=json_decode(file_get_contents($argv[1]),true,32,JSON_THROW_ON_ERROR);$p["description"]="Pinned launcher fixture";file_put_contents($argv[1],json_encode($p,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n");' "$WRITER/package.json"
printf '\n' >> "$WRITER/package-lock.json"
runuser -u cofrac2-deploy -- git -C "$WRITER" add resources/css/app.css package.json package-lock.json
runuser -u cofrac2-deploy -- git -C "$WRITER" -c user.name=Fixture -c user.email=fixture@example.invalid commit -m fixture-launcher >/dev/null
REV=$(runuser -u cofrac2-deploy -- git -C "$WRITER" rev-parse HEAD)
runuser -u cofrac2-deploy -- git -C "$WRITER" push origin v2 >/dev/null 2>&1
git_app remote add origin "$ORIGIN"
git_app fetch origin v2 >/dev/null 2>&1

# No external network is available: serve public reference fixtures with a CA trusted only in this container.
mkdir -m 0755 /tmp/correctifs-public-reference
cat > /tmp/correctifs-public-reference/communes.json <<'JSON'
[{"code":"01212","nom":"Lescheroux","codesPostaux":["01560"],"centre":{"type":"Point","coordinates":[5.13,46.4]}},{"code":"32055","nom":"Bivès","codesPostaux":["32380"],"centre":{"type":"Point","coordinates":[0.77,43.83]}}]
JSON
chmod 0644 /tmp/correctifs-public-reference/communes.json
openssl req -newkey rsa:2048 -nodes -subj /CN=geo.api.gouv.fr -keyout /tmp/correctifs-geo.key -out /tmp/correctifs-geo.csr >/dev/null 2>&1
printf 'subjectAltName=DNS:geo.api.gouv.fr\nbasicConstraints=CA:FALSE\nextendedKeyUsage=serverAuth\n' > /tmp/correctifs-geo.ext
openssl x509 -req -in /tmp/correctifs-geo.csr -CA /usr/local/share/ca-certificates/cofrac2-fixture.crt -CAkey /tmp/runtime-ca.key -CAcreateserial \
    -days 1 -extfile /tmp/correctifs-geo.ext -out /tmp/correctifs-geo.crt >/dev/null 2>&1
printf '127.0.0.1 geo.api.gouv.fr\n' >> /etc/hosts
cat > /etc/apache2/sites-available/correctifs-reference-fixture.conf <<'APACHE'
<VirtualHost *:443>
    ServerName geo.api.gouv.fr
    SSLEngine on
    SSLCertificateFile /tmp/correctifs-geo.crt
    SSLCertificateKeyFile /tmp/correctifs-geo.key
    Alias /communes /tmp/correctifs-public-reference/communes.json
    <Directory /tmp/correctifs-public-reference>
        Require all granted
    </Directory>
</VirtualHost>
APACHE
ln -s ../sites-available/correctifs-reference-fixture.conf /etc/apache2/sites-enabled/correctifs-reference-fixture.conf
/tmp/real-apache2ctl configtest >/dev/null
/tmp/real-apache2ctl graceful >/dev/null
sleep 1

install -d -m 0700 "$PACKAGE" "$PACKAGE/from-fixture"
cp -a "$APP/public/build" "$PACKAGE/from-fixture/build"
find "$PACKAGE" -type d -exec chmod 0700 {} +
find "$PACKAGE" -type f -exec chmod 0600 {} +
SHA=$(php8.4 -r 'require $argv[1]."/tools/deploy/lib/ProductionAssetUpdate.php";echo Cofrac2\Deploy\ProductionAssetUpdate::seal($argv[2],$argv[3],$argv[4]);' "$APP" "$PACKAGE/from-fixture" "$REV" "$BASE")
git_app diff --name-only "$BASE" "$REV" > "$PACKAGE/changes.txt"
php8.4 -r '
    $source=file_get_contents($argv[1]."/tools/deploy/templates/production-release-launcher.sh.in");
    $cases="case \"\$BASE\" in\n  ".$argv[4].")\n SOURCE=\"\$PACKAGE/from-fixture\"\n SHA=".$argv[5]."\n EXPECTED=\"\$PACKAGE/changes.txt\"\n EXPECTED_SHA=".hash_file("sha256",$argv[2]."/changes.txt")."\n ;;\n *) stop \"fixture revision mismatch\" ;;\nesac";
    $script=strtr($source,["@PACKAGE@"=>$argv[2],"@REVISION@"=>$argv[3],"@BASE_SELECTION@"=>$cases,"@RUNTIME_CHECK_SHA@"=>hash_file("sha256",$argv[1]."/tools/deploy/production-runtime.php")]);
    file_put_contents($argv[2]."/deploy.sh",$script);
' "$APP" "$PACKAGE" "$REV" "$BASE" "$SHA"
bash -n "$PACKAGE/deploy.sh"
sql 'SELECT id,email,password,role,admin,deleted_at,portal_enabled FROM cofrac2production.users ORDER BY id' > /tmp/correctifs-users.before
sha256sum "$APP/.env" /srv/cofrac2-runtime/documents.list0 > /tmp/correctifs-config.before
cp "$PACKAGE/changes.txt" /tmp/correctifs-changes.before
printf '.env\n' >> "$PACKAGE/changes.txt"
if bash "$PACKAGE/deploy.sh" > /tmp/correctifs-launcher-refused.log 2>&1; then exit 1; fi
test -f "$STATE/INTERNAL-SITE-OPEN.json"
test "$(git_app rev-parse HEAD)" = "$BASE"
cp /tmp/correctifs-changes.before "$PACKAGE/changes.txt"
bash "$PACKAGE/deploy.sh" > /tmp/correctifs-launcher-valid.log 2>&1
test "$(git_app rev-parse HEAD)" = "$REV"
test -f "$STATE/INTERNAL-SITE-OPEN.json"
test -f "$STATE/mymar-gateway-prepared/$REV/DISABLED.json"
php8.4 -r '$r=json_decode(file_get_contents($argv[1]),true,32,JSON_THROW_ON_ERROR);if($r["enabled"]||$r["configured"]||$r["services_changed"]||$r["source_database_read"]||$r["planning_changed"])exit(1);' "$STATE/mymar-gateway-prepared/$REV/DISABLED.json"
sql 'SELECT id,email,password,role,admin,deleted_at,portal_enabled FROM cofrac2production.users ORDER BY id' > /tmp/correctifs-users.after
cmp /tmp/correctifs-users.before /tmp/correctifs-users.after
sha256sum -c /tmp/correctifs-config.before
for host in cofrac.geniuscontrole.com cofrac2.geniuscontrole.com; do
    for route in /login /up; do
        test "$(curl --noproxy '*' -sS --resolve "$host:443:127.0.0.1" -o /dev/null -w '%{http_code}' "https://$host$route")" = 200
    done
done
sha256sum --status -c /tmp/runtime-original.sha256
printf 'OK : deux super-admins conserves, zero admin actif et admin externe/invalide refuses, lanceur epingle, deux domaines et MYMAR desactive ; comptes/configuration/source preserves.\n'
