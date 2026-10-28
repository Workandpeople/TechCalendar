#!/usr/bin/env bash
# Switch only the renderer. Never restore SQL, replay jobs or touch shared services.
set +x
set -Eeuo pipefail
umask 077
export PATH=/usr/sbin:/usr/bin:/sbin:/bin
stop() { printf 'STOP : %s\n' "$1" >&2; exit 1; }
[ "${EUID:-$(id -u)}" = 0 ] && [ "$(hostname -s)" = ns365601 ] && [ "$#" = 1 ] \
    && { [ "$1" = native ] || [ "$1" = fullcalendar ]; } || stop 'Usage : sudo bash set-production-calendar.sh native|fullcalendar'
ENGINE=$1
APP=/var/www/cofrac2
STATE=/var/lib/cofrac2-production
HERE=$(cd -- "$(dirname -- "$0")" && pwd -P)
[ "$HERE" = "$APP/tools/deploy" ] && [ "$(realpath "$APP")" = "$APP" ] \
    && [ ! -L "$STATE" ] && [ "$(stat -c %u:%a "$STATE")" = 0:700 ] || stop 'chemins V2 inattendus.'
exec 9>"$STATE/runtime-operation.lock"
flock -n 9 || stop 'autre operation runtime en cours ; site non modifie.'
git_app() { runuser -u cofrac2-deploy -- env HOME=/home/cofrac2-deploy git -C "$APP" "$@"; }
app_cli() { runuser -u cofrac2-app -- env -i PATH="$PATH" HOME=/var/lib/cofrac2-app USER=cofrac2-app LOGNAME=cofrac2-app php8.4 "$@"; }
[ -z "$(git_app status --porcelain)" ] && [ "$(git_app branch --show-current)" = v2 ] || stop 'checkout V2 propre requis.'
[ -f "$STATE/INTERNAL-SITE-OPEN.json" ] && [ ! -L "$STATE/INTERNAL-SITE-OPEN.json" ] || stop 'site ouvert attendu.'
systemctl is-active --quiet cofrac2-php-fpm.service || stop 'PHP V2 inactif.'
[ ! -L "$APP/.env" ] && [ "$(stat -c %U:%G:%a "$APP/.env")" = root:cofrac2-app:640 ] \
    && [ ! -L "$STATE/RUNTIME-PREPARED.json" ] && [ "$(stat -c %u:%a "$STATE/RUNTIME-PREPARED.json")" = 0:600 ] \
    && [ -f "$APP/bootstrap/cache/config.php" ] && [ ! -L "$APP/bootstrap/cache/config.php" ] || stop 'configuration privee attendue.'
REV=$(git_app rev-parse HEAD)
WORK=$(mktemp -d "$STATE/calendar-switch-XXXXXXXX")
changed=0
success=0
cleanup() {
    result=$?
    trap - EXIT
    if [ "$success" != 1 ] && [ "$changed" = 1 ]; then
        install -o root -g cofrac2-app -m 0640 "$WORK/env.before" "$APP/.env"
        cp -p "$WORK/config.before.php" "$APP/bootstrap/cache/config.php"
        install -o root -g root -m 0600 "$WORK/prepared.before.json" "$STATE/RUNTIME-PREPARED.json"
        systemctl reload cofrac2-php-fpm.service || true
        printf 'Bascule annulee : configuration precedente restauree.\n' >&2
    fi
    [ "$result" = 0 ] || printf 'Diagnostic prive : %s\n' "$WORK" >&2
    exit "$result"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
php8.4 -r '
    require $argv[1]."/vendor/autoload.php";
    require $argv[1]."/tools/deploy/lib/ProductionCalendar.php";
    $marker=$argv[2]."/RUNTIME-PREPARED.json";
    $prepared=json_decode(file_get_contents($marker),true,64,JSON_THROW_ON_ERROR);
    $env=file_get_contents($argv[1]."/.env");
    if (($prepared["revision"]??null)!==$argv[5] || !hash_equals($prepared["env_sha256"]??"",hash("sha256",$env))) exit(1);
    $next=Cofrac2\Deploy\ProductionCalendar::environment($env,$argv[4]);
    if ($argv[4]==="native") {
        $assets=json_decode(file_get_contents($argv[1]."/public/build/manifest.json"),true,64,JSON_THROW_ON_ERROR);
        $file=$assets["resources/js/native-calendar-entry.js"]["file"]??"";
        if (!preg_match("#^assets/[A-Za-z0-9_-]+\\.js$#D",$file) || !is_file($argv[1]."/public/build/".$file)) exit(1);
    }
    $before=Dotenv\Dotenv::parse($env); $after=Dotenv\Dotenv::parse($next);
    unset($before["COFRAC_CALENDAR_ENGINE"],$after["COFRAC_CALENDAR_ENGINE"]);
    if ($before!==$after) exit(1);
    $prepared["env_sha256"]=hash("sha256",$next);
    $prepared["calendar_engine"]=$argv[4];
    file_put_contents($argv[3]."/env.next",$next);
    file_put_contents($argv[3]."/prepared.next.json",json_encode($prepared,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n");
' "$APP" "$STATE" "$WORK" "$ENGINE" "$REV" || stop 'revision, empreinte ou configuration inattendue ; aucun changement.'
app_cli "$HERE/production-runtime.php" --check > "$WORK/runtime.before.json"
cp -p "$APP/.env" "$WORK/env.before"
cp -p "$STATE/RUNTIME-PREPARED.json" "$WORK/prepared.before.json"
cp -p "$APP/bootstrap/cache/config.php" "$WORK/config.before.php"
changed=1
install -o root -g cofrac2-app -m 0640 "$WORK/env.next" "$APP/.env"
app_cli "$APP/artisan" config:cache --no-interaction --no-ansi > "$WORK/config.log" 2>&1
install -o root -g root -m 0600 "$WORK/prepared.next.json" "$STATE/RUNTIME-PREPARED.json"
app_cli -r '
    chdir("/var/www/cofrac2"); require "vendor/autoload.php";
    $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if(config("calendar.engine")!==$argv[1])exit(1);
' "$ENGINE" || stop 'cache calendrier non confirme.'
app_cli "$HERE/production-runtime.php" --check > "$WORK/runtime.after.json"
systemctl reload cofrac2-php-fpm.service
for domain in cofrac.geniuscontrole.com cofrac2.geniuscontrole.com; do
    curl --noproxy '*' --fail --silent --show-error --max-time 30 --retry 4 --retry-delay 1 --retry-connrefused \
        --resolve "$domain:443:127.0.0.1" -o /dev/null "https://$domain/login"
done
success=1
printf 'Calendrier actif : %s. Actualiser les onglets ouverts.\n' "$ENGINE"
printf 'Aucune maintenance, restauration SQL, modification SMTP/SMS, reprise de job ou action sur un service partage.\n'
