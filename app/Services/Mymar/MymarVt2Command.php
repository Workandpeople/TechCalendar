<?php

declare(strict_types=1);

namespace App\Services\Mymar;

use InvalidArgumentException;

/** Metadata only: the file is staged separately, never embedded in a JSON body. */
final readonly class MymarVt2Command
{
    public const MAXIMUM_BYTES = 1073741824;

    private function __construct(public array $payload) {}

    public static function fromArray(array $data): self
    {
        $fields = ['format', 'version', 'request_id', 'cofrac_actor_id', 'dossier_id', 'rdv_id', 'technician_id', 'expected_revision', 'sha256', 'bytes'];
        if (array_diff($fields, array_keys($data)) || array_diff(array_keys($data), $fields)
            || ($data['format'] ?? null) !== 'cofrac.mymar-vt2-command' || ($data['version'] ?? null) !== 1
            || ! MymarBookingCommand::isRequestId($data['request_id'] ?? null)) {
            throw new InvalidArgumentException('mymar.invalid_vt2_command');
        }
        foreach (['cofrac_actor_id', 'dossier_id', 'rdv_id', 'technician_id', 'bytes'] as $field) {
            if (! is_int($data[$field]) || $data[$field] < 1) {
                throw new InvalidArgumentException('mymar.invalid_vt2_command');
            }
        }
        foreach (['expected_revision', 'sha256'] as $field) {
            if (! is_string($data[$field]) || ! preg_match('/^[a-f0-9]{64}$/D', $data[$field])) {
                throw new InvalidArgumentException('mymar.invalid_vt2_command');
            }
        }
        if ($data['bytes'] < 8 || $data['bytes'] > self::MAXIMUM_BYTES) {
            throw new InvalidArgumentException('mymar.invalid_vt2_command');
        }
        ksort($data);

        return new self($data);
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode($this->payload, JSON_THROW_ON_ERROR));
    }
}
