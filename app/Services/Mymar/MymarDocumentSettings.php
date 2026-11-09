<?php

namespace App\Services\Mymar;

use RuntimeException;

class MymarDocumentSettings
{
    public const DIRECTORY = '/etc/cofrac2-mymar-documents';

    public const UPLOADS = '/srv/cofrac2-mymar-documents/uploads';

    public function load(): ?array
    {
        $file = self::DIRECTORY.'/reader.json';
        if (! file_exists($file) && ! is_link($file)) {
            return null;
        }
        if (realpath(self::DIRECTORY) !== self::DIRECTORY || fileowner(self::DIRECTORY) !== 0 || (fileperms(self::DIRECTORY) & 0777) !== 0750
            || realpath($file) !== $file || ! is_file($file) || ! is_readable($file) || filesize($file) > 4096
            || fileowner($file) !== 0 || (fileperms($file) & 0777) !== 0640) {
            throw new RuntimeException('mymar.unsafe_document_settings');
        }

        return self::validate(json_decode(file_get_contents($file), true, 8, JSON_THROW_ON_ERROR));
    }

    public static function validate(array $data): ?array
    {
        if (count($data) !== 7 || ($data['format'] ?? null) !== 'cofrac.mymar-document-reader' || ($data['version'] ?? null) !== 1
            || ! is_bool($data['enabled'] ?? null) || ($data['writes_enabled'] ?? null) !== false
            || ! is_string($data['username'] ?? null) || ! preg_match('/^cofrac_mymar_dr_[a-f0-9]{8}$/D', $data['username'])
            || ! is_string($data['password'] ?? null) || ! preg_match('/^Md4![a-f0-9]{64}$/D', $data['password'])
            || ($data['uploads_root'] ?? null) !== self::UPLOADS) {
            throw new RuntimeException('mymar.invalid_document_settings');
        }

        return $data['enabled'] ? $data : null;
    }
}
