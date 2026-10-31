#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
BASE=$(cd "$(dirname "$0")" && pwd -P)
HOST=ubuntu@46.105.110.76
REMOTE="/home/ubuntu/$(basename "$BASE")"
cd "$BASE"; shasum -a 256 -c SHA256SUMS
printf 'Deploiement Cofrac puis ouverture de la liste MAR en lecture seule. Courte maintenance applicative ; aucun envoi de test.\n'
ssh "$HOST" "umask 077; test ! -e '$REMOTE' && test ! -L '$REMOTE' && mkdir -m 0700 '$REMOTE'"
rsync -rt --chmod=Du=rwx,Dgo=,Fu=rw,Fgo= "$BASE/" "$HOST:$REMOTE/"
LOG=$(mktemp "$HOME/Downloads/cofrac2-mymar-booking-ui-run-XXXXXXXX")
ssh -t "$HOST" "cd '$REMOTE' && sudo bash ./run-all.sh" | tee "$LOG"
REPORT=$(tr -d '\r' < "$LOG" | sed -n 's/^REPORT_DIR=//p' | tail -n 1)
[[ "$REPORT" =~ ^/home/ubuntu/cofrac2-mymar-booking-ui-report-[A-Za-z0-9]+$ ]] || { printf 'STOP : rapport absent. Journal : %s\n' "$LOG" >&2; exit 1; }
DEST=$(mktemp -d "$HOME/Downloads/cofrac2-mymar-booking-ui-analysis-XXXXXXXX")
scp "$HOST:$REPORT/summary.json" "$HOST:$REPORT/SHA256SUMS" "$DEST/"
(cd "$DEST" && shasum -a 256 -c SHA256SUMS)
printf 'LOCAL_DIR=%s\nAucun secret ni donnees beneficiaire telecharges. Les placements restent desactives.\n' "$DEST"
