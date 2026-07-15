<?php

namespace App\Services;

use App\Models\ExternalDelegataire;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class CoffracDelegataireService
{
    public function isConfigured(): bool
    {
        return filled(config('services.coffrac.api_url'))
            && filled(config('services.coffrac.api_token'));
    }

    /**
     * @return array{synced:int,disabled:int,total:int}
     */
    public function sync(): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('API Coffrac non configurée.');
        }

        $response = $this->request()->get($this->endpoint('delegataires'));

        if ($response->failed()) {
            $payload = $response->json();
            $message = is_array($payload) && filled($payload['message'] ?? null)
                ? (string) $payload['message']
                : 'Impossible de récupérer les délégataires Coffrac.';

            throw new RuntimeException($message);
        }

        $remoteDelegataires = collect($response->json('data', []))
            ->filter(fn (mixed $delegataire): bool => is_array($delegataire) && filled($delegataire['id'] ?? null))
            ->map(fn (array $delegataire): array => $this->normalize($delegataire))
            ->filter(fn (array $delegataire): bool => $delegataire['name'] !== '')
            ->unique(fn (array $delegataire): string => $delegataire['source'].'|'.$delegataire['external_id'])
            ->values();

        return DB::transaction(function () use ($remoteDelegataires): array {
            $syncedAt = now();
            $externalIds = $remoteDelegataires->pluck('external_id')->all();

            foreach ($remoteDelegataires as $delegataire) {
                ExternalDelegataire::query()->updateOrCreate(
                    [
                        'source' => CoffracAppointmentService::SOURCE,
                        'external_id' => $delegataire['external_id'],
                    ],
                    [
                        'name' => $delegataire['name'],
                        'company_name' => $delegataire['company_name'],
                        'email' => $delegataire['email'],
                        'phone' => $delegataire['phone'],
                        'is_active' => $delegataire['is_active'],
                        'payload' => $delegataire['payload'],
                        'last_synced_at' => $syncedAt,
                    ],
                );
            }

            $disabled = ExternalDelegataire::query()
                ->where('source', CoffracAppointmentService::SOURCE)
                ->when($externalIds !== [], fn ($query) => $query->whereNotIn('external_id', $externalIds))
                ->update([
                    'is_active' => false,
                    'last_synced_at' => $syncedAt,
                    'updated_at' => $syncedAt,
                ]);

            return [
                'synced' => $remoteDelegataires->count(),
                'disabled' => $disabled,
                'total' => ExternalDelegataire::query()->where('source', CoffracAppointmentService::SOURCE)->count(),
            ];
        });
    }

    /**
     * @param array<string, mixed> $delegataire
     * @return array{source:string,external_id:string,name:string,company_name:?string,email:?string,phone:?string,is_active:bool,payload:array<string,mixed>}
     */
    private function normalize(array $delegataire): array
    {
        $companyName = $this->nullableString($delegataire['company_name'] ?? $delegataire['nom_societe'] ?? null);
        $fullName = trim(implode(' ', array_filter([
            $this->nullableString($delegataire['first_name'] ?? $delegataire['prenom'] ?? null),
            $this->nullableString($delegataire['last_name'] ?? $delegataire['name'] ?? null),
        ])));
        $email = $this->nullableString($delegataire['email'] ?? null);
        $name = $companyName ?: ($fullName !== '' ? $fullName : ($email ?: ''));

        return [
            'source' => CoffracAppointmentService::SOURCE,
            'external_id' => (string) $delegataire['id'],
            'name' => $name,
            'company_name' => $companyName,
            'email' => $email,
            'phone' => $this->nullableString($delegataire['phone'] ?? $delegataire['telephone'] ?? null),
            'is_active' => (bool) ($delegataire['is_active'] ?? $delegataire['actif'] ?? true),
            'payload' => $delegataire,
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function request(): PendingRequest
    {
        return Http::acceptJson()
            ->withToken((string) config('services.coffrac.api_token'))
            ->timeout((int) config('services.coffrac.timeout', 15))
            ->connectTimeout((int) config('services.coffrac.connect_timeout', 5))
            ->retry(2, 250, throw: false);
    }

    private function endpoint(string $path): string
    {
        return rtrim((string) config('services.coffrac.api_url'), '/').'/techcalendar/'.ltrim($path, '/');
    }
}
