#!/usr/bin/env bash

# Prepare a new deployment identity and checkout only. Do not bootstrap the app.
set -Eeuo pipefail
umask 077

ACTION=${1:-}
if [ "${EUID:-$(id -u)}" -ne 0 ] || [ "$#" -ne 1 ] \
    || { [ "$ACTION" != '--prepare-ssh' ] && [ "$ACTION" != '--clone-v2' ]; }; then
    printf 'Usage : sudo bash prepare-production-checkout.sh --prepare-ssh|--clone-v2\n' >&2
    exit 1
fi
if [ "$(hostname -s)" != ns365601 ]; then
    printf 'STOP : cible attendue ns365601.\n' >&2
    exit 1
fi

cd / || exit 1
DEPLOY_USER=cofrac2-deploy
DEPLOY_HOME=/home/cofrac2-deploy
SSH_DIRECTORY="$DEPLOY_HOME/.ssh"
KEY="$SSH_DIRECTORY/id_ed25519_techcalendar_production"
SSH_CONFIG="$SSH_DIRECTORY/techcalendar-github.conf"
KNOWN_HOSTS="$SSH_DIRECTORY/known_hosts_techcalendar"
STATE=/var/lib/cofrac2-deploy-bootstrap
STATE_CONTENT=techcalendar.production.checkout.v1
TARGET=/var/www/cofrac2
REPOSITORY=git@github.com:Workandpeople/TechCalendar.git

stop() {
    printf 'STOP : %s\n' "$1" >&2
    exit 1
}

require_identity() {
    [ -f "$STATE" ] && [ ! -L "$STATE" ] || stop 'preparer le compte avec --prepare-ssh.'
    [ "$(stat -c %u "$STATE")" = 0 ] || stop 'marqueur de preparation inattendu.'
    [ "$(cat "$STATE")" = "$STATE_CONTENT" ] || stop 'marqueur de preparation inattendu.'
    [ "$(getent passwd "$DEPLOY_USER" | cut -d: -f6)" = "$DEPLOY_HOME" ] \
        || stop 'compte ou repertoire personnel inattendu.'
    [ "$(id -gn "$DEPLOY_USER")" = "$DEPLOY_USER" ] || stop 'groupe principal inattendu.'
    [ -d "$DEPLOY_HOME" ] && [ ! -L "$DEPLOY_HOME" ] || stop 'repertoire personnel inattendu.'
    [ "$(stat -c %U "$DEPLOY_HOME")" = "$DEPLOY_USER" ] || stop 'proprietaire du home inattendu.'
    [ "$(passwd -S "$DEPLOY_USER" | awk '{print $2}')" = L ] || stop 'mot de passe du compte non verrouille.'
}

write_config() {
    local path=$1 content
    content=$(cat)
    if [ -e "$path" ] || [ -L "$path" ]; then
        [ -f "$path" ] && [ ! -L "$path" ] || stop "fichier inattendu : $path"
        [ "$(stat -c %U "$path")" = "$DEPLOY_USER" ] || stop "proprietaire inattendu : $path"
        cmp -s "$path" <(printf '%s\n' "$content") || stop "configuration existante differente : $path"
    else
        printf '%s\n' "$content" > "$path"
        chown "$DEPLOY_USER:$DEPLOY_USER" "$path"
        chmod 0600 "$path"
    fi
}

