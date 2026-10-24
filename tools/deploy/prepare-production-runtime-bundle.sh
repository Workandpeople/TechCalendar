#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
HERE=$(cd -- "$(dirname -- "$0")" && pwd -P)
REPO=$(cd -- "$HERE/../.." && pwd -P)
cd "$REPO"
[ "$#" = 1 ] && [ -f "$1/manifest.json" ] || { echo 'Usage : prepare-production-runtime-bundle.sh IMPORT_PACKAGE_DIRECTORY' >&2; exit 1; }
IMPORT=$(cd -- "$1" && pwd -P)
[ "$(git branch --show-current)" = v2 ] && [ -z "$(git status --porcelain)" ] || { echo 'Checkout V2 propre requis.' >&2; exit 1; }
REVISION=$(git rev-parse HEAD)
OUT=$(mktemp -d "$HOME/Downloads/cofrac2-runtime-XXXXXXXX")
BUILD=$(mktemp -d)
trap 'rm -rf -- "$BUILD"' EXIT
git archive "$REVISION" app resources routes public package.json package-lock.json vite.config.js | tar -xf - -C "$BUILD"
# Build without the local .env or its VITE_* secrets/localhost values.
(cd "$BUILD" && env -i HOME="$HOME" PATH="$PATH" npm ci --ignore-scripts --include=dev \
    && env -i HOME="$HOME" PATH="$PATH" NODE_ENV=production npm run build) > "$OUT-build.log" 2>&1
install -d -m 0700 "$OUT/build" "$OUT/build/assets"
cp -R "$BUILD/public/build/." "$OUT/build/"
find "$OUT" -type d -exec chmod 0700 {} +
find "$OUT" -type f -exec chmod 0600 {} +
php -r '
    try {
    require $argv[1]."/vendor/autoload.php";
    require $argv[1]."/tools/deploy/lib/ProductionRuntimeBundle.php";
    $env=Dotenv\Dotenv::parse(file_get_contents($argv[1]."/.env"));$lines=[];
    foreach (Cofrac2\Deploy\ProductionRuntimeBundle::API_KEYS as $key) {
        $value=$env[$key]??match($key){"OPENAI_MODEL"=>"gpt-4o-mini","GLOBAL_PLUS_BUREAU_ID"=>"1035",default=>""};
        if(!is_string($value)||preg_match("/[\\x00-\\x1f\\x7f]/",$value))throw new RuntimeException("Configuration API invalide.");
        $lines[]=$key."=".json_encode($value,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    }
    file_put_contents($argv[2]."/runtime-apis.env",implode("\n",$lines)."\n");chmod($argv[2]."/runtime-apis.env",0600);
    $files=[];$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($argv[2],FilesystemIterator::SKIP_DOTS));
    foreach($iterator as $file){$name=substr($file->getPathname(),strlen($argv[2])+1);$files[$name]=["bytes"=>$file->getSize(),"sha256"=>hash_file("sha256",$file->getPathname())];}
    foreach (["MAPBOX_TOKEN","OPENAI_API_KEY","GLOBAL_PLUS_API_KEY"] as $key) {
        $secret=$env[$key]??"";
        foreach (array_keys($files) as $name) {
            if (str_starts_with($name,"build/") && strlen($secret)>8 && str_contains(file_get_contents($argv[2]."/".$name),$secret)) throw new RuntimeException("Secret API present dans un asset.");
        }
    }
    $source=json_decode(file_get_contents($argv[4]."/manifest.json"),true,64,JSON_THROW_ON_ERROR);
    $plan=json_decode(file_get_contents($argv[4]."/documents/report.json"),true,64,JSON_THROW_ON_ERROR);
    $documents=["inventory"=>$source["server_documents"]["inventory"],"verification"=>$source["server_documents"]["verification"],
      "plan_sha256"=>$source["document_plan_sha256"],"inventory_sha256"=>$plan["inventory_sha256"],"verification_sha256"=>$plan["verification_sha256"]];
    ksort($files);file_put_contents($argv[2]."/manifest.json",json_encode(["format"=>"cofrac2.internal-runtime","version"=>1,"revision"=>$argv[3],"import_package_sha256"=>hash_file("sha256",$argv[4]."/manifest.json"),"documents"=>$documents,"files"=>$files],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
    chmod($argv[2]."/manifest.json",0600);$sha=hash_file("sha256",$argv[2]."/manifest.json");new Cofrac2\Deploy\ProductionRuntimeBundle($argv[2],$sha);
    echo "BUNDLE=".$argv[2]."\nSHA256=".$sha."\nAucun secret affiche ; seuls les assets et les API approuvees sont inclus.\n";
    } catch (Throwable) {fwrite(STDERR,"Paquet runtime non valide. Aucun secret affiche.\n");exit(1);}
' "$REPO" "$OUT" "$REVISION" "$IMPORT"
