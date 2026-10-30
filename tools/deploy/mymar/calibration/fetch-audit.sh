#!/usr/bin/env bash
set +x
set -Eeuo pipefail
umask 077
BASE=$(cd "$(dirname "$0")" && pwd -P)
HOST=ubuntu@46.105.110.76
REMOTE="/home/ubuntu/cofrac2-mymar-calibration-$(date -u +%Y%m%dT%H%M%SZ)-$$-$RANDOM"
cd "$BASE"
shasum -a 256 -c SHA256SUMS
ssh "$HOST" "umask 077; test ! -e '$REMOTE' && test ! -L '$REMOTE' && mkdir -m 700 '$REMOTE'"
scp "$BASE/MymarReadOnlyAudit.php" "$BASE/MymarTimeCalibration.php" "$BASE/calibrate.php" \
    "$BASE/collect.sh" "$BASE/fetch-audit.sh" "$BASE/README.txt" "$BASE/SHA256SUMS" "$HOST:$REMOTE/"
LOG=$(mktemp "$HOME/Downloads/cofrac2-mymar-calibration-run-XXXXXXXX")
ssh -t "$HOST" "cd '$REMOTE' && sudo bash ./collect.sh" | tee "$LOG"
REPORT=$(tr -d '\r' < "$LOG" | sed -n 's/^REPORT_DIR=//p' | tail -n 1)
[[ "$REPORT" =~ ^/home/ubuntu/cofrac2-mymar-calibration-report-[a-zA-Z0-9]+$ ]] || { printf 'Rapport non confirme. Diagnostic local : %s\n' "$LOG" >&2; exit 1; }
LOCAL=$(mktemp -d "$HOME/Downloads/cofrac2-mymar-calibration-analysis-XXXXXXXX")
scp "$HOST:$REPORT/*" "$LOCAL/"
cd "$LOCAL"
shasum -a 256 -c SHA256SUMS
gzip -t source-facts.json.gz
printf 'LOCAL_DIR=%s\n' "$LOCAL"
printf 'Calibration privee telechargee. Transmettre seulement LOCAL_DIR pour analyser la suite.\n'
