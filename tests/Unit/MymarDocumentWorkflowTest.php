<?php

use App\Services\Mymar\MymarVt2Command;
use Cofrac2\Deploy\Mymar\MymarDocumentFailure;
use Cofrac2\Deploy\Mymar\MymarDocumentFiles;
use Cofrac2\Deploy\Mymar\MymarDocumentPolicy;

require_once __DIR__.'/../../tools/deploy/mymar/documents/MymarDocumentFailure.php';
require_once __DIR__.'/../../tools/deploy/mymar/documents/MymarDocumentPolicy.php';
require_once __DIR__.'/../../tools/deploy/mymar/documents/MymarDocumentFiles.php';

function vt2TestCommand(array $changes = []): MymarVt2Command
{
    $pdf = "%PDF-1.4\nsynthetic document\n%%EOF\n";

    return MymarVt2Command::fromArray(array_replace(['format' => 'cofrac.mymar-vt2-command', 'version' => 1,
        'request_id' => '11223344-5566-4788-99aa-bbccddeeff00', 'cofrac_actor_id' => 1,
        'dossier_id' => 2, 'rdv_id' => 3, 'technician_id' => 4, 'expected_revision' => str_repeat('a', 64),
        'sha256' => hash('sha256', $pdf), 'bytes' => strlen($pdf)], $changes));
}

it('recognizes only unambiguous invoices and works quotes', function (string $name, ?string $kind) {
    expect(MymarDocumentPolicy::kind($name, 'Vincent MOHEDANO'))->toBe($kind);
})->with([
    ['FACTURE MOHEDANO', 'invoice'], ['FACTURE MOHEDANO (tamponnée)', 'invoice'],
    ['Facture travaux acquittée Vincent Mohedano', 'invoice'], ['DEVIS', null], ['DEVIS travaux', 'quote'],
    ['MOHEDANO DEVIS 2', 'quote'], ['FACTURE + DEVIS MOHEDANO', null],
    ['DEVIS MOHEDANO 2', 'quote'], ['Facture AMO', null], ['devis my mar', null],
    ['Attestation travaux (devis)', null], ['ATTESTATION + PLAN DE FINANCEMENT', null],
    ['CNI MOHEDANO', null], ['IMPOTS MOHEDANO', null], ['RAPPORT VT2', null],
    ['Facture honoraires MOHEDANO', null], ['Facture AMO travaux', null], ['CONTRAT', null],
    ['Facture inconnu', null], ['Facture MOHEDANO contrat', null], ["Facture\nMOHEDANO", null],
]);

it('rejects incomplete or overbroad VT2 commands and fingerprints every field', function () {
    $command = vt2TestCommand();
    foreach (['path' => '/etc/passwd', 'status_id' => 20, 'is_public' => true] as $key => $value) {
        expect(fn () => vt2TestCommand([$key => $value]))->toThrow(InvalidArgumentException::class);
    }
    foreach (['bytes' => 1073741825, 'dossier_id' => '2', 'cofrac_actor_id' => 0, 'sha256' => str_repeat('A', 64), 'request_id' => '../../invalid'] as $key => $value) {
        expect(fn () => vt2TestCommand([$key => $value]))->toThrow(InvalidArgumentException::class);
    }
    expect($command->fingerprint())->not->toBe(vt2TestCommand(['cofrac_actor_id' => 5])->fingerprint());
    expect(MymarVt2Command::fromArray(array_reverse($command->payload, true))->fingerprint())->toBe($command->fingerprint());
});

beforeEach(function () {
    $this->documentRoot = sys_get_temp_dir().'/mymar-files-'.bin2hex(random_bytes(8));
    mkdir($this->documentRoot, 0700);
    $this->documentRoot = realpath($this->documentRoot);
    mkdir($this->documentRoot.'/uploads', 0700);
    mkdir($this->documentRoot.'/staging', 0700);
    $this->files = new MymarDocumentFiles($this->documentRoot.'/uploads', $this->documentRoot.'/staging', reserveBytes: 0);
});

afterEach(function () {
    foreach (['uploads', 'staging'] as $directory) {
        foreach (scandir($this->documentRoot.'/'.$directory) as $name) {
            if (! in_array($name, ['.', '..'], true)) {
                unlink($this->documentRoot.'/'.$directory.'/'.$name);
            }
        }
        rmdir($this->documentRoot.'/'.$directory);
    }
    foreach (scandir($this->documentRoot) as $name) {
        if (! in_array($name, ['.', '..'], true)) {
            unlink($this->documentRoot.'/'.$name);
        }
    }
    rmdir($this->documentRoot);
});

