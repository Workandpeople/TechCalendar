<?php

declare(strict_types=1);

use Cofrac2\Deploy\Mymar\MymarGatewayPreparation;

umask(0077);
ini_set('display_errors', '0');
try {
    $app = '/var/www/cofrac2';
    $state = '/var/lib/cofrac2-production';
    if (PHP_SAPI !== 'cli' || ! function_exists('posix_geteuid') || posix_geteuid() !== 0
        || explode('.', gethostname())[0] !== 'ns365601' || $argc !== 3 || $argv[1] !== '--prepare-disabled'
        || ! preg_match('/^[a-f0-9]{40}$/D', $argv[2]) || realpath(__FILE__) !== $app.'/tools/deploy/mymar/gateway/prepare-disabled.php'
        || realpath($app) !== $app || realpath($state) !== $state || (fileperms($state) & 0777) !== 0700 || fileowner($state) !== 0) {
        throw new RuntimeException('Controle reserve a la preparation MYMAR desactivee sur ns365601.');
    }
    $lock = fopen($state.'/runtime-operation.lock', 'c');
    if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Operation runtime deja en cours.');
    }
    $prepared = json_decode(file_get_contents($state.'/RUNTIME-PREPARED.json'), true, 32, JSON_THROW_ON_ERROR);
    if (($prepared['revision'] ?? null) !== $argv[2] || ! is_file($state.'/INTERNAL-SITE-OPEN.json')) {
        throw new RuntimeException('La release applicative doit etre validee et le site rouvert avant cette preparation.');
    }
    $parent = $state.'/mymar-gateway-prepared';
    if (! file_exists($parent) && ! is_link($parent) && ! mkdir($parent, 0700)) {
        throw new RuntimeException('Preparation privee impossible.');
    }
    if (is_link($parent) || fileowner($parent) !== 0) {
        throw new RuntimeException('Preparation privee inattendue.');
    }
    require __DIR__.'/MymarGatewayPreparation.php';
    $report = (new MymarGatewayPreparation)->stage($app, $parent, $argv[2]);
    echo json_encode(array_diff_key($report, ['files' => true]), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
    echo "Preparation MYMAR desactivee : $parent/{$argv[2]}\n";
    echo "Aucun compte SQL, secret, connexion, service, configuration Apache ou planning modifie.\n";
} catch (Throwable) {
    fwrite(STDERR, "STOP : preparation MYMAR non validee. Cofrac reste inchange ; aucun service ni envoi active.\n");
    exit(1);
}
