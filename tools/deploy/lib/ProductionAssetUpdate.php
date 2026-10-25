<?php

declare(strict_types=1);

namespace Cofrac2\Deploy;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class ProductionAssetUpdate
{
    public readonly array $manifest;

    public readonly string $assetProbe;

    public function __construct(public readonly string $directory, string $digest)
    {
        if (is_link($directory) || realpath($directory) !== $directory || (fileperms($directory) & 0077) !== 0
            || ! preg_match('/^[a-f0-9]{64}$/D', $digest) || ! is_file($directory.'/manifest.json')
            || is_link($directory.'/manifest.json') || ! hash_equals($digest, hash_file('sha256', $directory.'/manifest.json'))) {
            throw new RuntimeException('Paquet de mise a jour prive ou empreinte invalide.');
        }
        $manifest = json_decode(file_get_contents($directory.'/manifest.json'), true, 32, JSON_THROW_ON_ERROR);
        if (($manifest['format'] ?? null) !== 'cofrac2.asset-update' || ($manifest['version'] ?? null) !== 1
            || ! preg_match('/^[a-f0-9]{40}$/D', $manifest['revision'] ?? '')
            || ! preg_match('/^[a-f0-9]{40}$/D', $manifest['from_revision'] ?? '')
            || ! is_array($manifest['files'] ?? null) || count($manifest['files']) < 4 || count($manifest['files']) > 1000) {
            throw new RuntimeException('Format de mise a jour invalide.');
        }
        $bytes = 0;
        foreach ($manifest['files'] as $name => $metadata) {
            if (! preg_match('#^build/(?:assets/[A-Za-z0-9_-]+\.(?:js|css|woff2?|ttf|png|svg)|manifest\.json)$#D', $name)
                || ! is_int($metadata['bytes'] ?? null) || $metadata['bytes'] < 0 || $metadata['bytes'] > 10 * 1024 ** 2
                || ! is_file($directory.'/'.$name) || is_link($directory.'/'.$name)
                || filesize($directory.'/'.$name) !== $metadata['bytes']
                || ! hash_equals($metadata['sha256'] ?? '', hash_file('sha256', $directory.'/'.$name))) {
                throw new RuntimeException('Asset absent, modifie ou non autorise.');
            }
            $bytes += $metadata['bytes'];
        }
        if ($bytes > 50 * 1024 ** 2) {
            throw new RuntimeException('Paquet de mise a jour trop volumineux.');
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $entry) {
            $name = substr($entry->getPathname(), strlen($directory) + 1);
            if ($entry->isLink() || ($entry->getPerms() & 0077) !== 0 || ($entry->isDir()
                ? ! in_array($name, ['build', 'build/assets'], true)
                : ! $entry->isFile() || ($name !== 'manifest.json' && ! isset($manifest['files'][$name])))) {
                throw new RuntimeException('Entree inattendue ou non privee dans le paquet.');
            }
        }
        $vite = json_decode(file_get_contents($directory.'/build/manifest.json'), true, 32, JSON_THROW_ON_ERROR);
        foreach (['resources/css/app.css', 'resources/js/app.js', 'resources/js/chart.js'] as $entry) {
            if (($vite[$entry]['src'] ?? null) !== $entry || ! isset($manifest['files']['build/'.($vite[$entry]['file'] ?? '')])) {
                throw new RuntimeException('Entree Vite principale absente.');
            }
        }
        $this->manifest = $manifest;
        $this->assetProbe = '/build/'.$vite['resources/css/app.css']['file'];
    }

    public static function seal(string $directory, string $revision, string $fromRevision): string
    {
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory.'/build', FilesystemIterator::SKIP_DOTS)) as $entry) {
            $name = substr($entry->getPathname(), strlen($directory) + 1);
            $files[$name] = ['bytes' => $entry->getSize(), 'sha256' => hash_file('sha256', $entry->getPathname())];
        }
        ksort($files);
        file_put_contents($directory.'/manifest.json', json_encode(['format' => 'cofrac2.asset-update', 'version' => 1,
            'revision' => $revision, 'from_revision' => $fromRevision, 'files' => $files], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        chmod($directory.'/manifest.json', 0600);
        $digest = hash_file('sha256', $directory.'/manifest.json');
        new self($directory, $digest);

        return $digest;
    }
}
