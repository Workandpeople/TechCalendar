#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
[ "${EUID:-$(id -u)}" -eq 0 ] && [ -f /.dockerenv ] \
    && [ "${COFRAC2_DOCUMENTS_TEST:-}" = 1 ] && [ "$(hostname -s)" = ns365601 ] \
    || { echo 'Fixture Docker jetable uniquement.' >&2; exit 1; }
STATE=/var/lib/cofrac2-production
SOURCE=/home/ubuntu/www/coffrac_preprod
ARCHIVE=/srv/cofrac2-document-archive
HERE=$(cd -- "$(dirname -- "$0")/.." && pwd -P)
SCRIPT="$HERE/prepare-production-documents.sh"
HELPER="$HERE/document-archive.php"
test ! -e "$STATE"
test ! -e "$SOURCE"
test ! -e "$ARCHIVE"
install -d -m 0700 "$STATE" "$SOURCE"
printf '{"fixture_only":true}\n' > "$STATE/SCHEMA-READY.json"
for path in public/documents public/pdf public/media public/signature public/lot_dossier storage/app; do
    install -d -m 0700 "$SOURCE/$path"
done
printf 'ORIGINAL ENV\n' > "$SOURCE/.env"
printf 'ORIGINAL WEB ENTRY\n' > "$SOURCE/public/index.php"
printf 'ORIGINAL APACHE CONFIG\n' > /tmp/original-vhost.conf
printf 'ORIGINAL HTACCESS\n' > "$SOURCE/public/documents/.htaccess"
printf '<?php file_put_contents("/tmp/SOURCE-EXECUTED", "BAD");\n' > "$SOURCE/public/documents/suspicious.php"
printf 'PDF NORMAL CONTENT\n' > "$SOURCE/public/documents/normal.pdf"
printf 'PDF BILLED CONTENT\n' > "$SOURCE/public/documents/billed.pdf"
printf 'PDF SUBDIR CONTENT\n' > "$SOURCE/public/pdf/report.pdf"
printf 'JPG CONTENT\n' > "$SOURCE/public/media/site.jpg"
printf 'PNG CONTENT\n' > "$SOURCE/public/signature/sig.png"
printf 'XLSX CONTENT\n' > "$SOURCE/public/lot_dossier/lot.xlsx"
printf 'PRIVATE UNKNOWN CONTENT\n' > "$SOURCE/storage/app/unknown.bin"
printf '' > "$SOURCE/public/documents/empty.pdf"
printf 'PRIVATE EXTERNAL CONTENT\n' > /tmp/outside-secret.txt
ln -s /tmp/outside-secret.txt "$SOURCE/public/documents/link.pdf"
php8.4 -r 'file_put_contents($argv[1]."/public/documents/spaces ; and accents-\xc3\xa9.pdf", "PDF SPACES"); file_put_contents($argv[1]."/public/documents/invalid-\xff.pdf", "UNKNOWN NAME");' "$SOURCE"
# Occupy enough transfer time for the capacity watchdog to stop a real rsync.
truncate -s 33554432 "$SOURCE/public/documents/large.pdf"
link "$SOURCE/public/documents/normal.pdf" "$SOURCE/public/documents/shared-reference.pdf"
find "$SOURCE" -type f -exec sha256sum {} + > /tmp/original-content.sha256
stat -c '%u %g %a %s %Y %Z %i %n' "$SOURCE/.env" "$SOURCE/public/index.php" "$SOURCE/public/documents/normal.pdf" > /tmp/original-metadata.txt
# Any accidental service or SQL command fails and records the violation.
for command in mysql mysqldump systemctl service apachectl a2ensite a2enmod; do
    cat > "/usr/sbin/$command" <<'GUARD'
#!/bin/sh
printf '%s\n' "$0" >> /tmp/UNEXPECTED-SERVICE-OR-SQL
exit 99
GUARD
    chmod 0755 "/usr/sbin/$command"
done

bash "$SCRIPT" --inventory-all-documents > /tmp/document-plan.log
INVENTORY=$(sed -n 's/^INVENTORY=//p' /tmp/document-plan.log)
test -d "$INVENTORY"
test ! -e "$ARCHIVE"
php8.4 -r '$s=json_decode(file_get_contents($argv[1]."/summary.json"),true); if (!$s["complete"] || $s["files"]!==12 || $s["quarantined_files"]!==2 || $s["source_hardlinked_files"]!==2 || $s["servable_documents"]!==5) exit(1);' "$INVENTORY"
# Same guard as production: refuse concurrent inventory/copy/verification.
exec 8>"$STATE/documents-operation.lock"
flock -n 8
if bash "$SCRIPT" --copy-all-documents "$INVENTORY" > /tmp/concurrent-copy.log 2>&1; then exit 1; fi
exec 8>&-
grep -q 'operation documentaire V2 est deja en cours' /tmp/concurrent-copy.log

