#!/usr/bin/env bash
set +x
set -Eeuo pipefail
umask 077
BASE=$(cd "$(dirname "$0")" && pwd -P)
NAME=$(basename "$BASE")
[[ "$NAME" =~ ^cofrac2-disk-audit-[A-Za-z0-9]+$ ]] || { echo 'Executer depuis le paquet disque prepare.' >&2; exit 1; }
HOST=ubuntu@46.105.110.76
REMOTE="/home/ubuntu/$NAME"
cd "$BASE"
shasum -a 256 -c SHA256SUMS
CONTROL=$(mktemp -d "${TMPDIR:-/tmp}/cofrac-disk-ssh-XXXXXXXX")
cleanup() {
    ssh -S "$CONTROL/socket" -O exit "$HOST" >/dev/null 2>&1 || true
    rmdir "$CONTROL" 2>/dev/null || true
}
trap cleanup EXIT
ssh -M -S "$CONTROL/socket" -o ControlPersist=900 -o StrictHostKeyChecking=yes -fN "$HOST"
CONNECTION=(-o "ControlPath=$CONTROL/socket" -o StrictHostKeyChecking=yes)
ssh "${CONNECTION[@]}" "$HOST" "umask 077; test ! -e '$REMOTE' && test ! -L '$REMOTE' && mkdir -m 0700 '$REMOTE'"
scp "${CONNECTION[@]}" "$BASE/audit-production-disk.sh" "$BASE/README.txt" "$BASE/fetch-audit.sh" "$BASE/SHA256SUMS" "$HOST:$REMOTE/"
LOG=$(mktemp "$HOME/Downloads/cofrac2-disk-audit-run-XXXXXXXX")
ssh "${CONNECTION[@]}" -t "$HOST" "cd '$REMOTE' && sha256sum -c SHA256SUMS && sudo bash ./audit-production-disk.sh --audit" | tee "$LOG"
REPORT=$(tr -d '\r' < "$LOG" | sed -n 's/^REPORT_DIR=//p' | tail -n 1)
[[ "$REPORT" =~ ^/home/ubuntu/cofrac2-disk-report-[A-Za-z0-9]+$ ]] || { echo 'Rapport non recupere. Transmettre le message affiche.' >&2; exit 1; }
DEST=$(mktemp -d "$HOME/Downloads/cofrac2-disk-analysis-XXXXXXXX")
scp "${CONNECTION[@]}" "$HOST:$REPORT/audit.txt" "$HOST:$REPORT/sections.tsv" "$HOST:$REPORT/SHA256SUMS" "$DEST/"
(cd "$DEST" && shasum -a 256 -c SHA256SUMS)
printf 'LOCAL_DIR=%s\nAucune suppression effectuee. Transmettre uniquement LOCAL_DIR pour analyser les resultats.\n' "$DEST"
