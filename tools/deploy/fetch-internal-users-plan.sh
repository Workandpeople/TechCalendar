#!/usr/bin/env bash

# Run on the Mac. Upload the sealed read-only inspection package, then fetch its private report.
set +x
set -Eeuo pipefail
umask 077
HERE=$(cd -- "$(dirname -- "$0")" && pwd -P)
NAME=$(basename "$HERE")
[[ "$NAME" =~ ^cofrac2-internal-users-plan-[A-Za-z0-9]{8}$ ]] || { echo 'Paquet prive attendu.' >&2; exit 1; }
REMOTE=ubuntu@46.105.110.76
for command in scp ssh shasum gzip mktemp awk tee tr; do
    command -v "$command" >/dev/null || { echo "Outil manquant : $command" >&2; exit 1; }
done
(cd "$HERE" && shasum -a 256 -c SHA256SUMS)
SHA=$(shasum -a 256 "$HERE/SHA256SUMS" | awk '{print $1}')
DEST=$(mktemp -d "$HOME/Downloads/cofrac2-internal-users-report-XXXXXXXX")
scp -rp -o ConnectTimeout=10 "$HERE" "$REMOTE:/home/ubuntu/"
if ! ssh -tt -o ConnectTimeout=10 "$REMOTE" \
    "sudo bash '/home/ubuntu/$NAME/plan-techcalendar-internal-users.sh' --plan '$SHA'" | tee "$DEST/collection.log"; then
    printf 'Controle echoue. Aucun import effectue. Diagnostic local : %s\n' "$DEST" >&2
    exit 1
fi
REPORT=$(tr -d '\r' < "$DEST/collection.log" | awk -F= '/^REPORT_DIR=/{print $2}')
REPORT_SHA=$(tr -d '\r' < "$DEST/collection.log" | awk -F= '/^REPORT_SHA256=/{print $2}')
[[ "$REPORT" =~ ^/home/ubuntu/cofrac2-internal-users-report-[A-Za-z0-9]{8}$ && "$REPORT_SHA" =~ ^[a-f0-9]{64}$ ]] \
    || { echo 'Rapport non valide : chemin ou empreinte inattendu.' >&2; exit 1; }
scp -o ConnectTimeout=10 "$REMOTE:$REPORT/report.json.gz" "$REMOTE:$REPORT/SHA256SUMS" "$DEST/"
chmod 0600 "$DEST"/*
(cd "$DEST" && shasum -a 256 -c SHA256SUMS)
[ "$(shasum -a 256 "$DEST/report.json.gz" | awk '{print $1}')" = "$REPORT_SHA" ]
gzip -t "$DEST/report.json.gz"
printf 'LOCAL_DIR=%s\nSHA256=%s\n' "$DEST" "$REPORT_SHA"
printf 'Rapport prive telecharge. Aucun import ni changement de mot de passe.\n'
