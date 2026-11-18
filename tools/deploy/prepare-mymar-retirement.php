<?php

declare(strict_types=1);

// Independent operator tooling. No application release, credential, source dump or production access.
umask(0077);
$root = dirname(__DIR__, 2);
chdir($root);
try {
    if (PHP_SAPI !== 'cli' || $argc !== 1) {
        throw new RuntimeException('Lancer sans argument.');
    }
    $out = rtrim(getenv('HOME'), '/').'/Downloads/cofrac2-mymar-retire-link-'.bin2hex(random_bytes(5));
    if (! mkdir($out, 0700)) {
        throw new RuntimeException('Creation du paquet impossible.');
    }
    foreach (['MymarRosterActivation.php', 'MymarRosterRetirement.php', 'retire.php', 'retire-link.sh', 'retire-runtime-probe.php'] as $file) {
        if (! copy($root.'/tools/deploy/mymar/roster/'.$file, $out.'/'.$file)) {
            throw new RuntimeException('Copie du paquet impossible.');
        }
    }
    copy($root.'/tools/deploy/mymar/runtime/MymarRuntimeState.php', $out.'/MymarRuntimeState.php');
    $contract = [];
    foreach (['MymarActionSettings', 'MymarPilotConfiguration', 'MymarPilotSettings', 'MymarObservationSync',
        'MymarCachedObservation', 'MymarCalendarFeed', 'MymarCalendarLabelsSync', 'MymarBookingReaderSettings'] as $class) {
        $path = 'app/Services/Mymar/'.$class.'.php';
        $contract[$path] = hash_file('sha256', $root.'/'.$path);
    }
    file_put_contents($out.'/REQUEST.json', json_encode(['format' => 'cofrac.mymar-roster-retirement', 'version' => 1,
        'source_technician_id' => 17, 'cofrac_user_id' => 131, 'expected_before' => 23, 'runtime_contract' => $contract], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
    file_put_contents($out.'/README.txt', "Retrait explicite du seul lien MAR 17 -> Cofrac 131 (DAVID DHERY), compte Cofrac deja supprime.\n"
        ."Refus si le compte n'est plus supprime, si un autre lien est invalide, si une commande MAR reste en cours/incertaine, ou si le code ne correspond pas.\n"
        ."--audit : lecture native et des configurations, sans changement de configuration ni appel MYMAR.\n"
        ."--apply : sauvegarde root privee, reduction des cinq perimetres coherents, lecture GET du cache MAR, publication finale apres verification.\n"
        ."Aucun compte reactive/modifie, aucune habilitation/permission SQL ajoutee, aucun RDV ou dossier modifie, aucun document telecharge ni message envoye.\n"
        ."Aucun deploiement applicatif, import, maintenance, pull Git ou redemarrage de service. Les flags et jetons existants sont conserves.\n"
        ."En cas d'echec, restauration controlee de la configuration precedente ; si STOP, transmettre le code et le dossier de diagnostic prive.\n");
    $hashes = [];
    foreach (new DirectoryIterator($out) as $file) {
        if ($file->isDot()) {
            continue;
        }
        if (! $file->isFile() || $file->isLink()) {
            throw new RuntimeException('Fichier indirect refuse.');
        }
        chmod($file->getPathname(), 0600);
        $hashes[$file->getFilename()] = hash_file('sha256', $file->getPathname());
    }
    ksort($hashes);
    file_put_contents($out.'/SHA256SUMS', implode("\n", array_map(fn ($name, $sha) => "$sha  $name", array_keys($hashes), $hashes))."\n");
    echo "PACKAGE=$out\nMANIFEST_SHA256=".hash_file('sha256', $out.'/SHA256SUMS')."\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'STOP : '.$error->getMessage().PHP_EOL);
    exit(1);
}
