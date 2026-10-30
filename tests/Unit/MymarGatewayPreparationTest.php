<?php

use Cofrac2\Deploy\Mymar\MymarGatewayPreparation;

require_once __DIR__.'/../../tools/deploy/mymar/gateway/MymarGatewayPreparation.php';

function mymarPreparationFixture(): array
{
    $root = realpath(sys_get_temp_dir()).'/mymar-preparation-'.bin2hex(random_bytes(6));
    mkdir($root, 0700);
    mkdir($root.'/source', 0700);
    mkdir($root.'/output', 0700);
    foreach (MymarGatewayPreparation::FILES as $file) {
        if (! is_dir(dirname($root.'/source/'.$file))) {
            mkdir(dirname($root.'/source/'.$file), 0700, true);
        }
        file_put_contents($root.'/source/'.$file, str_ends_with($file, '.php') ? '<?php // synthetic fixture' : 'Synthetic fixture');
    }
    file_put_contents($root.'/source/.env', 'SECRET_CANARY');

    return [$root, $root.'/source', $root.'/output', str_repeat('b', 40)];
}

function removeMymarPreparationFixture(string $root): void
{
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) {
        $entry->isDir() && ! $entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($root);
}

it('stages only immutable gateway code with private permissions and no activation or credentials', function () {
    [$root, $source, $parent, $revision] = mymarPreparationFixture();
    try {
        $report = (new MymarGatewayPreparation)->stage($source, $parent, $revision);
        expect($report)->toMatchArray(['enabled' => false, 'configured' => false, 'sql_account_created' => false,
            'source_database_read' => false, 'services_changed' => false, 'planning_changed' => false]);
        expect(array_keys($report['files']))->toBe(MymarGatewayPreparation::FILES)
            ->and(file_exists($parent.'/'.$revision.'/.env'))->toBeFalse()
            ->and(fileperms($parent.'/'.$revision) & 0777)->toBe(0700)
            ->and(fileperms($parent.'/'.$revision.'/DISABLED.json') & 0777)->toBe(0600);
        foreach (MymarGatewayPreparation::FILES as $file) {
            expect(fileperms($parent.'/'.$revision.'/'.$file) & 0777)->toBe(0600);
        }
        expect((new MymarGatewayPreparation)->stage($source, $parent, $revision))->toBe($report);
    } finally {
        removeMymarPreparationFixture($root);
    }
});

it('refuses tampering or symlinks rather than overwriting an existing preparation', function (string $case) {
    [$root, $source, $parent, $revision] = mymarPreparationFixture();
    try {
        $relative = MymarGatewayPreparation::FILES[0];
        if ($case === 'source_link') {
            unlink($source.'/'.$relative);
            symlink($source.'/.env', $source.'/'.$relative);
        } else {
            (new MymarGatewayPreparation)->stage($source, $parent, $revision);
            if ($case === 'tampered') {
                file_put_contents($parent.'/'.$revision.'/'.$relative, 'SECRET_CANARY');
            } elseif ($case === 'marker_link') {
                unlink($parent.'/'.$revision.'/DISABLED.json');
                symlink($source.'/.env', $parent.'/'.$revision.'/DISABLED.json');
            } elseif ($case === 'extra_config') {
                file_put_contents($parent.'/'.$revision.'/config.json', 'SECRET_CANARY');
            } else {
                chmod($parent, 0755);
            }
        }
        expect(fn () => (new MymarGatewayPreparation)->stage($source, $parent, $revision))->toThrow(RuntimeException::class);
        expect(file_get_contents($source.'/.env'))->toBe('SECRET_CANARY');
    } finally {
        removeMymarPreparationFixture($root);
    }
})->with(['source_link', 'tampered', 'marker_link', 'public_parent', 'extra_config']);

it('refuses application-directory outputs and invalid revision identifiers', function () {
    [$root, $source, $parent, $revision] = mymarPreparationFixture();
    try {
        expect(fn () => (new MymarGatewayPreparation)->stage($source, $source, $revision))->toThrow(RuntimeException::class);
        expect(fn () => (new MymarGatewayPreparation)->stage($source, $parent, '../../secret'))->toThrow(RuntimeException::class);
    } finally {
        removeMymarPreparationFixture($root);
    }
});
