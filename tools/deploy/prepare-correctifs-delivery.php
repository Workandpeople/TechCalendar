<?php

declare(strict_types=1);

use Cofrac2\Deploy\ProductionAssetUpdate;

// Builds a private, pinned operator package from committed code; never reads .env or contacts production.
umask(0077);
$root = dirname(__DIR__, 2);
chdir($root);
function deliveryRun(array $arguments, ?string $directory = null, ?string $output = null): string
{
    $pipes = [];
    $process = proc_open($arguments, [0 => ['file', '/dev/null', 'r'], 1 => $output ? ['file', $output, 'a'] : ['pipe', 'w'],
        2 => $output ? ['file', $output, 'a'] : ['pipe', 'w']], $pipes, $directory,
        ['HOME' => getenv('HOME'), 'PATH' => getenv('PATH'), 'NODE_ENV' => 'production', 'LC_ALL' => 'C']);
    if (! is_resource($process)) {
        throw new RuntimeException('Lancement local impossible.');
    }
    $stdout = isset($pipes[1]) ? stream_get_contents($pipes[1]) : '';
    $stderr = isset($pipes[2]) ? stream_get_contents($pipes[2]) : '';
    foreach ($pipes as $pipe) {
        fclose($pipe);
    }
    if (proc_close($process) !== 0) {
        throw new RuntimeException('Commande locale refusee : '.$arguments[0].' '.substr($stderr, 0, 500));
    }

    return $stdout;
}
try {
    if (PHP_SAPI !== 'cli' || $argc < 2 || trim(deliveryRun(['git', 'branch', '--show-current'])) !== 'v2') {
        throw new RuntimeException('Usage : php tools/deploy/prepare-correctifs-delivery.php BASE_REVISION [AUTRES_BASES...] [--application-only] sur v2.');
    }
    $revision = trim(deliveryRun(['git', 'rev-parse', 'HEAD']));
    $arguments = array_slice($argv, 1);
    $applicationOnly = end($arguments) === '--application-only';
    if ($applicationOnly) {
        array_pop($arguments);
    }
    $bases = array_values(array_unique($arguments));
    if ($bases === []) {
        throw new RuntimeException('Au moins une revision de depart est requise.');
    }
    foreach ($bases as $base) {
        if (! preg_match('/^[a-f0-9]{40}$/D', $base) || $base === $revision) {
            throw new RuntimeException('Revisions de depart explicites requises.');
        }
        deliveryRun(['git', 'merge-base', '--is-ancestor', $base, $revision]);
        deliveryRun(['git', 'diff', '--quiet', $base, $revision, '--', 'composer.json', 'composer.lock']);
    }
    $out = rtrim(getenv('HOME'), '/').'/Downloads/'.($applicationOnly ? 'cofrac2-application-update-' : 'cofrac2-correctifs-mymar-').bin2hex(random_bytes(5));
    $work = sys_get_temp_dir().'/cofrac-correctifs-build-'.bin2hex(random_bytes(8));
    if (! mkdir($out, 0700) || ! mkdir($work, 0700)) {
        throw new RuntimeException('Creation des dossiers locaux impossible.');
    }
    deliveryRun(['git', 'archive', '--format=tar', '--output='.$work.'/source.tar', $revision]);
    mkdir($work.'/source', 0700);
    deliveryRun(['tar', '-xf', $work.'/source.tar', '-C', $work.'/source']);
    $source = $work.'/source';
    require $source.'/tools/deploy/lib/ProductionAssetUpdate.php';
    deliveryRun(['npm', 'ci', '--ignore-scripts', '--include=dev'], $source, $out.'-build.log');
    deliveryRun(['npm', 'run', 'build'], $source, $out.'-build.log');
    $cases = 'case "$BASE" in'."\n";
    foreach ($bases as $base) {
        $name = 'from-'.substr($base, 0, 8);
        $directory = $out.'/'.$name;
        mkdir($directory, 0700);
        deliveryRun(['cp', '-R', $source.'/public/build', $directory.'/build']);
        deliveryRun(['find', $directory, '-type', 'd', '-exec', 'chmod', '0700', '{}', '+']);
        deliveryRun(['find', $directory, '-type', 'f', '-exec', 'chmod', '0600', '{}', '+']);
        $sha = ProductionAssetUpdate::seal($directory, $revision, $base);
        $changes = deliveryRun(['git', 'diff', '--name-only', $base, $revision]);
        $changeFile = 'changes-from-'.substr($base, 0, 8).'.txt';
        file_put_contents($out.'/'.$changeFile, $changes);
        deliveryRun(['bash', $source.'/tools/deploy/update-production-release.sh', '--check-scope', $out.'/'.$changeFile]);
        $cases .= "    $base)\n        SOURCE=\"\$PACKAGE/$name\"\n        SHA=$sha\n"
            ."        EXPECTED=\"\$PACKAGE/$changeFile\"\n        EXPECTED_SHA=".hash('sha256', $changes)."\n        ;;\n";
    }
    $cases .= "    $revision)\n".<<<'CASE'
        [ "$(git_app rev-parse HEAD)" = "$BASE" ] && [ -f "$STATE/INTERNAL-SITE-OPEN.json" ] || stop 'release deja installee mais etat serveur a verifier.'
        php8.4 "$APP/tools/deploy/mymar/gateway/prepare-disabled.php" --prepare-disabled "$REV"
        printf 'Correctifs deja installes et preparation MYMAR desactivee verifiee.\n'
        exit 0
        ;;
    *) stop 'version serveur non prise en charge ; aucune maintenance demandee.' ;;
