#!/usr/bin/env bash
set +x
set -Eeuo pipefail
umask 077
BASE=$(cd "$(dirname "$0")" && pwd -P)
HOST=ubuntu@46.105.110.76
REMOTE="/home/ubuntu/cofrac2-mymar-cache-$(date -u +%Y%m%dT%H%M%SZ)-$$-$RANDOM"
cd "$BASE"
shasum -a 256 -c SHA256SUMS
ssh "$HOST" "umask 077; test ! -e '$REMOTE' && test ! -L '$REMOTE' && mkdir -m 700 '$REMOTE'"
scp "$BASE/MymarReadOnlyAudit.php" "$BASE/diagnose.php" "$BASE/collect.sh" \
    "$BASE/fetch-audit.sh" "$BASE/README.txt" "$BASE/SHA256SUMS" "$HOST:$REMOTE/"
LOG=$(mktemp "$HOME/Downloads/cofrac2-mymar-cache-run-XXXXXXXX")
ssh -t "$HOST" "cd '$REMOTE' && sudo bash ./collect.sh" | tee "$LOG"
REPORT=$(tr -d '\r' < "$LOG" | sed -n 's/^REPORT_DIR=//p' | tail -n 1)
[[ "$REPORT" =~ ^/home/ubuntu/cofrac2-mymar-cache-report-[a-zA-Z0-9]+$ ]] || { printf 'Rapport non confirme : %s\n' "$LOG" >&2; exit 1; }
LOCAL=$(mktemp -d "$HOME/Downloads/cofrac2-mymar-cache-analysis-XXXXXXXX")
scp "$HOST:$REPORT/SHA256SUMS" "$HOST:$REPORT/cache-diagnostic.json" "$LOCAL/"
cd "$LOCAL"
shasum -a 256 -c SHA256SUMS
printf 'LOCAL_DIR=%s\n' "$LOCAL"
printf 'Diagnostic sans valeurs de configuration telecharge. Aucun SQL ni appel applicatif.\n'
