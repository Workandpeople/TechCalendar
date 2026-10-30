#!/usr/bin/env bash
set +x
set -Eeuo pipefail
umask 077
BASE=$(cd "$(dirname "$0")" && pwd -P)
HOST=ubuntu@46.105.110.76
REMOTE="/home/ubuntu/cofrac2-mymar-mapping-$(date -u +%Y%m%dT%H%M%SZ)-$$-$RANDOM"
cd "$BASE"
shasum -a 256 -c SHA256SUMS
ssh "$HOST" "umask 077; test ! -e '$REMOTE' && test ! -L '$REMOTE' && mkdir -m 700 '$REMOTE'"
FILES=()
while read -r digest file; do
    [[ "$digest" =~ ^[a-f0-9]{64}$ ]] && [[ "$file" =~ ^[A-Za-z0-9_.-]+$ ]] && [ ! -L "$BASE/$file" ] || exit 1
    FILES+=("$BASE/$file")
done < SHA256SUMS
scp "${FILES[@]}" "$BASE/SHA256SUMS" "$HOST:$REMOTE/"
LOG=$(mktemp "$HOME/Downloads/cofrac2-mymar-mapping-run-XXXXXXXX")
ssh -t "$HOST" "cd '$REMOTE' && sudo bash ./collect.sh" | tee "$LOG"
REPORT=$(tr -d '\r' < "$LOG" | sed -n 's/^REPORT_DIR=//p' | tail -n 1)
[[ "$REPORT" =~ ^/home/ubuntu/cofrac2-mymar-mapping-report-[a-zA-Z0-9]+$ ]] || { printf 'Preparation non confirmee : %s\n' "$LOG" >&2; exit 1; }
LOCAL=$(mktemp -d "$HOME/Downloads/cofrac2-mymar-mapping-analysis-XXXXXXXX")
scp "$HOST:$REPORT/*" "$LOCAL/"
cd "$LOCAL"
shasum -a 256 -c SHA256SUMS
printf 'LOCAL_DIR=%s\n' "$LOCAL"
printf 'Rapport prive recupere. Le fichier de correspondance reste root-prive, sans activation.\n'
