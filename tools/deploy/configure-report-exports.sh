#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
export PATH=/usr/sbin:/usr/bin:/sbin:/bin
stop() { printf 'STOP : %s\n' "$1" >&2; exit 1; }
[ "${EUID:-$(id -u)}" = 0 ] && [ "$(hostname -s)" = ns365601 ] && [ "${1:-}" = --install ] && [ "$#" = 1 ] \
    || stop 'Usage : sudo bash /var/www/cofrac2/tools/deploy/configure-report-exports.sh --install'
APP=/var/www/cofrac2
STATE=/var/lib/cofrac2-production
UNIT=cofrac2-report-exports.service
HERE=$(cd -- "$(dirname -- "$0")" && pwd -P)
[ "$HERE" = "$APP/tools/deploy" ] && [ "$(realpath "$APP")" = "$APP" ] || stop 'checkout V2 attendu.'
[ -d "$STATE" ] && [ ! -L "$STATE" ] && [ "$(stat -c %u:%a "$STATE")" = 0:700 ] || stop 'etat prive inattendu.'
exec 9>"$STATE/runtime-operation.lock"
flock -n 9 || stop 'operation runtime deja en cours.'
git_app() { runuser -u cofrac2-deploy -- git -C "$APP" "$@"; }
[ "$(git_app branch --show-current)" = v2 ] && [ -z "$(git_app status --porcelain)" ] || stop 'checkout V2 propre requis.'
[ -f "$STATE/INTERNAL-SITE-OPEN.json" ] && [ ! -L "$STATE/INTERNAL-SITE-OPEN.json" ] || stop 'rouvrir la V2 avant cette installation.'
REVISION=$(git_app rev-parse HEAD)
php8.4 -r '
$p=json_decode(file_get_contents($argv[1]),true,32,JSON_THROW_ON_ERROR);
exit(($p["revision"]??null)===$argv[2]?0:1);
' "$STATE/RUNTIME-PREPARED.json" "$REVISION" || stop 'revision applicative non preparee.'
runuser -u cofrac2-app -- env -i PATH="$PATH" HOME=/var/lib/cofrac2-app php8.4 -r '
chdir($argv[1]);require "vendor/autoload.php";$app=require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!app()->environment("production") || !config("crm.v2_enabled") || !class_exists(ZipArchive::class)
    || config("queue.default")!=="database" || Illuminate\Support\Facades\DB::connection()->getDatabaseName()!=="cofrac2production"
    || !Illuminate\Support\Facades\Schema::hasTable("lot_report_exports")) exit(1);
echo "Configuration et schema des archives prives verifies.\n";
' "$APP" || stop 'configuration, extension ZIP ou migration absente.'
TARGET="/etc/systemd/system/$UNIT"
[ ! -L "$TARGET" ] || stop 'unite symbolique refusee.'
if [ -e "$TARGET" ]; then cmp -s "$TARGET" "$HERE/$UNIT.example" || stop 'unite existante differente ; examiner avant modification.'; fi
install -o root -g root -m 0644 "$HERE/$UNIT.example" "$TARGET"
systemd-analyze verify "$TARGET"
systemctl daemon-reload
systemctl enable --now "$UNIT" 9>&-
sleep 2
systemctl is-active --quiet "$UNIT" || stop 'worker archives inactif ; consulter son journal prive.'
printf 'Worker archives actif, file report-exports isolee des emails. Aucun ZIP, mail, SMS ou appel MYMAR de test.\n'
