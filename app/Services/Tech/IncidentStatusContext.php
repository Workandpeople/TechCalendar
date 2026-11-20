<?php

namespace App\Services\Tech;

use App\Models\TechnicianIncident;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class IncidentStatusContext
{
    public static function sameData(mixed $stored, array $expected): bool
    {
        // MySQL JSON normalizes object key order; retain strict scalar comparisons.
        return is_array($stored) && Arr::sortRecursive($stored) === Arr::sortRecursive($expected);
    }

    public static function issue(User $actor, TechnicianIncident $incident, array $data): string
    {
        return Crypt::encryptString(json_encode(['purpose' => 'incident-status-v1', 'actor' => $actor->id,
            'incident' => $incident->id, 'version' => $incident->version, 'source' => $incident->source,
            'issued' => now()->timestamp, 'request_id' => (string) Str::uuid(), 'data' => $data], JSON_THROW_ON_ERROR));
    }

    public static function read(User $actor, TechnicianIncident $incident, string $token): array
    {
        try {
            $data = json_decode(Crypt::decryptString($token), true, 12, JSON_THROW_ON_ERROR);
            if (($data['purpose'] ?? null) !== 'incident-status-v1' || ($data['actor'] ?? null) !== $actor->id
                || ($data['incident'] ?? null) !== $incident->id || ($data['source'] ?? null) !== $incident->source
                || ! is_int($data['version'] ?? null) || ! is_int($data['issued'] ?? null)
                || $data['issued'] > now()->timestamp || $data['issued'] < now()->subMinutes(15)->timestamp
                || ! is_array($data['data'] ?? null) || ! Str::isUuid($data['request_id'] ?? '')) {
                throw new \RuntimeException('Invalid context.');
            }

            return $data;
        } catch (Throwable) {
            throw ValidationException::withMessages(['context' => 'Cette fiche de décision est expirée ou invalide. Rechargez les statuts avant de réessayer.']);
        }
    }
}
