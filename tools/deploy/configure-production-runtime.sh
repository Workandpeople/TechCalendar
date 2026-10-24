#!/usr/bin/env bash

# Only Cofrac 2 runtime/configuration. Never stop or restart shared PHP, SQL or original app services.
set +x
set -Eeuo pipefail
umask 077
export LC_ALL=C
export PATH=/usr/sbin:/usr/bin:/sbin:/bin
stop() { printf 'STOP : %s\n' "$1" >&2; exit 1; }
[ "${EUID:-$(id -u)}" = 0 ] && [ "$(hostname -s)" = ns365601 ] || stop 'root sur ns365601 uniquement.'
case "${1:-}:$#" in
    --prepare:3) ;;
    --open:2|--maintenance:2) [ "$2" = --reload-shared-apache ] || stop 'consentement au rechargement gracieux Apache requis.' ;;
    *) stop 'Usage : --prepare BUNDLE SHA256 | --open|--maintenance --reload-shared-apache' ;;
esac
ACTION=$1
APP=/var/www/cofrac2
STATE=/var/lib/cofrac2-production
RUNTIME=cofrac2-app
DOMAIN=cofrac2.geniuscontrole.com
SITE="/etc/apache2/sites-available/$DOMAIN.conf"
MODULES=/etc/apache2/conf-enabled/cofrac2-runtime-modules.conf
HERE=$(cd -- "$(dirname -- "$0")" && pwd -P)
[ "$HERE" = "$APP/tools/deploy" ] && [ "$(realpath "$APP")" = "$APP" ] || stop 'checkout V2 attendu.'
[ -d "$STATE" ] && [ ! -L "$STATE" ] && [ "$(stat -c %u:%a "$STATE")" = 0:700 ] || stop 'etat prive inattendu.'
[ -f "$APP/.env" ] && [ ! -L "$APP/.env" ] && [ "$(stat -c %U:%G:%a "$APP/.env")" = root:cofrac2-app:640 ] \
    || stop 'configuration V2 privee et protegee par root attendue.'
for command in php8.4 php-fpm8.4 runuser systemctl systemd-analyze apache2ctl curl sha256sum flock install cmp ss git; do
    command -v "$command" >/dev/null || stop "outil manquant : $command"
