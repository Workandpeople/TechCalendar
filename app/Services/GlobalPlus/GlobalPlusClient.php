<?php

namespace App\Services\GlobalPlus;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class GlobalPlusClient
{
    private const TOKEN_CACHE_KEY = 'global_plus:bearer_token';

    private const TOKEN_LOCK_KEY = 'global_plus:auth_token_lock';

    private const REFERENCE_CACHE_TTL_MINUTES = 30;

    private const ERROR_BODY_MAX_LENGTH = 500;

    public function isConfigured(): bool
    {
        return filled(config('services.global_plus.api_url'))
            && filled(config('services.global_plus.api_key'))
            && (int) config('services.global_plus.bureau_id') > 0;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function installers(): array
    {
        return Cache::remember('global_plus:installers', now()->addMinutes(self::REFERENCE_CACHE_TTL_MINUTES), function (): array {
            return collect($this->listPayload($this->get('Entreprise/Liste')))
                ->filter(fn (mixed $installer): bool => is_array($installer))
                ->map(fn (array $installer): ?array => $this->normalizeInstaller($installer))
                ->filter()
                ->values()
                ->all();
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function controllers(): array
    {
        return Cache::remember('global_plus:controllers', now()->addMinutes(self::REFERENCE_CACHE_TTL_MINUTES), function (): array {
            return collect($this->listPayload($this->get('Auth/Controllers')))
                ->filter(fn (mixed $controller): bool => is_array($controller))
                ->map(fn (array $controller): ?array => $this->normalizeController($controller))
                ->filter()
                ->values()
                ->all();
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function activeFormVersions(): array
    {
        return Cache::remember('global_plus:active_form_versions', now()->addMinutes(self::REFERENCE_CACHE_TTL_MINUTES), function (): array {
            return collect($this->listPayload($this->get('VersionFormulaire/GetVersionFormulaires/true')))
                ->filter(fn (mixed $version): bool => is_array($version))
                ->map(fn (array $version): ?array => $this->normalizeFormVersion($version))
                ->filter()
                ->values()
                ->all();
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function interventionTypes(?string $search = null, int $page = 1, int $pageSize = 100): array
    {
        $payload = $this->get('TypeIntervention/list', array_filter([
            'bureauId' => (int) config('services.global_plus.bureau_id'),
            'search' => $search,
            'page' => max(1, $page),
            'pageSize' => max(1, min(100, $pageSize)),
        ], fn (mixed $value): bool => $value !== null && $value !== ''));

        return collect($this->listPayload($payload))
            ->filter(fn (mixed $type): bool => is_array($type))
            ->values()
            ->all();
    }

    public function createDemand(array $payload): string
    {
        $responsePayload = $this->post('Demande', $payload);
        $demandId = $this->extractId($responsePayload);

        if ($demandId === null) {
            throw new GlobalPlusApiException('Global+ a créé le dossier, mais la réponse ne contient pas d’identifiant exploitable.');
        }

        return $demandId;
    }

    /**
     * @param  array<int, array{fileName:string,fileContent:string,category:string}>  $files
     */
    public function replaceDemandFiles(string $demandId, array $files): mixed
    {
        if (trim($demandId) === '') {
            throw new GlobalPlusApiException('Identifiant de dossier Global+ absent pour synchroniser les documents.');
        }

        return $this->put('Demande/changeDemandFiles/'.$demandId, array_values($files), true);
    }

    /**
     * @param  array<int, array{op:string,path:string,value:mixed}>  $operations
     */
    public function patchIntervention(string $interventionId, array $operations): mixed
    {
        if (trim($interventionId) === '') {
            throw new GlobalPlusApiException('Identifiant d’intervention Global+ absent pour modifier le RDV.');
        }

        return $this->patch('Intervention/Patch/'.$interventionId, array_values($operations));
    }

    public function forgetToken(): void
    {
        Cache::forget(self::TOKEN_CACHE_KEY);
    }

    private function get(string $path, array $query = []): mixed
    {
        return $this->request('GET', $path, $query);
    }

    private function post(string $path, array $payload = [], bool $upload = false): mixed
    {
        return $this->request('POST', $path, $payload, $upload);
    }

    private function put(string $path, array $payload = [], bool $upload = false): mixed
    {
        return $this->request('PUT', $path, $payload, $upload);
    }

    private function patch(string $path, array $payload = []): mixed
    {
        return $this->request('PATCH', $path, $payload);
    }

    private function request(string $method, string $path, array $payload = [], bool $upload = false, bool $retried = false): mixed
    {
        $this->ensureConfigured();

        $response = $this->baseRequest($upload)
            ->withToken($this->bearerToken())
            ->send($method, $this->endpoint($path), $method === 'GET'
                ? ['query' => $payload]
                : ['json' => $payload]);

        if ($response->status() === 401 && ! $retried) {
            $this->forgetToken();

            return $this->request($method, $path, $payload, $upload, true);
        }

        return $this->decodedResponse($response, 'Appel Global+ refusé.');
    }

    private function bearerToken(): string
    {
        $cachedToken = Cache::get(self::TOKEN_CACHE_KEY);

        if (is_string($cachedToken) && trim($cachedToken) !== '') {
            return $cachedToken;
        }

        try {
            $lock = Cache::lock(self::TOKEN_LOCK_KEY, 15);
        } catch (Throwable) {
            // Some cache drivers used in tests/local environments do not support locks reliably.
            return $this->authenticate();
        }

        try {
            return $lock->block(10, function (): string {
                $cachedToken = Cache::get(self::TOKEN_CACHE_KEY);

                if (is_string($cachedToken) && trim($cachedToken) !== '') {
                    return $cachedToken;
                }

                return $this->authenticate();
            });
        } catch (LockTimeoutException) {
            $cachedToken = Cache::get(self::TOKEN_CACHE_KEY);

            if (is_string($cachedToken) && trim($cachedToken) !== '') {
                return $cachedToken;
            }

            throw new GlobalPlusApiException('Authentification Global+ déjà en cours. Réessaie dans quelques secondes.');
        }
    }

    private function authenticate(): string
    {
        $this->ensureConfigured();

        $response = $this->baseRequest()
            ->post($this->endpoint('Auth/token'), [
                'apiKey' => (string) config('services.global_plus.api_key'),
            ]);

        $payload = $this->decodedResponse($response, 'Authentification Global+ impossible.');
        $token = is_array($payload) ? trim((string) ($payload['token'] ?? '')) : '';

        if ($token === '') {
            throw new GlobalPlusApiException('Global+ n’a pas renvoyé de token Bearer exploitable.');
        }

        Cache::put(self::TOKEN_CACHE_KEY, $token, now()->addDays(6));

        return $token;
    }

    private function baseRequest(bool $upload = false): PendingRequest
    {
        return Http::acceptJson()
            ->asJson()
            ->timeout((int) config($upload ? 'services.global_plus.upload_timeout' : 'services.global_plus.timeout', 45))
            ->connectTimeout((int) config('services.global_plus.connect_timeout', 5));
    }

    private function ensureConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new GlobalPlusApiException('API Global+ non configurée. Renseigne GLOBAL_PLUS_API_URL, GLOBAL_PLUS_API_KEY et GLOBAL_PLUS_BUREAU_ID.');
        }
    }

    private function endpoint(string $path): string
    {
        return rtrim((string) config('services.global_plus.api_url'), '/').'/api/'.ltrim($path, '/');
    }

    private function decodedResponse(Response $response, string $fallback): mixed
    {
        if ($response->failed()) {
            throw new GlobalPlusApiException($this->responseError($response, $fallback), $response->status());
        }

        $json = $response->json();

        if ($json !== null) {
            return $json;
        }

        $body = trim((string) $response->body());

        return $body !== '' ? trim($body, '"') : null;
    }

    private function responseError(Response $response, string $fallback): string
    {
        $payload = $response->json();

        if (is_array($payload)) {
            foreach (['message', 'Message', 'title', 'error'] as $key) {
                if (isset($payload[$key]) && is_string($payload[$key]) && trim($payload[$key]) !== '') {
                    return trim($payload[$key]);
                }
            }

            if (isset($payload['errors']) && is_array($payload['errors'])) {
                $firstError = collect($payload['errors'])->flatten()->first();

                if (is_string($firstError) && trim($firstError) !== '') {
                    return trim($firstError);
                }
            }
        }

        $body = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $response->body())));

        if ($body !== '') {
            return sprintf('%s Global+ a renvoyé HTTP %d: %s', $fallback, $response->status(), Str::limit($body, self::ERROR_BODY_MAX_LENGTH));
        }

        return sprintf('%s Global+ a renvoyé HTTP %d sans détail exploitable.', $fallback, $response->status());
    }

    /**
     * @return array<int, mixed>
     */
    private function listPayload(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        if (array_is_list($payload)) {
            return $payload;
        }

        foreach (['items', 'data', 'results', 'value'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                return $payload[$key];
            }
        }

        return [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeInstaller(array $installer): ?array
    {
        $address = is_array($installer['adresseEntreprise'] ?? null) ? $installer['adresseEntreprise'] : [];
        $addressId = $address['id'] ?? null;
        $label = trim((string) ($address['raisonSociale'] ?? $installer['raisonSociale'] ?? ''));

        if ($addressId === null || $label === '') {
            return null;
        }

        return [
            'id' => (string) ($installer['idEntreprise'] ?? $addressId),
            'address_id' => (int) $addressId,
            'label' => $label,
            'name' => $label,
            'siren' => trim((string) ($address['siren'] ?? '')) ?: null,
            'phone' => trim((string) ($address['phone'] ?? '')) ?: null,
            'address' => trim((string) ($address['adresse'] ?? '')) ?: null,
            'postal_code' => trim((string) ($address['codePostal'] ?? '')) ?: null,
            'city' => trim((string) ($address['ville'] ?? '')) ?: null,
            'blocked' => (bool) ($installer['blocageActif'] ?? false),
            'payload' => $installer,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeController(array $controller): ?array
    {
        $id = $controller['id'] ?? null;
        $email = trim((string) ($controller['email'] ?? ''));
        $name = trim(implode(' ', array_filter([
            trim((string) ($controller['prenom'] ?? '')),
            trim((string) ($controller['nom'] ?? '')),
        ])));

        if ($id === null || $email === '') {
            return null;
        }

        return [
            'id' => (int) $id,
            'email' => Str::lower($email),
            'name' => $name !== '' ? $name : $email,
            'active' => (bool) ($controller['etat'] ?? true),
            'payload' => $controller,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeFormVersion(array $version): ?array
    {
        $versionFormulaireId = (int) ($version['versionFormulaireId'] ?? 0);

        if ($versionFormulaireId <= 0) {
            return null;
        }

        $label = trim((string) ($version['libelle'] ?? $version['codeRapport'] ?? ''));
        $code = trim((string) ($version['codeRapport'] ?? $version['code'] ?? ''));

        if ($label === '' && $code === '') {
            return null;
        }

        return [
            'version_formulaire_id' => $versionFormulaireId,
            'type_intervention_id' => (int) ($version['idTypeIntervention'] ?? $version['id'] ?? 0),
            'label' => $label !== '' ? $label : $code,
            'code' => $code !== '' ? $code : $label,
            'planning_enabled' => (bool) ($version['enablePlanning'] ?? true),
            'payload' => $version,
        ];
    }

    private function extractId(mixed $payload): ?string
    {
        if (is_scalar($payload)) {
            $id = trim((string) $payload);

            return $id !== '' ? $id : null;
        }

        if (! is_array($payload)) {
            return null;
        }

        foreach (['id', 'demandId', 'demandeId', 'dossierId', 'data.id', 'data'] as $path) {
            $value = data_get($payload, $path);

            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }
}