it('stages a bounded stream then publishes one immutable PDF without overwriting a source file', function () {
    $command = vt2TestCommand();
    $input = fopen('php://temp', 'w+b');
    fwrite($input, "%PDF-1.4\nsynthetic document\n%%EOF\n");
    rewind($input);
    $this->files->stage($input, $command);
    fclose($input);
    $filename = $this->files->publish($command);
    expect($filename)->toStartWith('cofrac-vt2-')->toEndWith('.pdf')
        ->and($this->files->metadata($filename))->toBe(['bytes' => $command->payload['bytes']])
        ->and($this->files->publish($command))->toBe($filename)
        ->and(stat($this->documentRoot.'/uploads/'.$filename)['nlink'])->toBe(1);
    $stream = $this->files->open($filename);
    expect(stream_get_contents($stream))->toStartWith('%PDF-1.4');
    fclose($stream);
    expect(scandir($this->documentRoot.'/staging'))->toBe(['.', '..']);
});

it('can read with no staging directory while refusing every write entry point', function () {
    $reader = new MymarDocumentFiles($this->documentRoot.'/uploads');
    file_put_contents($this->documentRoot.'/uploads/read-only.pdf', "%PDF-1.4\nsynthetic\n%%EOF\n");
    expect($reader->metadata('read-only.pdf')['bytes'])->toBeGreaterThan(8);
    $stream = $reader->open('read-only.pdf');
    expect(stream_get_contents($stream))->toStartWith('%PDF-');
    fclose($stream);
    expect(fn () => $reader->stage(null, vt2TestCommand()))->toThrow(MymarDocumentFailure::class)
        ->and(fn () => $reader->preparePublication(vt2TestCommand()))->toThrow(MymarDocumentFailure::class)
        ->and(fn () => $reader->publish(vt2TestCommand()))->toThrow(MymarDocumentFailure::class);
});

it('rejects traversal URLs hidden files and non PDF extensions', function (string $name) {
    expect(fn () => $this->files->metadata($name))->toThrow(MymarDocumentFailure::class);
})->with(['../other.pdf', 'uploads/other.pdf', 'https://host/other.pdf', 'other%2Epdf', 'file.php', '.file.pdf', 'other..pdf', 'file.pdf:secret', "file\x00.pdf", 'a\\file.pdf']);

it('rejects symlinks hardlinks and renamed non PDF contents', function () {
    file_put_contents($this->documentRoot.'/outside.pdf', '%PDF-private canary');
    symlink($this->documentRoot.'/outside.pdf', $this->documentRoot.'/uploads/symlink.pdf');
    link($this->documentRoot.'/outside.pdf', $this->documentRoot.'/uploads/hardlink.pdf');
    file_put_contents($this->documentRoot.'/uploads/html.pdf', '<html>PRIVATE CANARY</html>');
    foreach (['symlink.pdf', 'hardlink.pdf', 'html.pdf'] as $name) {
        expect(fn () => $this->files->open($name))->toThrow(MymarDocumentFailure::class);
    }
});

it('rejects wrong length digest and content and removes only its own temporary upload', function (string $case) {
    $body = $case === 'content' ? '<html>not a pdf</html>' : "%PDF-1.4\nsynthetic document\n%%EOF\n";
    $changes = match ($case) {
        'length' => ['bytes' => strlen($body) - 1],
        'digest' => ['sha256' => str_repeat('f', 64)],
        default => ['sha256' => hash('sha256', $body), 'bytes' => strlen($body)],
    };
    file_put_contents($this->documentRoot.'/uploads/existing.pdf', '%PDF-source canary');
    $stream = fopen('php://temp', 'w+b');
    fwrite($stream, $body);
    rewind($stream);
    try {
        expect(fn () => $this->files->stage($stream, vt2TestCommand($changes)))->toThrow(MymarDocumentFailure::class);
    } finally {
        fclose($stream);
    }
    expect(scandir($this->documentRoot.'/staging'))->toBe(['.', '..'])
        ->and(file_get_contents($this->documentRoot.'/uploads/existing.pdf'))->toBe('%PDF-source canary');
})->with(['length', 'digest', 'content']);

it('rejects an insecure or overlapping staging directory and enforces the free space reserve', function () {
    chmod($this->documentRoot.'/staging', 0755);
    clearstatcache();
    expect(fn () => new MymarDocumentFiles($this->documentRoot.'/uploads', $this->documentRoot.'/staging'))->toThrow(MymarDocumentFailure::class);
    chmod($this->documentRoot.'/staging', 0700);
    clearstatcache();
    expect(fn () => new MymarDocumentFiles($this->documentRoot.'/uploads', $this->documentRoot.'/uploads'))->toThrow(MymarDocumentFailure::class);
    $files = new MymarDocumentFiles($this->documentRoot.'/uploads', $this->documentRoot.'/staging', PHP_INT_MAX - 1073741824);
    $stream = fopen('php://temp', 'w+b');
    try {
        expect(fn () => $files->stage($stream, vt2TestCommand()))->toThrow(MymarDocumentFailure::class);
    } finally {
        fclose($stream);
    }
});