done
exec 9>"$STATE/runtime-operation.lock"
flock -n 9 || stop 'operation runtime deja en cours.'
systemctl is-active --quiet apache2.service && systemctl is-active --quiet mysql.service || stop 'Apache/MySQL doivent deja etre actifs.'
apache2ctl configtest
[ "$(realpath "/etc/apache2/sites-enabled/$DOMAIN.conf")" = "$SITE" ] && [ ! -L "$SITE" ] || stop 'vhost V2 dedie attendu.'
git_app() { runuser -u cofrac2-deploy -- git -C "$APP" "$@"; }
[ "$(git_app branch --show-current)" = v2 ] && [ -z "$(git_app status --porcelain)" ] || stop 'checkout V2 propre requis.'
REVISION=$(git_app rev-parse HEAD)
UNITS=(cofrac2-php-fpm.service cofrac2-queue.service cofrac2-reverb.service)
app_cli() {
    runuser -u "$RUNTIME" -- env -i PATH="$PATH" HOME=/var/lib/cofrac2-app USER="$RUNTIME" LOGNAME="$RUNTIME" \
        php8.4 -d memory_limit=768M "$@"
}
snapshot_services() {
    systemctl show apache2.service mysql.service php7.3-fpm.service php7.4-fpm.service php8.3-fpm.service php8.4-fpm.service \
        --property=Id,ActiveState,MainPID,ExecMainStartTimestampMonotonic
}
snapshot_configs() {
    find -L /etc/apache2/sites-enabled /etc/apache2/conf-enabled /etc/apache2/mods-enabled -type f \
        ! -name "$DOMAIN.conf" ! -name cofrac2-runtime-modules.conf -print0 | sort -z | xargs -0 -r sha256sum
}
WORK=$(mktemp -d "$STATE/runtime-$(date -u +%Y%m%dT%H%M%SZ)-XXXXXXXX")
printf 'Diagnostic prive : %s\n' "$WORK"
snapshot_services > "$WORK/services.before"
snapshot_configs > "$WORK/apache.before"
success=0
site_written=0
module_written=0
module_removed=0
reload_attempted=0
services_started=0
env_written=0
cleanup() {
    result=$?
    trap - EXIT
    if [ "$success" != 1 ]; then
        if [ "$site_written" = 1 ]; then install -o root -g root -m 0644 "$WORK/site.before" "$SITE"; fi
        if [ "$module_written" = 1 ]; then rm -f -- "$MODULES"; fi
        if [ "$module_removed" = 1 ]; then install -o root -g root -m 0644 "$WORK/modules.before" "$MODULES"; fi
        if [ "$reload_attempted" = 1 ]; then
            apache2ctl configtest && systemctl reload apache2.service || printf 'ATTENTION : verifier le retour du seul vhost V2.\n' >&2
        fi
        if [ "$services_started" = 1 ]; then systemctl stop "${UNITS[@]}" || true; fi
        if [ "$env_written" = 1 ] && [ "$ACTION" = --prepare ]; then
            install -o root -g "$RUNTIME" -m 0640 "$WORK/env.before" "$APP/.env"
            app_cli "$APP/artisan" config:clear > "$WORK/config-clear.log" 2>&1 || true
        fi
        printf 'Operation non validee. Ne pas reinitialiser la base. Diagnostic prive : %s\n' "$WORK" >&2
    fi
    exit "$result"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

if [ "$ACTION" = --prepare ]; then
    [ -f "$STATE/STAGING-IMPORTED.json" ] && [ ! -L "$STATE/STAGING-IMPORTED.json" ] || stop 'import V2 non valide.'
    [ ! -e "$STATE/RUNTIME-PREPARED.json" ] || stop 'runtime deja prepare ; ne pas le reinstaller.'
    cmp -s "$SITE" "$HERE/apache-production-https.conf.example" || stop 'le site doit encore etre en maintenance HTTPS statique.'
    for unit in "${UNITS[@]}"; do
        ! systemctl is-active --quiet "$unit" || stop 'service V2 deja actif ; preparation initiale refusee.'
    done
    [ -d "$2" ] && [ ! -L "$2" ] && [ "$(realpath "$2")" = "$2" ] && [[ "$3" =~ ^[a-f0-9]{64}$ ]] || stop 'paquet runtime inattendu.'
    install -d -m 0700 "$WORK/bundle"
    cp -a --no-preserve=ownership "$2/." "$WORK/bundle/"
    [ -z "$(find "$WORK/bundle" ! -type d ! -type f -print -quit)" ] || stop 'paquet contenant un lien ou fichier special.'
    find "$WORK/bundle" -type d -exec chmod 0700 {} +
    find "$WORK/bundle" -type f -exec chmod 0600 {} +
    php8.4 -r '
        try {
            require $argv[1]."/vendor/autoload.php";require $argv[1]."/tools/deploy/lib/ProductionRuntimeBundle.php";
            $p=new Cofrac2\Deploy\ProductionRuntimeBundle($argv[2],$argv[3]);if($p->manifest["revision"]!==$argv[4])exit(1);
        } catch (Throwable) {fwrite(STDERR,"Paquet runtime non valide. Aucun secret affiche.\n");exit(1);}
    ' "$APP" "$WORK/bundle" "$3" "$REVISION" || stop 'paquet non valide ou revision differente.'
    [ -z "$(ss -H -ltn 'sport = :8091')" ] || stop 'port loopback Reverb 8091 deja utilise.'
    modules=$(apache2ctl -M 2>/dev/null)
    for module in proxy proxy_fcgi rewrite ssl; do grep -q " ${module}_module " <<< "$modules" || stop "module deja requis absent : $module"; done
    : > "$WORK/modules.conf"
    for module in headers proxy_http; do
        if ! grep -q " ${module}_module " <<< "$modules"; then
            [ -f "/usr/lib/apache2/modules/mod_$module.so" ] || stop "module Apache installe manquant : $module"
            printf 'LoadModule %s_module /usr/lib/apache2/modules/mod_%s.so\n' "$module" "$module" >> "$WORK/modules.conf"
        fi
    done
    [ ! -e "$MODULES" ] && [ ! -L "$MODULES" ] || stop 'configuration de modules V2 deja presente.'
    if [ -e /etc/cofrac2 ] || [ -L /etc/cofrac2 ]; then
        [ -d /etc/cofrac2 ] && [ ! -L /etc/cofrac2 ] && [ "$(stat -c %u:%a /etc/cofrac2)" = 0:700 ] || stop 'configuration PHP dediee inattendue.'
    else install -d -o root -g root -m 0700 /etc/cofrac2; fi
    for name in php-fpm queue reverb; do
        target="/etc/systemd/system/cofrac2-$name.service"
        if [ -e "$target" ] || [ -L "$target" ]; then cmp -s "$target" "$HERE/cofrac2-$name.service.example" || stop "unite deja presente : $target"; fi
        install -o root -g root -m 0644 "$HERE/cofrac2-$name.service.example" "$target"
    done
    if [ -e /etc/cofrac2/php-fpm.conf ] || [ -L /etc/cofrac2/php-fpm.conf ]; then
        cmp -s /etc/cofrac2/php-fpm.conf "$HERE/cofrac2-php-fpm.conf.example" || stop 'configuration PHP deja presente.'
    fi
    install -o root -g root -m 0600 "$HERE/cofrac2-php-fpm.conf.example" /etc/cofrac2/php-fpm.conf
    systemd-analyze verify /etc/systemd/system/cofrac2-{php-fpm,queue,reverb}.service
    php-fpm8.4 --test --fpm-config /etc/cofrac2/php-fpm.conf
    for directory in app config routes resources tools; do
        find "$APP/$directory" -type d -exec chgrp "$RUNTIME" {} + -exec chmod 2750 {} +
        find "$APP/$directory" -type f -exec chgrp "$RUNTIME" {} + -exec chmod u+rw,g=r,o= {} +
    done
    cp -p "$APP/.env" "$WORK/env.before"
    env_written=1
    php8.4 -d memory_limit=768M "$HERE/production-runtime.php" --prepare "$WORK/bundle" "$3" "$WORK/prepared.json"
    cd "$APP"
    for command in package:discover config:cache route:cache view:cache; do
        app_cli artisan "$command" --no-interaction --no-ansi >> "$WORK/laravel-cache.log" 2>&1 || stop "cache V2 en echec : $command (journal prive)."
    done
    app_cli "$HERE/production-runtime.php" --check > "$WORK/application-check.json"
    systemctl daemon-reload
    snapshot_services > "$WORK/services.after"
    snapshot_configs > "$WORK/apache.after"
    cmp -s "$WORK/services.before" "$WORK/services.after" && cmp -s "$WORK/apache.before" "$WORK/apache.after" || stop 'etat partage modifie pendant la preparation.'
    php8.4 -r '$r=json_decode(file_get_contents($argv[1]."/prepared.json"),true,32,JSON_THROW_ON_ERROR);$r["work_directory"]=$argv[1];file_put_contents($argv[2],json_encode($r,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n");' "$WORK" "$STATE/RUNTIME-PREPARED.json"
    success=1
    cat "$WORK/application-check.json"
    printf 'Runtime prepare. Aucun service demarre, aucun rechargement Apache, site encore ferme.\n'
    if [ -s "$WORK/modules.conf" ]; then printf 'A l ouverture seulement, ajout des modules dans un fichier dedie V2 :\n'; cat "$WORK/modules.conf"; fi
    exit 0
fi

if [ "$ACTION" = --maintenance ]; then
    cmp -s "$SITE" "$HERE/apache-production-app.conf.example" || stop 'vhost applicatif V2 inattendu.'
    if [ -e "$MODULES" ]; then
        prepared_path=$(php8.4 -r '$r=json_decode(file_get_contents($argv[1]),true,32,JSON_THROW_ON_ERROR);if(!preg_match("#^/var/lib/cofrac2-production/runtime-[A-Za-z0-9_-]+$#D",$r["work_directory"]))exit(1);echo $r["work_directory"];' "$STATE/RUNTIME-PREPARED.json")
        cmp -s "$MODULES" "$prepared_path/modules.conf" || stop 'modules V2 modifies ; ne pas supprimer.'
        cp -p "$MODULES" "$WORK/modules.before"
        rm -- "$MODULES"
        module_removed=1
    fi
    cp -p "$SITE" "$WORK/site.before"
    install -o root -g root -m 0644 "$HERE/apache-production-https.conf.example" "$SITE"
    site_written=1
    apache2ctl configtest
    reload_attempted=1
    systemctl reload apache2.service
    systemctl disable --now "${UNITS[@]}"
    sha256sum "$SITE" > "$STATE/apache-config.sha256"
    if [ -f "$STATE/INTERNAL-SITE-OPEN.json" ]; then mv -- "$STATE/INTERNAL-SITE-OPEN.json" "$WORK/open.before.json"; fi
    success=1
    printf 'Seul cofrac2 remis en maintenance statique. Donnees V2 conservees, ancien Coffrac inchange.\n'
    exit 0
fi

[ -f "$STATE/RUNTIME-PREPARED.json" ] && [ ! -L "$STATE/RUNTIME-PREPARED.json" ] || stop 'runtime non prepare.'
[ ! -e "$STATE/INTERNAL-SITE-OPEN.json" ] || stop 'ouverture deja validee.'
readarray -t prepared < <(php8.4 -r '
    $r=json_decode(file_get_contents($argv[1]),true,32,JSON_THROW_ON_ERROR);
    if($r["revision"]!==$argv[2]||!hash_equals($r["env_sha256"],hash_file("sha256","/var/www/cofrac2/.env"))||!preg_match("#^/var/lib/cofrac2-production/runtime-[A-Za-z0-9_-]+$#D",$r["work_directory"]))exit(1);
    echo $r["work_directory"]."\n".$r["asset_probe"]."\n";
' "$STATE/RUNTIME-PREPARED.json" "$REVISION")
[ "${#prepared[@]}" = 2 ] || stop 'revision ou configuration differente depuis la preparation.'
PREPARED=${prepared[0]}
ASSET=${prepared[1]}
[[ "$ASSET" =~ ^/build/assets/[A-Za-z0-9_-]+\.css$ ]] || stop 'asset de controle inattendu.'
cmp -s "$SITE" "$HERE/apache-production-https.conf.example" || stop 'maintenance HTTPS statique attendue avant ouverture.'
for name in php-fpm queue reverb; do
    cmp -s "/etc/systemd/system/cofrac2-$name.service" "$HERE/cofrac2-$name.service.example" || stop 'unite V2 modifiee.'
    ! systemctl is-active --quiet "cofrac2-$name.service" || stop 'service V2 deja actif, examiner avant nouvelle ouverture.'
done
cmp -s /etc/cofrac2/php-fpm.conf "$HERE/cofrac2-php-fpm.conf.example" || stop 'configuration PHP dediee modifiee.'
[ -z "$(ss -H -ltn 'sport = :8091')" ] || stop 'port Reverb deja utilise.'
app_cli "$HERE/production-runtime.php" --check > "$WORK/application-check.json"
services_started=1
systemctl start "${UNITS[@]}" 9>&-
for attempt in {1..20}; do
    if [ -S /run/cofrac2/php-fpm.sock ] && php8.4 "$HERE/production-runtime.php" --websocket-direct > "$WORK/reverb-direct.log" 2>&1; then break; fi
    sleep 1
done
test -S /run/cofrac2/php-fpm.sock
php8.4 "$HERE/production-runtime.php" --websocket-direct
app_cli "$HERE/production-runtime.php" --queue-probe
cp -p "$SITE" "$WORK/site.before"
if [ -s "$PREPARED/modules.conf" ]; then
    [ ! -e "$MODULES" ] && [ ! -L "$MODULES" ] || stop 'fichier de modules V2 deja present.'
    install -o root -g root -m 0644 "$PREPARED/modules.conf" "$MODULES"
    module_written=1
fi
install -o root -g root -m 0644 "$HERE/apache-production-app.conf.example" "$SITE"
site_written=1
apache2ctl configtest
reload_attempted=1
systemctl reload apache2.service
for path in /login /up "$ASSET"; do
    code=$(curl --noproxy '*' --silent --show-error --max-time 30 --resolve "$DOMAIN:443:127.0.0.1" \
        -D "$WORK/http.headers" -o "$WORK/http.body" -w '%{http_code}' "https://$DOMAIN$path")
    [ "$code" = 200 ] && grep -qi '^X-Cofrac2-Release: v2-internal' "$WORK/http.headers" || stop "controle HTTPS echoue : $path HTTP $code"
    printf '%s : HTTPS 200.\n' "$path"
done
code=$(curl --noproxy '*' --silent --show-error --max-time 30 --resolve "$DOMAIN:443:127.0.0.1" \
    -o /dev/null -w '%{http_code}' "https://$DOMAIN/v2/dossiers")
[ "$code" = 302 ] || stop 'authentification obligatoire non prouvee.'
for path in /.env /.git/config /phpmyadmin /storage/anything.php; do
    code=$(curl --noproxy '*' --silent --show-error --max-time 30 --resolve "$DOMAIN:443:127.0.0.1" \
        -o /dev/null -w '%{http_code}' "https://$DOMAIN$path")
    [[ "$code" = 403 || "$code" = 404 ]] || stop 'controle de refus HTTP echoue.'
done
php8.4 "$HERE/production-runtime.php" --websocket
snapshot_services > "$WORK/services.after"
snapshot_configs > "$WORK/apache.after"
cmp -s "$WORK/services.before" "$WORK/services.after" && cmp -s "$WORK/apache.before" "$WORK/apache.after" || stop 'configuration ou processus partage different.'
systemctl enable "${UNITS[@]}"
sha256sum "$SITE" > "$STATE/apache-config.sha256"
printf '{"url":"https://cofrac2.geniuscontrole.com","internal_only":true,"outbound_delivery_enabled":false,"final_cutover_ready":false}\n' > "$STATE/INTERNAL-SITE-OPEN.json"
success=1
printf 'Cofrac 2 ouvert pour recette interne : https://cofrac2.geniuscontrole.com\n'
printf 'PHP, worker et Reverb dedies verifies. Ancien Coffrac et services existants inchanges.\n'
printf 'Envois reels et portails externes toujours bloques. Verifier maintenant la connexion et un document dans le navigateur.\n'
