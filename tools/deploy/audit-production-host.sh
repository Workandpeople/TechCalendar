#!/usr/bin/env bash

# Inventory only: no Laravel bootstrap, application config, HTTP request or writes.
set -uo pipefail
export LC_ALL=C

if [ "${EUID:-$(id -u)}" -ne 0 ]; then
    printf 'Executer avec sudo bash audit-production-host.sh.\n' >&2
    exit 1
fi

cd / || exit 1
COFFRAC_APP=/home/ubuntu/www/coffrac_preprod

section() {
    printf '\n%s\n' "$1"
}

section 'SYSTEME ET CAPACITE'
date -u '+%Y-%m-%d %H:%M:%S UTC'
hostname
uname -sr
awk '/^(PRETTY_NAME|VERSION_ID)=/' /etc/os-release
uptime
free -h
df -hT / /home /var /srv /tmp
df -i / /home /var /srv
lsblk -o NAME,TYPE,SIZE,FSTYPE,MOUNTPOINTS

section 'SERVICES ET PORTS EN ECOUTE'
systemctl list-units --all --type=service --full --no-pager \
    'apache2*' 'nginx*' 'php*-fpm*' 'mysql*' 'mariadb*' 'redis*' \
    '*queue*' '*reverb*' '*supervisor*' '*techcalendar*'
ss -lnt
ps -eo user=,comm= | awk '$2 ~ /^(apache2|nginx|php-fpm|mysqld|mariadbd|redis-server)/' | sort -u

section 'SITES CONFIGURES ET VHOSTS CHARGES'
if command -v apache2ctl >/dev/null 2>&1; then
    apache2ctl -S 2>&1
    apache2ctl -M 2>/dev/null | grep -E '(proxy|fcgi|rewrite|ssl|php)' || true
fi

for directory in /etc/apache2/sites-enabled /etc/nginx/sites-enabled; do
    if [ -d "$directory" ]; then
        ls -l "$directory"
        # Restrict output to routing and log locations; never dump whole vhosts.
        grep -REn '^[[:space:]]*(ServerName[[:space:]]|ServerAlias[[:space:]]|DocumentRoot[[:space:]]|ErrorLog[[:space:]]|CustomLog[[:space:]]|AllowOverride[[:space:]]|Require[[:space:]]|SetHandler[[:space:]]|<Directory[[:space:]]|listen[[:space:]]|server_name[[:space:]]|root[[:space:]]|fastcgi_pass[[:space:]])' \
            "$directory" || true
    fi
done
if [ -d /etc/nginx/conf.d ]; then
    grep -REn '^[[:space:]]*(listen[[:space:]]|server_name[[:space:]]|root[[:space:]]|fastcgi_pass[[:space:]])' \
        /etc/nginx/conf.d || true
fi

section 'RUNTIMES ET OUTILS'
for runtime in php php8.3 php8.4 php8.5; do
    if command -v "$runtime" >/dev/null 2>&1; then
        command -v "$runtime"
        "$runtime" -v 2>&1 | head -n 2
    fi
done
if command -v php8.4 >/dev/null 2>&1; then
    php8.4 -m
fi
for executable in composer git node npm mysql mariadb rsync ionice certbot; do
    command -v "$executable" || true
done
if command -v composer >/dev/null 2>&1; then
    COMPOSER_ALLOW_SUPERUSER=1 composer --no-plugins --no-scripts --no-interaction --version 2>&1 | head -n 3
fi
if command -v node >/dev/null 2>&1; then node --version; fi
if command -v npm >/dev/null 2>&1; then npm --version; fi
if command -v mysql >/dev/null 2>&1; then mysql --no-defaults --version; fi

section 'TAILLES DES BASES : SELECT UNIQUEMENT, CONNEXION ROOT PAR SOCKET'
if command -v mysql >/dev/null 2>&1; then
    if ! mysql --no-defaults --protocol=socket --user=root --connect-timeout=5 --table \
        --execute="SELECT TABLE_SCHEMA AS database_name, COUNT(*) AS base_tables,
        ROUND(SUM(COALESCE(DATA_LENGTH,0) + COALESCE(INDEX_LENGTH,0))/1024/1024,1) AS estimated_mib
        FROM information_schema.TABLES
        WHERE TABLE_TYPE = 'BASE TABLE'
          AND TABLE_SCHEMA NOT IN ('mysql','sys','performance_schema','information_schema')
        GROUP BY TABLE_SCHEMA ORDER BY estimated_mib DESC"; then
        printf 'Inventaire SQL indisponible via le socket root. Ne pas partager de mot de passe.\n'
    fi
fi

section 'REPERTOIRES APPLICATIFS'
for directory in /home/ubuntu/www /var/www /srv; do
    if [ -d "$directory" ]; then
        find "$directory" -mindepth 1 -maxdepth 1 -type d -print
    fi
done

section 'VOLUMES CIBLES : PARCOURS DES METADONNEES A FAIBLE PRIORITE'
for directory in \
    "$COFFRAC_APP/public/documents" "$COFFRAC_APP/public/pdf" \
    "$COFFRAC_APP/public/media" "$COFFRAC_APP/public/signature" \
    "$COFFRAC_APP/public/lot_dossier" "$COFFRAC_APP/storage" \
    /var/lib/mysql /var/log \
    /home/ubuntu/coffrac-export-* /home/ubuntu/coffrac-audit-* \
    /home/ubuntu/coffrac-documents-selection-*; do
    if [ -d "$directory" ] && [ ! -L "$directory" ]; then
        if command -v ionice >/dev/null 2>&1; then
            ionice -c 3 nice -n 15 du -shx -- "$directory"
        else
            nice -n 15 du -shx -- "$directory"
        fi
    fi
done

section 'CONTROLE DES FICHIERS SUSPECTS DEJA IDENTIFIES : METADONNEES ET EMPREINTES'
for relative in \
    public/universign/css/images/108492/index.php \
    public/pdf/875032/.htaccess \
    public/media/440055/.htaccess \
    public/lot_dossier/533657/.htaccess; do
    candidate="$COFFRAC_APP/$relative"
    if [ -L "$candidate" ]; then
        printf 'Lien symbolique : %s -> %s\n' "$candidate" "$(readlink -- "$candidate")"
    elif [ -f "$candidate" ]; then
        stat --printf='%n | %s octets | %U:%G | %a | modification %y\n' -- "$candidate"
        sha256sum -- "$candidate"
    else
        printf 'Absent : %s\n' "$candidate"
    fi
done

section 'FIN DE L INVENTAIRE : AUCUN SERVICE, FICHIER APPLICATIF OU BASE MODIFIE'
printf 'Cet inventaire ne constitue pas une analyse complete de compromission.\n'
