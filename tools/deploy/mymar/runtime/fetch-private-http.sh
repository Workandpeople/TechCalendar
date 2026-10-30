#!/usr/bin/env bash
set +x
set -Eeuo pipefail
umask 077
BASE=$(cd "$(dirname "$0")" && pwd -P)
HOST=ubuntu@46.105.110.76
REMOTE="/home/ubuntu/cofrac2-mymar-private-http-$(date -u +%Y%m%dT%H%M%SZ)-$$-$RANDOM"
cd "$BASE"
shasum -a 256 -c SHA256SUMS
printf 'Installation de deux nouveaux services prives (127.0.0.1 seulement), compte SQL dedie en lecture deverrouille. Aucun branchement CRM ou rechargement Apache public.\n'
ssh "$HOST" "umask 077; test ! -e '$REMOTE' && test ! -L '$REMOTE' && mkdir -m 700 '$REMOTE'"
FILES=()
while read -r digest file; do
    [[ "$digest" =~ ^[a-f0-9]{64}$ ]] && [[ "$file" =~ ^[A-Za-z0-9_.-]+$ ]] && [ ! -L "$BASE/$file" ] || exit 1
    FILES+=("$BASE/$file")
done < SHA256SUMS
scp "${FILES[@]}" "$BASE/SHA256SUMS" "$HOST:$REMOTE/"
LOG=$(mktemp "$HOME/Downloads/cofrac2-mymar-private-http-run-XXXXXXXX")
ssh -t "$HOST" "cd '$REMOTE' && sudo bash ./install.sh --install-private-http-and-observe" | tee "$LOG"
REPORT=$(tr -d '\r' < "$LOG" | sed -n 's/^REPORT_DIR=//p' | tail -n 1)
[[ "$REPORT" =~ ^/home/ubuntu/cofrac2-mymar-private-http-report-[a-zA-Z0-9]+$ ]] || { printf 'Installation non confirmee : %s\n' "$LOG" >&2; exit 1; }
LOCAL=$(mktemp -d "$HOME/Downloads/cofrac2-mymar-private-http-analysis-XXXXXXXX")
scp "$HOST:$REPORT/*" "$LOCAL/"
cd "$LOCAL"; shasum -a 256 -c SHA256SUMS
printf 'LOCAL_DIR=%s\nRapport recupere sans secret. Aucun RDV MYMAR encore utilise par le planning.\n' "$LOCAL"