if [ "$ACTION" = '--prepare-ssh' ]; then
    for executable in getent useradd passwd runuser ssh-keygen git stat cmp; do
        command -v "$executable" >/dev/null || stop "outil manquant : $executable"
    done

    if ! getent passwd "$DEPLOY_USER" >/dev/null; then
        for path in "$DEPLOY_HOME" "$STATE"; do
            if [ -e "$path" ] || [ -L "$path" ]; then stop "chemin deja present : $path"; fi
        done
        useradd --create-home --user-group --home-dir "$DEPLOY_HOME" --shell /bin/bash \
            --comment 'TechCalendar V2 deployment only' "$DEPLOY_USER"
        printf '%s\n' "$STATE_CONTENT" > "$STATE"
        chmod 0600 "$STATE"
    fi
    require_identity

    if [ -L "$SSH_DIRECTORY" ]; then stop 'repertoire SSH symbolique refuse.'; fi
    chmod 0700 "$DEPLOY_HOME"
    install -d -o "$DEPLOY_USER" -g "$DEPLOY_USER" -m 0700 "$SSH_DIRECTORY"

    if [ -e "$KEY" ] || [ -L "$KEY" ]; then
        [ -f "$KEY" ] && [ ! -L "$KEY" ] || stop 'cle privee inattendue.'
        [ "$(stat -c %U "$KEY")" = "$DEPLOY_USER" ] || stop 'proprietaire de cle inattendu.'
        [ "$(stat -c %a "$KEY")" = 600 ] || stop 'permissions de cle privee inattendues.'
        [ -f "$KEY.pub" ] && [ ! -L "$KEY.pub" ] || stop 'cle publique absente ou symbolique.'
    else
        [ ! -e "$KEY.pub" ] && [ ! -L "$KEY.pub" ] || stop 'cle publique deja presente sans cle privee.'
        runuser -u "$DEPLOY_USER" -- ssh-keygen -q -t ed25519 -N '' \
            -C 'techcalendar-v2-production-ns365601' -f "$KEY"
    fi

    # Official GitHub Ed25519 host key, verified against GitHub's documentation.
    write_config "$KNOWN_HOSTS" <<'HOST_KEY'
github.com ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl
HOST_KEY
    write_config "$SSH_CONFIG" <<SSH_OPTIONS
Host github.com
    HostName github.com
    User git
    IdentityFile $KEY
    IdentitiesOnly yes
    IdentityAgent none
    UserKnownHostsFile $KNOWN_HOSTS
    GlobalKnownHostsFile /dev/null
    StrictHostKeyChecking yes
    HostKeyAlgorithms ssh-ed25519
    BatchMode yes
    ConnectTimeout 10
SSH_OPTIONS

    [ "$(ssh-keygen -lf "$KNOWN_HOSTS" -E sha256 | awk '{print $2}')" \
        = 'SHA256:+DiY3wvvV6TuJJhbpZisF/zLDA0zPMSvHdkr4UvCOqU' ] || stop 'cle hote GitHub inattendue.'
    public_key=$(runuser -u "$DEPLOY_USER" -- ssh-keygen -y -f "$KEY" | awk '{print $1" "$2}')
    [ "$public_key" = "$(awk '{print $1" "$2}' "$KEY.pub")" ] || stop 'paire de cles incoherente.'

    printf 'Compte et acces SSH sortant prepares. Aucun service modifie.\n'
    printf 'Ajouter cette cle PUBLIQUE aux Deploy keys de Workandpeople/TechCalendar, sans droit d ecriture :\n'
    cat "$KEY.pub"
    printf 'Aucun clone, paquet, fichier applicatif, acces SQL ou service demarre.\n'
    exit 0
fi

require_identity
test -f "$KEY"
test -f "$SSH_CONFIG"
if [ -e "$TARGET" ] || [ -L "$TARGET" ]; then
    stop "destination deja presente, ne pas ecraser : $TARGET"
fi

# Check repository permission and the branch before creating the checkout.
runuser -u "$DEPLOY_USER" -- env GIT_SSH_COMMAND="ssh -F $SSH_CONFIG" \
    git ls-remote --exit-code --heads "$REPOSITORY" refs/heads/v2 >/dev/null
install -d -o "$DEPLOY_USER" -g "$DEPLOY_USER" -m 0700 "$TARGET"
runuser -u "$DEPLOY_USER" -- env GIT_SSH_COMMAND="ssh -F $SSH_CONFIG" \
    git clone --branch v2 --single-branch "$REPOSITORY" "$TARGET"
runuser -u "$DEPLOY_USER" -- git -C "$TARGET" config core.sshCommand "ssh -F $SSH_CONFIG"
printf 'Checkout V2 : %s\n' "$TARGET"
runuser -u "$DEPLOY_USER" -- git -C "$TARGET" log -1 --format='%h %s'
printf 'Code clone uniquement. Aucun .env, import, migration ou service demarre.\n'
