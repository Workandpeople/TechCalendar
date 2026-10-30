#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
PROJECT=$(cd "$(dirname "$0")/../../.." && pwd -P)
WORK=$(mktemp -d /tmp/mymar-observation-test-XXXXXXXX)
mkdir -m 700 "$WORK/input" "$WORK/output"
php -n -r '
$root=$argv[1];
$files=[
 "summary.json"=>json_encode(["format"=>"cofrac.mymar-time-calibration","version"=>1,"complete"=>true,"sql_complete"=>true,"selected_source_unchanged"=>true,"database_writes"=>false]),
 "source-facts.json.gz"=>gzencode(json_encode(["format"=>"cofrac.mymar-time-source","version"=>1,"identity"=>["database"=>"MYMAR","sql_eligible"=>true]])),
 "reference.json"=>json_encode(["format"=>"cofrac.mymar-time-reference","version"=>1,"read_only"=>true,"collected_at_utc"=>gmdate(DATE_ATOM),
  "references"=>[["section"=>"reference","rdv_id"=>777,"dossier_id"=>91,"technician_id"=>26,"status_id"=>16,"is_old"=>0,"stored_start"=>"2026-11-02 09:30:00","raw_duration"=>2]]]),
];
$manifest="";
foreach($files as $file=>$value){ file_put_contents($root."/".$file,$value); $manifest.=hash("sha256",$value)."  ".$file."\n"; }
file_put_contents($root."/SHA256SUMS",$manifest);
' "$WORK/input"
TOOL="$PROJECT/tools/deploy/mymar/observation/observe-calibration.php"
php -n "$TOOL" "$WORK/input" "$WORK/output/observation.json" > "$WORK/stdout"
php -n -r '
$r=json_decode(file_get_contents($argv[1]),true,64,JSON_THROW_ON_ERROR);
if($r["counts"]["candidates"]!==1 || $r["candidates"][0]["duration_minutes"]!==120
 || $r["candidates"][0]["starts_at"]!=="2026-11-02T09:30:00+01:00"
 || $r["candidates"][0]["ends_at"]!=="2026-11-02T11:30:00+01:00"
 || $r["candidates"][0]["cofrac_user_id"]!==null || !$r["candidates"][0]["duration_estimated"]
 || $r["connector_enabled"] || $r["can_affect_planning"] || $r["planning_ready"] || $r["database_writes"] || $r["http_requests"]
 || $r["snapshot_complete"] || !in_array("partial_snapshot",array_column($r["issues"],"code"),true)
 || ((fileperms($argv[1])&0777)!==0600)) exit(1);
' "$WORK/output/observation.json"
if php -n "$TOOL" "$WORK/input" "$WORK/output/observation.json" > "$WORK/repeat.stdout" 2> "$WORK/repeat.stderr"; then
    echo 'Existing output was unexpectedly overwritten.' >&2; exit 1
fi
printf '\nCORRUPTED_PRIVATE_CANARY\n' >> "$WORK/input/reference.json"
if php -n "$TOOL" "$WORK/input" "$WORK/output/corrupted.json" > "$WORK/corrupted.stdout" 2> "$WORK/corrupted.stderr"; then
    echo 'Corrupt calibration was unexpectedly accepted.' >&2; exit 1
fi
test ! -e "$WORK/output/corrupted.json"
if grep -q 'PRIVATE_CANARY' "$WORK/corrupted.stderr" "$WORK/corrupted.stdout"; then exit 1; fi
rm "$WORK/input/SHA256SUMS"
ln -s "$WORK/input/reference.json" "$WORK/input/SHA256SUMS"
if php -n "$TOOL" "$WORK/input" "$WORK/output/symlink.json" > "$WORK/symlink.stdout" 2> "$WORK/symlink.stderr"; then
    echo 'Symlink manifest was unexpectedly accepted.' >&2; exit 1
fi
test ! -e "$WORK/output/symlink.json"
echo 'Observation hors ligne : horaires/durees provisoires, rapprochements non inventes, aucun bootstrap Laravel/PDO/cURL.'
echo 'Fichiers corrompus, manifestes indirects et ecrasement de sortie refuses.'
printf 'Diagnostic synthetique prive : %s\n' "$WORK"