cat > /usr/sbin/df <<'LOW_SPACE'
#!/bin/sh
printf 'Filesystem 1024-blocks Used Available Capacity Mounted\nfixture 100 99 1 99%% /\n'
LOW_SPACE
chmod 0755 /usr/sbin/df
if bash "$SCRIPT" --copy-all-documents "$INVENTORY" > /tmp/interrupted-copy.log 2>&1; then exit 1; fi
test -n "$(find "$STATE" -name SPACE-STOPPED.txt -print -quit)"
cat > /usr/sbin/df <<'FAILED_MEASURE'
#!/bin/sh
exit 1
FAILED_MEASURE
if bash "$SCRIPT" --copy-all-documents "$INVENTORY" > /tmp/failed-measure-copy.log 2>&1; then exit 1; fi
unlink /usr/sbin/df
# Resume the same inventory; keep partial files and never erase existing data.
bash "$SCRIPT" --copy-all-documents "$INVENTORY" > /tmp/resumed-copy.log
bash "$SCRIPT" --verify-all-documents "$INVENTORY" > /tmp/verified-copy.log
REPORT=$(find "$STATE" -path '*/report.json' -print -quit)
php8.4 -r '$r=json_decode(file_get_contents($argv[1]),true); if (!$r["copy_verified"] || $r["verified_files"]!==12 || $r["final_cutover_ready"] || $r["database_links_verified"]) exit(1);' "$REPORT"
test ! -e "$ARCHIVE/files/public/documents/.htaccess"
test ! -e "$ARCHIVE/files/public/documents/suspicious.php"
test ! -e "$ARCHIVE/files/public/documents/link.pdf"
test -f "$ARCHIVE/files/storage/app/unknown.bin"
test "$(stat -c %h "$ARCHIVE/files/public/documents/shared-reference.pdf")" = 1
test "$(stat -c %h "$SOURCE/public/documents/shared-reference.pdf")" = 2
test "$(stat -c %i "$SOURCE/public/documents/shared-reference.pdf")" != "$(stat -c %i "$ARCHIVE/files/public/documents/shared-reference.pdf")"
test "$(stat -c %a "$ARCHIVE")" = 700
test "$(stat -c %a "$ARCHIVE/files/public/documents/normal.pdf")" = 600

# Reconcile references without SQL, service calls, permissions changes or serving files.
php8.4 -r '
require $argv[1];
$base=["source_table"=>"dossier_document","source_field"=>"path","dossier_id"=>101,"owner_exists"=>true,
"client_visible"=>false,"delegataire_visible"=>false,"payload_sha256"=>str_repeat("d",64)];
$rows=[["source_id"=>1,"path_base64"=>base64_encode("normal.pdf")]+$base,
["source_id"=>2,"path_base64"=>base64_encode("normal.pdf"),"dossier_id"=>102,"client_visible"=>true,"delegataire_visible"=>true]+$base,
["source_id"=>3,"path_base64"=>base64_encode("missing.pdf")]+$base];
\Cofrac2\Deploy\CoffracDocumentReconciliation::exportReferences($rows,["dump_sha256"=>str_repeat("a",64),
"dataset_sha256"=>str_repeat("b",64),"dossier_document_records"=>3],$argv[2]);
' "$HERE/lib/CoffracDocumentReconciliation.php" "$STATE/document-references-fixture"
bash "$HERE/reconcile-production-documents.sh" "$STATE/document-references-fixture" "$INVENTORY" "$(dirname "$REPORT")" > /tmp/reconciliation.log
RECONCILIATION=$(find "$STATE" -path '*/documents-reconciliation-*/report.json' -print -quit)
php8.4 -r '$r=json_decode(file_get_contents($argv[1]),true); if (!$r["complete"] || $r["records"]!==3 || $r["linked_records"]!==2
|| $r["unique_linked_files"]!==1 || $r["shared_file_references"]!==1 || $r["issues"]!==["missing_in_verified_inventory"=>1]
|| $r["serving_enabled"] || $r["database_links_verified"] || $r["final_cutover_ready"] || $r["copy_content_rehashed"]) exit(1);' "$RECONCILIATION"
test "$(stat -c %a "$ARCHIVE")" = 700
test "$(stat -c %a "$ARCHIVE/files/public/documents/normal.pdf")" = 600

# Exercise the same private staging entry point distributed to the operator.
PACKAGE=/home/ubuntu/reconciliation-package
install -d -m 0700 "$PACKAGE/lib" "$PACKAGE/references"
for file in stage-document-reconciliation.sh reconcile-production-documents.sh reconcile-documents.php lib/CoffracDocumentArchive.php lib/CoffracDocumentReconciliation.php; do
    install -m 0600 "$HERE/$file" "$PACKAGE/$file"
done
for file in references.jsonl summary.json SHA256SUMS; do
    install -m 0600 "$STATE/document-references-fixture/$file" "$PACKAGE/references/$file"