esac
CASE;
    $template = file_get_contents($source.'/tools/deploy/templates/production-release-launcher.sh.in');
    $launcher = strtr($template, ['@PACKAGE@' => '/home/ubuntu/'.basename($out), '@REVISION@' => $revision, '@BASE_SELECTION@' => $cases,
        '@RUNTIME_CHECK_SHA@' => hash_file('sha256', $source.'/tools/deploy/production-runtime.php')]);
    if (preg_match('/@[A-Z_]+@/', $launcher)) {
        throw new RuntimeException('Substitution du lanceur incomplete.');
    }
    if ($applicationOnly) {
        // Keep release checks/backups/reopening, but never rerun unrelated source preparations.
        $from = strpos($launcher, '# Confirm the new public reference using the runtime account, before closing V2.');
        $to = strpos($launcher, '# Run maintenance with the currently installed templates, before changing code.');
        $tail = strpos($launcher, "printf 'Correctifs adresses, recherche beneficiaire et commentaires deployes.");
        if ($from === false || $to === false || $tail === false || ! ($from < $to && $to < $tail)) {
            throw new RuntimeException('Structure de lanceur applicatif inattendue.');
        }
        $launcher = substr($launcher, 0, $from).substr($launcher, $to, $tail - $to)
            ."printf 'Correctif applicatif deploye ; les deux domaines sont ouverts.\\n'\n"
            ."printf 'Modes mail/SMS et integration MYMAR conserves. Actualiser le navigateur.\\n'\n";
        $launcher = str_replace('        php8.4 "$APP/tools/deploy/mymar/gateway/prepare-disabled.php" --prepare-disabled "$REV"'."\n", '', $launcher, $count);
        if ($count !== 1) {
            throw new RuntimeException('Chemin de reexecution applicatif inattendu.');
        }
        $launcher = str_replace('Correctifs deja installes et preparation MYMAR desactivee verifiee.', 'Correctif applicatif deja installe ; integration MYMAR inchangee.', $launcher);
    }
    file_put_contents($out.'/deploy.sh', $launcher);
    deliveryRun(['bash', '-n', $out.'/deploy.sh']);
    file_put_contents($out.'/revision.txt', $revision."\n");
    file_put_contents($out.'/README.txt', ($applicationOnly ? "Correctif applicatif uniquement ; integration MYMAR inchangee.\n" : "Correctifs applicatifs + preparation MYMAR DESACTIVEE.\n")
        ."Controles avant maintenance, sauvegarde V2, mise a jour ciblee, reouverture des deux domaines.\n"
        ."Aucun import, seeder, migration nouvelle, Node/Composer, message de test ou activation MYMAR.\n"
        ."Les modes mail/SMS et les identifiants SMTP sont conserves. Aucun compte SQL MYMAR cree.\n"
        .'Lancer depuis /home/ubuntu/'.basename($out)." : sha256sum -c SHA256SUMS && sudo bash ./deploy.sh\n"
        ."Si STOP avant maintenance : ne pas lancer de pull manuel ni fermer le site, transmettre le message.\n");
    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($out, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (! $file->isFile() || $file->isLink()) {
            throw new RuntimeException('Fichier indirect dans le paquet.');
        }
        chmod($file->getPathname(), 0600);
        $files[substr($file->getPathname(), strlen($out) + 1)] = hash_file('sha256', $file->getPathname());
    }
    ksort($files);
    file_put_contents($out.'/SHA256SUMS', implode("\n", array_map(static fn ($file, $sha) => "$sha  $file", array_keys($files), $files))."\n");
    echo "PACKAGE=$out\nREVISION=$revision\nBUILD_LOG=$out-build.log\n";
    deliveryRun(['rm', '-rf', '--', $work]);
} catch (Throwable $exception) {
    fwrite(STDERR, 'STOP : paquet non valide. '.$exception->getMessage().PHP_EOL);
    exit(1);
}
