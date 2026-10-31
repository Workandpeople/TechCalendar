#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
BASE=$(cd "$(dirname "$0")" && pwd -P)
HOST=ubuntu@46.105.110.76
REMOTE="/home/ubuntu/cofrac2-mymar-workflow-$(date -u +%Y%m%dT%H%M%SZ)-$$-$RANDOM"
cd "$BASE"; shasum -a 256 -c SHA256SUMS
ssh "$HOST" "umask 077; test ! -e '$REMOTE' && test ! -L '$REMOTE' && mkdir -m 0700 '$REMOTE'"
FILES=()
while read -r digest file; do
    [[ "$digest" =~ ^[a-f0-9]{64}$ ]] && [[ "$file" =~ ^[A-Za-z0-9_.-]+$ ]] && [ ! -L "$BASE/$file" ] || exit 1
    FILES+=("$BASE/$file")
done < SHA256SUMS
scp "${FILES[@]}" "$BASE/SHA256SUMS" "$HOST:$REMOTE/"
LOG=$(mktemp "$HOME/Downloads/cofrac2-mymar-workflow-run-XXXXXXXX")
ssh -t "$HOST" "cd '$REMOTE' && sudo bash ./collect.sh" | tee "$LOG"
REPORT=$(tr -d '\r' < "$LOG" | sed -n 's/^REPORT_DIR=//p' | tail -n 1)
[[ "$REPORT" =~ ^/home/ubuntu/cofrac2-mymar-workflow-report-[A-Za-z0-9]+$ ]] || { printf 'STOP : rapport absent. Journal : %s\n' "$LOG" >&2; exit 1; }
DEST=$(mktemp -d "$HOME/Downloads/cofrac2-mymar-workflow-analysis-XXXXXXXX")
scp "$HOST:$REPORT/source-facts.json.gz" "$HOST:$REPORT/schema.json" "$HOST:$REPORT/summary.json" "$HOST:$REPORT/SHA256SUMS" "$DEST/"
(cd "$DEST" && shasum -a 256 -c SHA256SUMS)
printf 'LOCAL_DIR=%s\n' "$DEST"
printf 'Code masque et metadonnees uniquement. Aucun deploiement ou changement du planning.\n'