done
printf 'Disposable fixture only.\n' > "$PACKAGE/README.txt"
(cd "$PACKAGE"; sha256sum stage-document-reconciliation.sh reconcile-production-documents.sh reconcile-documents.php \
    lib/CoffracDocumentArchive.php lib/CoffracDocumentReconciliation.php \
    references/references.jsonl references/summary.json references/SHA256SUMS README.txt > SHA256SUMS)
bash "$PACKAGE/stage-document-reconciliation.sh" "$INVENTORY" "$(dirname "$REPORT")" > /tmp/staged-reconciliation.log
grep -q 'Rapprochement de preparation termine' /tmp/staged-reconciliation.log

# Diagnose only the unresolved reference, using lstat rather than original contents.
REVIEW_PACKAGE=/home/ubuntu/document-issue-review-package
install -d -m 0700 "$REVIEW_PACKAGE/lib"
for file in review-production-document-issues.sh review-document-issues.php lib/CoffracDocumentArchive.php lib/CoffracDocumentIssueReview.php; do
    install -m 0600 "$HERE/$file" "$REVIEW_PACKAGE/$file"
done
printf 'Disposable issue-review fixture only.\n' > "$REVIEW_PACKAGE/README.txt"
(cd "$REVIEW_PACKAGE"; sha256sum review-production-document-issues.sh review-document-issues.php \
    lib/CoffracDocumentArchive.php lib/CoffracDocumentIssueReview.php README.txt > SHA256SUMS)
bash "$REVIEW_PACKAGE/review-production-document-issues.sh" "$INVENTORY" "$(dirname "$REPORT")" "$(dirname "$RECONCILIATION")" > /tmp/document-issue-review.log
REVIEW=$(find "$STATE" -path '*/documents-issue-review-*/report.json' -print -quit)
php8.4 -r '$r=json_decode(file_get_contents($argv[1]),true); if (!$r["complete"] || $r["reviewed_records"]!==1
|| $r["diagnoses"]!==["not_in_inventory_and_missing_on_source_now"=>1] || $r["original_contents_read"]
|| $r["automatic_relinking"] || $r["serving_enabled"] || $r["final_cutover_ready"]) exit(1);' "$REVIEW"
test "$(stat -c %a "$ARCHIVE")" = 700
test "$(stat -c %a "$ARCHIVE/files/public/documents/normal.pdf")" = 600

# Detect corruption of equal size and timestamp. No validated report may be issued.
php8.4 -r '$p=$argv[1]; $s=stat($p); file_put_contents($p,str_repeat("X",$s["size"])); touch($p,$s["mtime"]);' "$ARCHIVE/files/public/documents/billed.pdf"
if bash "$SCRIPT" --verify-all-documents "$INVENTORY" > /tmp/corrupted-copy.log 2>&1; then exit 1; fi
php8.4 -r '$found=false; foreach(glob($argv[1]."/documents-run-*/issues.jsonl") as $p) $found=$found||str_contains(file_get_contents($p),"content_checksum_mismatch"); if (!$found) exit(1);' "$STATE"
# Explicit checksum comparison repairs same-size/same-mtime corruption without deleting originals.
bash "$SCRIPT" --repair-copy-all-documents "$INVENTORY" > /tmp/repaired-copy.log
bash "$SCRIPT" --verify-all-documents "$INVENTORY" > /tmp/reverified-copy.log

# Existing symlinks and hardlinks must be rejected BEFORE another transfer.
unlink "$ARCHIVE/files/public/documents/normal.pdf"
ln -s "$SOURCE/public/documents/normal.pdf" "$ARCHIVE/files/public/documents/normal.pdf"
if bash "$SCRIPT" --copy-all-documents "$INVENTORY" > /tmp/symlink-copy.log 2>&1; then exit 1; fi
unlink "$ARCHIVE/files/public/documents/normal.pdf"
link /tmp/outside-secret.txt "$ARCHIVE/files/public/documents/normal.pdf"
if bash "$SCRIPT" --copy-all-documents "$INVENTORY" > /tmp/hardlink-copy.log 2>&1; then exit 1; fi

sha256sum -c /tmp/original-content.sha256 > /tmp/source-unchanged.log
stat -c '%u %g %a %s %Y %Z %i %n' "$SOURCE/.env" "$SOURCE/public/index.php" "$SOURCE/public/documents/normal.pdf" > /tmp/after-metadata.txt
cmp /tmp/original-metadata.txt /tmp/after-metadata.txt
test ! -e /tmp/SOURCE-EXECUTED
test ! -e /tmp/UNEXPECTED-SERVICE-OR-SQL
printf 'OK : inventaire complet, reprise apres manque de place, hashes source/copie, droits prives, aucun hardlink/symlink accepte.\n'
printf 'OK : corruption detectee, originaux/metadonnees/configurations intacts, aucun appel SQL ou service.\n'
