#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
BASE=$(cd "$(dirname "$0")" && pwd -P)
HOST=ubuntu@46.105.110.76
REMOTE="/home/ubuntu/$(basename "$BASE")"
cd "$BASE"; shasum -a 256 -c SHA256SUMS
ssh "$HOST" "umask 077; test ! -e '$REMOTE' && test ! -L '$REMOTE' && mkdir -m 0700 '$REMOTE'"
rsync -rt --chmod=Du=rwx,Dgo=,Fu=rw,Fgo= "$BASE/" "$HOST:$REMOTE/"
LOG=$(mktemp "$HOME/Downloads/cofrac2-mymar-actions-recovery-run-XXXXXXXX")
ssh -t "$HOST" "cd '$REMOTE' && sudo bash ./resume.sh --resume-pilot-actions" | tee "$LOG"
REPORT=$(tr -d '\r' < "$LOG" | sed -n 's/^REPORT_DIR=//p' | tail -n 1)
[[ "$REPORT" =~ ^/home/ubuntu/cofrac2-mymar-actions-recovery-report-[A-Za-z0-9]+$ ]] || exit 1
DEST=$(mktemp -d "$HOME/Downloads/cofrac2-mymar-actions-recovery-analysis-XXXXXXXX")
scp "$HOST:$REPORT/summary.json" "$HOST:$REPORT/repair.json" "$HOST:$REPORT/readiness.json" "$HOST:$REPORT/SHA256SUMS" "$DEST/"
(cd "$DEST" && shasum -a 256 -c SHA256SUMS)
printf 'LOCAL_DIR=%s\nActualiser le navigateur puis ouvrir RDV MAR. Aucun placement de test effectue.\n' "$DEST"
