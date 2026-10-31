#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
BASE=$(cd "$(dirname "$0")" && pwd -P)
HOST=ubuntu@46.105.110.76
REMOTE="/home/ubuntu/$(basename "$BASE")"
cd "$BASE"; shasum -a 256 -c SHA256SUMS
printf 'Deploiement avec courte maintenance puis activation explicite des actions MAR pour les techniciens valides. Aucun envoi ou placement de test.\n'
ssh "$HOST" "umask 077; test ! -e '$REMOTE' && test ! -L '$REMOTE' && mkdir -m 0700 '$REMOTE'"
rsync -rt --chmod=Du=rwx,Dgo=,Fu=rw,Fgo= "$BASE/" "$HOST:$REMOTE/"
LOG=$(mktemp "$HOME/Downloads/cofrac2-mymar-actions-run-XXXXXXXX")
ssh -t "$HOST" "cd '$REMOTE' && sudo bash ./run-all.sh --enable-pilot-actions" | tee "$LOG"
REPORT=$(tr -d '\r' < "$LOG" | sed -n 's/^REPORT_DIR=//p' | tail -n 1)
[[ "$REPORT" =~ ^/home/ubuntu/cofrac2-mymar-actions-report-[A-Za-z0-9]+$ ]] || { printf 'STOP : rapport absent. Journal : %s\n' "$LOG" >&2; exit 1; }
DEST=$(mktemp -d "$HOME/Downloads/cofrac2-mymar-actions-analysis-XXXXXXXX")
scp "$HOST:$REPORT/summary.json" "$HOST:$REPORT/SHA256SUMS" "$DEST/"
(cd "$DEST" && shasum -a 256 -c SHA256SUMS)
printf 'LOCAL_DIR=%s\nActualiser le navigateur puis ouvrir RDV MAR. Pas de test sur un vrai dossier sans accord.\n' "$DEST"
