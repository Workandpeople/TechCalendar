#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
BASE=$(cd "$(dirname "$0")" && pwd -P)
HOST=ubuntu@46.105.110.76
REMOTE="/home/ubuntu/cofrac2-mymar-booking-reader-$(date -u +%Y%m%dT%H%M%SZ)-$$-$RANDOM"
cd "$BASE"; shasum -a 256 -c SHA256SUMS
printf 'Installation du lecteur prive MAR, sans deploiement applicatif ni activation des placements.\n'
ssh "$HOST" "umask 077; test ! -e '$REMOTE' && test ! -L '$REMOTE' && mkdir -m 0700 '$REMOTE'"
FILES=()
while read -r digest file; do
    [[ "$digest" =~ ^[a-f0-9]{64}$ ]] && [[ "$file" =~ ^[A-Za-z0-9_.-]+$ ]] && [ ! -L "$BASE/$file" ] || exit 1
    FILES+=("$BASE/$file")
done < SHA256SUMS
scp "${FILES[@]}" "$BASE/SHA256SUMS" "$HOST:$REMOTE/"
LOG=$(mktemp "$HOME/Downloads/cofrac2-mymar-booking-reader-run-XXXXXXXX")
ssh -t "$HOST" "cd '$REMOTE' && sudo bash ./install.sh --install-reader-only" | tee "$LOG"
REPORT=$(tr -d '\r' < "$LOG" | sed -n 's/^REPORT_DIR=//p' | tail -n 1)
[[ "$REPORT" =~ ^/home/ubuntu/cofrac2-mymar-booking-http-report-[A-Za-z0-9]+$ ]] || { printf 'STOP : rapport absent. Journal : %s\n' "$LOG" >&2; exit 1; }
DEST=$(mktemp -d "$HOME/Downloads/cofrac2-mymar-booking-reader-analysis-XXXXXXXX")
scp "$HOST:$REPORT/summary.json" "$HOST:$REPORT/SHA256SUMS" "$DEST/"
(cd "$DEST" && shasum -a 256 -c SHA256SUMS)
printf 'LOCAL_DIR=%s\nAucune donnee beneficiaire ni secret telecharge. Aucun placement active.\n' "$DEST"
