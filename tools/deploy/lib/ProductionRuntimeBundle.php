<?php

declare(strict_types=1);

namespace Cofrac2\Deploy;

use Dotenv\Dotenv;
use RuntimeException;

/** Only compiled public assets and a tightly allowlisted, private API configuration. */
final class ProductionRuntimeBundle
{
    public const API_KEYS = ['MAPBOX_TOKEN', 'OPENAI_API_KEY', 'OPENAI_MODEL', 'GLOBAL_PLUS_API_KEY', 'GLOBAL_PLUS_API_URL', 'GLOBAL_PLUS_BUREAU_ID'];

    public readonly array $manifest;

    public readonly array $apis;

    public function __construct(public readonly string $directory, string $sha256)
    {
        $this->privateFile('manifest.json');
        if (! preg_match('/^[a-f0-9]{64}$/D', $sha256) || ! hash_equals($sha256, hash_file('sha256', $directory.'/manifest.json'))) {
            throw new RuntimeException('Empreinte du paquet runtime differente.');
        }
        $this->manifest = json_decode(file_get_contents($directory.'/manifest.json'), true, 32, JSON_THROW_ON_ERROR);
        $manifest = $this->manifest;
        if (($manifest['format'] ?? null) !== 'cofrac2.internal-runtime' || ($manifest['version'] ?? null) !== 1
            || ! preg_match('/^[a-f0-9]{40}$/D', $manifest['revision'] ?? '') || ! is_array($manifest['files'] ?? null)
            || count($manifest['files']) < 4 || count($manifest['files']) > 1000
            || ! isset($manifest['files']['runtime-apis.env'], $manifest['files']['build/manifest.json'])) {
            throw new RuntimeException('Format du paquet runtime invalide.');
        }
        foreach (['inventory' => 'documents-inventory-', 'verification' => 'documents-run-'] as $key => $prefix) {
            if (! preg_match('#^/var/lib/cofrac2-production/'.preg_quote($prefix, '#').'[A-Za-z0-9_-]+$#D', $manifest['documents'][$key] ?? '')) {
                throw new RuntimeException('Chemin de preuve documentaire runtime inattendu.');
            }
        }
        foreach (['plan_sha256', 'inventory_sha256', 'verification_sha256'] as $key) {
            if (! preg_match('/^[a-f0-9]{64}$/D', $manifest['documents'][$key] ?? '')) {
                throw new RuntimeException('Empreinte documentaire runtime absente.');
            }
        }
        if (! preg_match('/^[a-f0-9]{64}$/D', $manifest['import_package_sha256'] ?? '')) {
            throw new RuntimeException('Preuve du paquet importe absente.');
        }
        foreach ($manifest['files'] as $name => $metadata) {
            if ($name !== 'runtime-apis.env' && ! preg_match('#^build/(?:assets/[A-Za-z0-9_-]+\.(?:js|css|woff2?|ttf|png|svg)|manifest\.json)$#D', $name)) {
                throw new RuntimeException('Chemin runtime non autorise.');
            }
            $this->privateFile($name);
            if (! is_int($metadata['bytes'] ?? null) || $metadata['bytes'] > 10 * 1024 * 1024
                || filesize($directory.'/'.$name) !== $metadata['bytes']
                || ! hash_equals($metadata['sha256'] ?? '', hash_file('sha256', $directory.'/'.$name))) {
                throw new RuntimeException('Contenu du paquet runtime modifie.');
            }
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $entry) {
            $name = substr($entry->getPathname(), strlen($directory) + 1);
            if ($entry->isLink() || ($entry->isDir() ? ! in_array($name, ['build', 'build/assets'], true)
                : (! $entry->isFile() || ($name !== 'manifest.json' && ! isset($manifest['files'][$name]))))) {
                throw new RuntimeException('Entree inattendue du paquet runtime.');
            }
        }
        $apiText = file_get_contents($directory.'/runtime-apis.env');
        if (str_contains($apiText, '$')) {
            throw new RuntimeException('Interpolation interdite dans les API du paquet runtime.');
        }
        $this->apis = Dotenv::parse($apiText);
        if (array_keys($this->apis) !== self::API_KEYS || $this->apis['GLOBAL_PLUS_API_URL'] !== 'https://cee-api.test.globalplus.fr') {
            throw new RuntimeException('Seules les API approuvees et Global+ TEST sont autorises.');
        }
        foreach ($this->apis as $value) {
            if (! is_string($value) || trim($value) === '' || str_contains($value, '$') || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                throw new RuntimeException('Configuration API manquante ou invalide.');
            }
        }
        $vite = json_decode(file_get_contents($directory.'/build/manifest.json'), true, 32, JSON_THROW_ON_ERROR);
        foreach (['resources/css/app.css', 'resources/js/app.js', 'resources/js/chart.js'] as $entry) {
            if (! isset($vite[$entry]['file'], $manifest['files']['build/'.$vite[$entry]['file']]) || ($vite[$entry]['src'] ?? null) !== $entry) {
                throw new RuntimeException('Asset Vite principal absent.');
            }
        }
    }

    private function privateFile(string $name): void
    {
        foreach (array_unique([$this->directory, dirname($this->directory.'/'.$name)]) as $directory) {
            if (is_link($directory) || realpath($directory) !== $directory || (fileperms($directory) & 0077) !== 0) {
                throw new RuntimeException('Repertoire runtime non prive.');
            }
        }
        $path = $this->directory.'/'.$name;
        if (! is_file($path) || is_link($path) || ! is_readable($path) || (fileperms($path) & 0077) !== 0) {
            throw new RuntimeException('Fichier runtime absent ou non prive.');
        }
    }
}
