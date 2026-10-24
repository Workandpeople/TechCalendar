<?php

declare(strict_types=1);

namespace Cofrac2\Deploy;

use DirectoryIterator;
use RuntimeException;
use Throwable;

/** Filesystem-only preparation: no Laravel bootstrap, SQL, source write or service operation. */
final class CoffracDocumentArchive
{
    public const ROOTS = ['public/documents', 'public/pdf', 'public/media', 'public/signature', 'public/lot_dossier', 'storage/app'];

    private const MANIFEST_FILES = ['inventory.jsonl', 'files.list0', 'documents.list0', 'exceptions.jsonl', 'summary.json'];

    private const DOCUMENT_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'jfif', 'png', 'gif', 'webp', 'heic', 'heif', 'tif', 'tiff', 'bmp',
        'doc', 'docx', 'xls', 'xlsx', 'xltx', 'ppt', 'pptx', 'odt', 'ods', 'rtf', 'txt', 'csv', 'zip', '7z', 'mp4', 'mov'];

    private const CODE_EXTENSIONS = ['php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'cgi', 'pl', 'py', 'sh', 'bash', 'js', 'mjs', 'ts', 'css', 'html', 'htm'];

    public static function inventory(string $source, string $output): array
    {
        $source = self::source($source);
        self::newDirectory($output, $source);
        $handles = [];
        foreach (['inventory.jsonl', 'files.list0', 'documents.list0', 'exceptions.jsonl'] as $name) {
            $handles[$name] = self::openNew($output.'/'.$name);
        }
        $summary = ['format' => 'cofrac2.document-inventory', 'version' => 1, 'complete' => false,
            'source' => $source, 'started_at_utc' => gmdate(DATE_ATOM), 'roots' => [],
            'selection' => 'all_statuses_including_billed_and_unreferenced_files',
            'files' => 0, 'bytes' => 0, 'largest_file_bytes' => 0, 'servable_documents' => 0,
            'empty_files' => 0, 'quarantined_files' => 0, 'source_hardlinked_files' => 0, 'exceptions' => [],
            'final_cutover_ready' => false, 'source_database_read' => false];
        $exception = static function (string $path, string $reason) use (&$summary, $handles): void {
            $summary['exceptions'][$reason] = ($summary['exceptions'][$reason] ?? 0) + 1;
            self::write($handles['exceptions.jsonl'], self::json(['path_base64' => base64_encode($path), 'reason' => $reason]));
        };
        try {
            foreach (self::ROOTS as $root) {
                $path = $source.'/'.$root;
                if (! file_exists($path) && ! is_link($path)) {
                    if ($root === 'public/documents') {
                        throw new RuntimeException('Racine documentaire principale absente.');
                    }
                    $summary['roots'][$root] = 'absent';
                    $exception($root, 'optional_root_absent');

                    continue;
                }
                self::regularPath($source, $root, true);
                $summary['roots'][$root] = 'inventoried';
                self::walk($source, $root, function (string $relative, array $stat) use (&$summary, $handles, $exception, $source): void {
                    $kind = $stat['mode'] & 0170000;
                    if ($kind === 0120000) {
                        $exception($relative, 'symlink_not_followed');

                        return;
                    }
                    if ($kind !== 0100000) {
                        $exception($relative, 'special_file_not_copied');

                        return;
                    }
                    $class = self::classify($relative);
                    if ($class === 'excluded') {
                        $exception($relative, 'code_configuration_or_metadata_not_copied');

                        return;
                    }
                    if (! is_readable($source.'/'.$relative)) {
                        throw new RuntimeException('Fichier source illisible ; inventaire incomplet.');
                    }
                    $servable = str_starts_with($relative, 'public/documents/') && $class === 'document' && $stat['size'] > 0;
                    $row = ['path_base64' => base64_encode($relative), 'class' => $class, 'servable' => $servable,
                        ...self::identity($stat)];
                    self::write($handles['inventory.jsonl'], self::json($row));
                    self::write($handles['files.list0'], $relative."\0");
                    if ($servable) {
                        self::write($handles['documents.list0'], substr($relative, strlen('public/documents/'))."\0");
                        $summary['servable_documents']++;
                    }
                    $summary['files']++;
                    $summary['bytes'] += $stat['size'];
                    $summary['largest_file_bytes'] = max($summary['largest_file_bytes'], $stat['size']);
                    $summary['empty_files'] += $stat['size'] === 0 ? 1 : 0;
                    $summary['source_hardlinked_files'] += $stat['nlink'] > 1 ? 1 : 0;
                    $summary['quarantined_files'] += $class === 'quarantine' ? 1 : 0;
                    if ($class === 'quarantine') {
                        $exception($relative, 'unknown_or_unsafe_name_quarantined');
                    }
                }, $exception);
            }
        } finally {
            foreach ($handles as $handle) {
                fclose($handle);
            }
        }
        $summary['complete'] = true;
        $summary['finished_at_utc'] = gmdate(DATE_ATOM);
        self::writeNew($output.'/summary.json', self::json($summary, true));
        $checksums = '';
        foreach (self::MANIFEST_FILES as $name) {
            $checksums .= hash_file('sha256', $output.'/'.$name).'  '.$name."\n";
        }
        self::writeNew($output.'/SHA256SUMS', $checksums);

        return $summary;
    }

    public static function validate(string $inventory): array
    {
        self::privateDirectory($inventory);
        self::privateFile($inventory.'/SHA256SUMS');
        $lines = file($inventory.'/SHA256SUMS', FILE_IGNORE_NEW_LINES);
        if (count($lines) !== count(self::MANIFEST_FILES)) {
            throw new RuntimeException('Manifeste incomplet.');
        }
        foreach (self::MANIFEST_FILES as $index => $name) {
            self::privateFile($inventory.'/'.$name);
            if (! preg_match('/^([a-f0-9]{64})  '.preg_quote($name, '/').'$/D', $lines[$index], $match)
                || ! hash_equals($match[1], hash_file('sha256', $inventory.'/'.$name))) {
                throw new RuntimeException('Manifeste modifie ou fichier remplace.');
            }
        }
        $summary = self::readJson($inventory.'/summary.json');
        if (($summary['format'] ?? '') !== 'cofrac2.document-inventory' || ($summary['version'] ?? null) !== 1
            || ($summary['complete'] ?? false) !== true || ($summary['final_cutover_ready'] ?? null) !== false
            || ($summary['selection'] ?? '') !== 'all_statuses_including_billed_and_unreferenced_files'
            || ! is_string($summary['source'] ?? null) || self::source($summary['source']) !== $summary['source']) {
            throw new RuntimeException('Format d inventaire inattendu.');
        }
        $files = $bytes = $servable = $largest = $empty = $quarantine = 0;
        $seen = [];
        $fileList = fopen($inventory.'/files.list0', 'rb');
        $documentList = fopen($inventory.'/documents.list0', 'rb');
        try {
            foreach (self::rows($inventory) as $row) {
                $relative = self::relative($row);
                $expectedClass = self::classify($relative);
                $expectedServable = str_starts_with($relative, 'public/documents/') && $expectedClass === 'document' && $row['size'] > 0;
                if (isset($seen[$relative]) || $expectedClass === 'excluded' || $row['class'] !== $expectedClass
                    || $row['servable'] !== $expectedServable
                    || fread($fileList, strlen($relative) + 1) !== $relative."\0") {
                    throw new RuntimeException('Chemin, classe ou liste de copie incoherent.');
                }
                $seen[$relative] = true;
                if ($expectedServable) {
                    $document = substr($relative, strlen('public/documents/'));
                    if (fread($documentList, strlen($document) + 1) !== $document."\0") {
                        throw new RuntimeException('Liste de documents incoherente.');
                    }
                    $servable++;
                }
                $files++;
                $bytes += $row['size'];
                $largest = max($largest, $row['size']);
                $empty += $row['size'] === 0 ? 1 : 0;
                $quarantine += $row['class'] === 'quarantine' ? 1 : 0;
            }
            if (fgetc($fileList) !== false || fgetc($documentList) !== false
                || $files !== $summary['files'] || $bytes !== $summary['bytes'] || $servable !== $summary['servable_documents']
                || $largest !== $summary['largest_file_bytes'] || $empty !== $summary['empty_files'] || $quarantine !== $summary['quarantined_files']) {
                throw new RuntimeException('Comptages ou listes incomplets.');
            }
        } finally {
            fclose($fileList);
            fclose($documentList);
        }
        $summary['inventory_sha256'] = hash_file('sha256', $inventory.'/SHA256SUMS');

        return $summary;
    }

    public static function prepareCopy(string $inventory, string $archive, int $reserveBytes): array
    {
        $summary = self::validate($inventory);
        self::archive($archive, $summary['source'], true);
        $additional = 0;
        foreach (self::rows($inventory) as $row) {
            $relative = self::relative($row);
            $source = self::regularPath($summary['source'], $relative);
            if (! self::sameIdentity($row, lstat($source))) {
                throw new RuntimeException('Source modifiee depuis l inventaire : refaire un inventaire, sans supprimer la copie.');
            }
            $target = self::destination($archive.'/files', $relative, false);
            $existing = is_file($target) ? lstat($target) : null;
            $additional += max(0, $row['size'] - ($existing['size'] ?? 0));
        }
        $free = (int) disk_free_space($archive);
        $required = $additional + $summary['largest_file_bytes'] + $reserveBytes;
        if ($reserveBytes < 0 || $free < $required) {
            throw new RuntimeException('Espace insuffisant pour la copie, les temporaires et la reserve.');
        }

        return ['inventory_sha256' => $summary['inventory_sha256'], 'files' => $summary['files'], 'bytes' => $summary['bytes'],
            'additional_bytes' => $additional, 'largest_file_bytes' => $summary['largest_file_bytes'],
            'reserve_bytes' => $reserveBytes, 'required_free_bytes' => $required, 'free_bytes' => $free,
            'source' => $summary['source'], 'archive' => $archive, 'final_cutover_ready' => false];
    }

    public static function verifyCopy(string $inventory, string $archive, string $reportDirectory): array
    {
        $summary = self::validate($inventory);
        self::archive($archive, $summary['source'], false);
        self::newDirectory($reportDirectory, $summary['source']);
        $checksums = self::openNew($reportDirectory.'/contents.jsonl');
        $errors = self::openNew($reportDirectory.'/issues.jsonl');
        $report = ['format' => 'cofrac2.document-copy-verification', 'version' => 1,
            'inventory_sha256' => $summary['inventory_sha256'], 'started_at_utc' => gmdate(DATE_ATOM),
            'files' => $summary['files'], 'bytes' => $summary['bytes'], 'verified_files' => 0,
            'verified_bytes' => 0, 'issues' => 0, 'extra_files_retained' => 0,
            'copy_verified' => false, 'database_links_verified' => false, 'final_cutover_ready' => false,
            'empty_files' => $summary['empty_files'], 'quarantined_files' => $summary['quarantined_files']];
        $seen = [];
        try {
            foreach (self::rows($inventory) as $row) {
                $relative = self::relative($row);
                $seen[$relative] = true;
                try {
                    $source = self::regularPath($summary['source'], $relative);
                    $target = self::destination($archive.'/files', $relative, true);
                    $sourceHash = self::stableHash($source, $row);
                    $targetStat = lstat($target);
                    if ($targetStat['size'] !== $row['size']) {
                        throw new RuntimeException('copy_size_mismatch');
                    }
                    $targetHash = self::stableHash($target, self::identity($targetStat));
                    if (! hash_equals($sourceHash, $targetHash)) {
                        throw new RuntimeException('content_checksum_mismatch');
                    }
                    // Catch a source replacement while the destination was being read.
                    $currentSource = self::regularPath($summary['source'], $relative);
                    if (! self::sameIdentity($row, lstat($currentSource))) {
                        throw new RuntimeException('source_changed_during_verification');
                    }
                    self::write($checksums, self::json(['path_base64' => $row['path_base64'], 'size' => $row['size'], 'sha256' => $sourceHash]));
                    $report['verified_files']++;
                    $report['verified_bytes'] += $row['size'];
                } catch (Throwable $error) {
                    $report['issues']++;
                    // Only trusted reason codes, never exception text containing source paths.
                    $reason = in_array($error->getMessage(), ['copy_size_mismatch', 'content_checksum_mismatch', 'source_changed_during_verification'], true)
                        ? $error->getMessage() : 'missing_changed_or_unsafe_file';
                    self::write($errors, self::json(['path_base64' => $row['path_base64'], 'reason' => $reason]));
                }
            }
            // Hashing can take hours: do not trust only the first files' initial stat.
            foreach (self::rows($inventory) as $row) {
                try {
                    $path = self::regularPath($summary['source'], self::relative($row));
                    if (! self::sameIdentity($row, lstat($path))) {
                        throw new RuntimeException('source_changed');
                    }
                } catch (Throwable) {
                    $report['issues']++;
                    self::write($errors, self::json(['path_base64' => $row['path_base64'], 'reason' => 'source_changed_after_content_verification']));
                }
            }
            self::walk($archive.'/files', '', function (string $relative, array $stat) use (&$report, $seen, $errors): void {
                if (($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1
                    || ($stat['mode'] & 0137) !== 0 || $stat['uid'] !== posix_geteuid()) {
                    $report['issues']++;
                    self::write($errors, self::json(['path_base64' => base64_encode($relative), 'reason' => 'unsafe_destination_entry']));
                }
                if (! isset($seen[$relative])) {
                    $report['extra_files_retained']++;
                    self::write($errors, self::json(['path_base64' => base64_encode($relative), 'reason' => 'extra_destination_entry_retained']));
                }
            }, static function (string $path, string $reason) use (&$report, $errors): void {
                $report['issues']++;
                self::write($errors, self::json(['path_base64' => base64_encode($path), 'reason' => $reason]));
            });
        } finally {
            fclose($checksums);
            fclose($errors);
        }
        // Recheck the manifest itself before issuing a verification result.
        if (self::validate($inventory)['inventory_sha256'] !== $summary['inventory_sha256']) {
            throw new RuntimeException('Inventaire modifie pendant le controle.');
        }
        $report['copy_verified'] = $report['issues'] === 0 && $report['verified_files'] === $report['files'] && $report['verified_bytes'] === $report['bytes'];
        $report['finished_at_utc'] = gmdate(DATE_ATOM);
        self::writeNew($reportDirectory.'/report.json', self::json($report, true));
        self::writeNew($reportDirectory.'/SHA256SUMS', implode('', array_map(
            fn (string $name) => hash_file('sha256', $reportDirectory.'/'.$name).'  '.$name."\n", ['contents.jsonl', 'issues.jsonl', 'report.json'])));

        return $report;
    }

    /** Reuse a sealed full verification for planning only, without rereading document contents. */
    public static function verifiedIndex(string $inventory, string $archive, string $verification): array
    {
        $summary = self::validate($inventory);
        self::archive($archive, $summary['source'], false);
        self::privateDirectory($verification);
        self::privateFile($verification.'/SHA256SUMS');
        $seal = file_get_contents($verification.'/SHA256SUMS');
        $lines = explode("\n", rtrim($seal, "\n"));
        $names = ['contents.jsonl', 'issues.jsonl', 'report.json'];
        if (count($lines) !== count($names)) {
            throw new RuntimeException('Verification documentaire incomplete.');
        }
        foreach ($names as $offset => $name) {
            self::privateFile($verification.'/'.$name);
            if (! preg_match('/^([a-f0-9]{64})  '.preg_quote($name, '/').'$/D', $lines[$offset], $matches)
                || ! hash_equals($matches[1], hash_file('sha256', $verification.'/'.$name))) {
                throw new RuntimeException('Verification documentaire modifiee.');
            }
        }
        $report = self::readJson($verification.'/report.json');
        if (($report['format'] ?? '') !== 'cofrac2.document-copy-verification' || ($report['version'] ?? null) !== 1
            || ($report['copy_verified'] ?? false) !== true || ($report['issues'] ?? null) !== 0
            || ($report['inventory_sha256'] ?? '') !== $summary['inventory_sha256']
            || ($report['files'] ?? null) !== $summary['files'] || ($report['bytes'] ?? null) !== $summary['bytes']
            || ($report['verified_files'] ?? null) !== $summary['files'] || ($report['verified_bytes'] ?? null) !== $summary['bytes']
            || ! is_string($report['finished_at_utc'] ?? null) || strtotime($report['finished_at_utc']) === false
            || ($report['final_cutover_ready'] ?? null) !== false || filesize($verification.'/issues.jsonl') !== 0) {
            throw new RuntimeException('Verification documentaire non conforme ou inventaire different.');
        }
        $index = [];
        $handle = fopen($verification.'/contents.jsonl', 'rb');
        try {
            foreach (self::rows($inventory) as $row) {
                $line = fgets($handle);
                $hash = $line === false ? [] : json_decode($line, true, 16, JSON_THROW_ON_ERROR);
                if (count($hash) !== 3 || ($hash['path_base64'] ?? '') !== $row['path_base64']
                    || ($hash['size'] ?? null) !== $row['size'] || ! str_ends_with($line ?: '', "\n")
                    || ! is_string($hash['sha256'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $hash['sha256'])) {
                    throw new RuntimeException('Empreinte documentaire absente ou incoherente.');
                }
                $relative = self::relative($row);
                $path = self::destination($archive.'/files', $relative, true);
                if (filesize($path) !== $row['size']) {
                    throw new RuntimeException('Copie modifiee depuis la verification.');
                }
                $index[$relative] = ['size' => $row['size'], 'class' => $row['class'],
                    'servable' => $row['servable'], 'sha256' => $hash['sha256']];
            }
            if (fgetc($handle) !== false) {
                throw new RuntimeException('Empreintes supplementaires inattendues.');
            }
        } finally {
            fclose($handle);
        }
        // This is not a fresh content check. Activation still requires a final differential verification.
        if (hash_file('sha256', $verification.'/SHA256SUMS') !== hash('sha256', $seal)
            || hash_file('sha256', $verification.'/contents.jsonl') !== substr($lines[0], 0, 64)
            || self::validate($inventory)['inventory_sha256'] !== $summary['inventory_sha256']) {
            throw new RuntimeException('Preuve documentaire modifiee pendant le rapprochement.');
        }

        return ['summary' => $summary, 'verification' => $report, 'verification_sha256' => hash('sha256', $seal), 'files' => $index];
    }

    private static function stableHash(string $path, array $expected): string
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('read_failed');
        }
        try {
            if (! self::sameIdentity($expected, fstat($handle))) {
                throw new RuntimeException('source_or_copy_changed');
            }
            $hash = hash_init('sha256');
            $bytes = hash_update_stream($hash, $handle);
            if ($bytes !== $expected['size'] || ! self::sameIdentity($expected, fstat($handle))) {
                throw new RuntimeException('source_or_copy_changed');
            }

            return hash_final($hash);
        } finally {
            fclose($handle);
        }
    }

    private static function source(string $source): string
    {
        $real = realpath($source);
        if (! $real || ! is_dir($real) || is_link($source)) {
            throw new RuntimeException('Source absente ou lien symbolique.');
        }

        return $real;
    }

    private static function newDirectory(string $path, string $source): void
    {
        $parent = realpath(dirname($path));
        if (! $parent || is_link(dirname($path)) || file_exists($path) || is_link($path)
            || $parent === $source || str_starts_with($parent, $source.'/') || ! mkdir($path, 0700)) {
            throw new RuntimeException('Nouvelle destination privee hors source requise.');
        }
        chmod($path, 0700);
    }

    private static function privateDirectory(string $path): void
    {
        if (! is_dir($path) || is_link($path) || (fileperms($path) & 0007) !== 0 || fileowner($path) !== posix_geteuid()) {
            throw new RuntimeException('Repertoire prive inattendu.');
        }
    }

    private static function privateFile(string $path): void
    {
        if (! is_file($path) || is_link($path) || (fileperms($path) & 0007) !== 0 || fileowner($path) !== posix_geteuid()) {
            throw new RuntimeException('Fichier prive inattendu.');
        }
    }

    private static function archive(string $archive, string $source, bool $create): void
    {
        if (! file_exists($archive) && ! is_link($archive) && $create) {
            self::newDirectory($archive, $source);
            self::writeNew($archive.'/ARCHIVE-IDENTITY.json', self::json(['format' => 'cofrac2.private-document-archive', 'version' => 1, 'source' => $source]));
            mkdir($archive.'/files', 0700);
        }
        self::privateDirectory($archive);
        self::privateFile($archive.'/ARCHIVE-IDENTITY.json');
        $identity = self::readJson($archive.'/ARCHIVE-IDENTITY.json');
        if ($identity !== ['format' => 'cofrac2.private-document-archive', 'version' => 1, 'source' => $source]
            || realpath($archive) === $source || str_starts_with(realpath($archive), $source.'/')) {
            throw new RuntimeException('Identite de l archive inattendue.');
        }
        self::privateDirectory($archive.'/files');
    }

    private static function destination(string $root, string $relative, bool $required): string
    {
        $path = $root;
        $parts = explode('/', $relative);
        foreach ($parts as $index => $part) {
            $path .= '/'.$part;
            clearstatcache(true, $path);
            if (is_link($path)) {
                throw new RuntimeException('Destination avec lien symbolique.');
            }
            if (! file_exists($path)) {
                if ($required) {
                    throw new RuntimeException('Destination absente.');
                }

                continue;
            }
            $stat = lstat($path);
            if ($stat['uid'] !== posix_geteuid() || ($stat['mode'] & 0007) !== 0) {
                throw new RuntimeException('Proprietaire ou permissions destination inattendus.');
            }
            if ($index === count($parts) - 1) {
                if (($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1 || ($stat['mode'] & 0111) !== 0) {
                    throw new RuntimeException('Destination non reguliere, executable ou hardlink.');
                }
            } elseif (($stat['mode'] & 0170000) !== 0040000) {
                throw new RuntimeException('Parent destination non regulier.');
            }
        }

        return $path;
    }

    private static function regularPath(string $root, string $relative, bool $directory = false): string
    {
        $path = $root;
        foreach (explode('/', $relative) as $part) {
            $path .= '/'.$part;
            clearstatcache(true, $path);
            if (is_link($path)) {
                throw new RuntimeException('Lien symbolique interdit.');
            }
        }
        if (realpath($path) !== $path || ($directory ? ! is_dir($path) : ! is_file($path))) {
            throw new RuntimeException('Chemin absent ou non regulier.');
        }

        return $path;
    }

    private static function relative(array $row): string
    {
        $path = base64_decode($row['path_base64'] ?? '', true);
        if (! is_string($path) || $path === '' || str_contains($path, "\0") || str_starts_with($path, '/')
            || array_filter(explode('/', $path), fn ($part) => $part === '' || $part === '.' || $part === '..')
            || ! array_filter(self::ROOTS, fn ($root) => str_starts_with($path, $root.'/'))) {
            throw new RuntimeException('Chemin de manifeste invalide.');
        }

        return $path;
    }

    private static function classify(string $relative): string
    {
        $parts = explode('/', $relative);
        $name = basename($relative);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (in_array(strtolower($name), ['.htaccess', '.htpasswd', '.user.ini', '.ds_store', '.gitignore'], true)
            || preg_match('/^\.env(?:\.|$)/i', $name) || in_array($extension, self::CODE_EXTENSIONS, true)) {
            return 'excluded';
        }
        if (in_array($extension, self::DOCUMENT_EXTENSIONS, true) && preg_match('//u', $relative)
            && ! preg_match('/[\x00-\x1f\x7f\\\\:]/', $relative)
            && ! array_filter($parts, fn ($part) => str_starts_with($part, '.'))) {
            return 'document';
        }

        return 'quarantine';
    }

    private static function walk(string $root, string $relative, callable $file, callable $exception): void
    {
        $root = realpath($root) ?: throw new RuntimeException('Racine de parcours absente.');
        if ($relative !== '') {
            self::regularPath($root, $relative, true);
        }
        $path = $relative === '' ? $root : $root.'/'.$relative;
        foreach (new DirectoryIterator($path) as $entry) {
            if ($entry->isDot()) {
                continue;
            }
            $child = ($relative === '' ? '' : $relative.'/').$entry->getFilename();
            $stat = lstat($root.'/'.$child);
            if ($stat === false) {
                throw new RuntimeException('Source modifiee pendant le parcours.');
            }
            if (($stat['mode'] & 0170000) === 0040000) {
                if (in_array($entry->getFilename(), ['.git', '.svn', '.rsync-partial'], true)) {
                    $exception($child, 'metadata_directory_not_traversed');

                    continue;
                }
                self::walk($root, $child, $file, $exception);
            } else {
                $file($child, $stat);
            }
        }
    }

    private static function identity(array $stat): array
    {
        return array_intersect_key($stat, array_flip(['size', 'mtime', 'ctime', 'ino', 'dev', 'mode', 'nlink']));
    }

    private static function sameIdentity(array $expected, array|false $actual): bool
    {
        if ($actual === false) {
            return false;
        }
        foreach (self::identity($actual) as $key => $value) {
            if (! array_key_exists($key, $expected) || $value !== $expected[$key]) {
                return false;
            }
        }

        return true;
    }

    private static function rows(string $inventory): \Generator
    {
        $handle = fopen($inventory.'/inventory.jsonl', 'rb');
        try {
            while (($line = fgets($handle)) !== false) {
                $row = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
                foreach (['size', 'mtime', 'ctime', 'ino', 'dev', 'mode', 'nlink'] as $key) {
                    if (! is_int($row[$key] ?? null) || (! in_array($key, ['mtime', 'ctime'], true) && $row[$key] < 0)) {
                        throw new RuntimeException('Metadonnees de fichier invalides.');
                    }
                }
                if (! is_bool($row['servable'] ?? null) || ! in_array($row['class'] ?? '', ['document', 'quarantine'], true)
                    || ($row['mode'] & 0170000) !== 0100000 || ! str_ends_with($line, "\n")) {
                    throw new RuntimeException('Entree d inventaire invalide.');
                }
                yield $row;
            }
            if (! feof($handle)) {
                throw new RuntimeException('Lecture d inventaire incomplete.');
            }
        } finally {
            fclose($handle);
        }
    }

    private static function openNew(string $path)
    {
        $handle = fopen($path, 'xb');
        if ($handle === false || ! chmod($path, 0600)) {
            throw new RuntimeException('Creation de fichier prive impossible.');
        }

        return $handle;
    }

    private static function write($handle, string $content): void
    {
        if (fwrite($handle, $content) !== strlen($content)) {
            throw new RuntimeException('Ecriture incomplete.');
        }
    }

    private static function writeNew(string $path, string $content): void
    {
        $handle = self::openNew($path);
        try {
            self::write($handle, $content);
        } finally {
            fclose($handle);
        }
    }

    private static function json(array $value, bool $pretty = false): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | ($pretty ? JSON_PRETTY_PRINT : 0))."\n";
    }

    private static function readJson(string $path): array
    {
        return json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
    }
}
