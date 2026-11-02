#!/usr/bin/env bash
set +x
set -Eeuo pipefail
umask 077
BASE=$(cd "$(dirname "$0")" && pwd -P)
NAME=$(basename "$BASE")
[[ "$NAME" =~ ^cofrac2-mymar-roster-[A-Za-z0-9]+$ ]] || exit 1
HOST=ubuntu@46.105.110.76
REMOTE="/home/ubuntu/$NAME"
cd "$BASE"; shasum -a 256 -c SHA256SUMS
CONTROL=$(mktemp -d "${TMPDIR:-/tmp}/cofrac-roster-ssh-XXXXXXXX")
cleanup() {
    ssh -S "$CONTROL/socket" -O exit "$HOST" >/dev/null 2>&1 || true
    rmdir "$CONTROL" 2>/dev/null || true
}
trap cleanup EXIT
ssh -M -S "$CONTROL/socket" -o ControlPersist=300 -o StrictHostKeyChecking=yes -fN "$HOST"
CONNECTION=(-o "ControlPath=$CONTROL/socket" -o StrictHostKeyChecking=yes)
ssh "${CONNECTION[@]}" "$HOST" "umask 077; test ! -e '$REMOTE' && test ! -L '$REMOTE' && mkdir -m 0700 '$REMOTE'"
scp "${CONNECTION[@]}" "$BASE/"* "$HOST:$REMOTE/"
LOG=$(mktemp "$HOME/Downloads/cofrac2-mymar-roster-run-XXXXXXXX")
ssh "${CONNECTION[@]}" -t "$HOST" "cd '$REMOTE' && sudo bash ./collect.sh" | tee "$LOG"
REPORT=$(tr -d '\r' < "$LOG" | sed -n 's/^REPORT_DIR=//p' | tail -n 1)
[[ "$REPORT" =~ ^/home/ubuntu/cofrac2-mymar-roster-report-[A-Za-z0-9]+$ ]] || exit 1
DEST=$(mktemp -d "$HOME/Downloads/cofrac2-mymar-roster-analysis-XXXXXXXX")
scp "${CONNECTION[@]}" "$HOST:$REPORT/report.json" "$HOST:$REPORT/preparation.txt" "$HOST:$REPORT/SHA256SUMS" "$DEST/"
(cd "$DEST" && shasum -a 256 -c SHA256SUMS)
printf 'LOCAL_DIR=%s\nPlan global prive recupere. Aucune correspondance active modifiee.\n' "$DEST"
