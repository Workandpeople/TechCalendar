<?php

namespace App\Services\Capcee;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class CapceeClient
{
    private const HOSTS = [
        'uat2' => 'https://capcee--uat2.sandbox.my.salesforce.com',
        'production' => 'https://capcee.my.salesforce.com',
    ];

    public function assertConfigured(): void
    {
        $this->baseUrl();
    }

    public function findDocumentLink(string $documentId, string $parentId): ?string
    {
        $this->requireId($documentId, '069');
        $this->requireId($parentId);
        $records = $this->query("SELECT Id FROM ContentDocumentLink WHERE ContentDocumentId = '{$documentId}' AND LinkedEntityId = '{$parentId}' LIMIT 2");
        if ($records === []) {
            return null;
        }
        if (count($records) !== 1 || ! $this->validId($records[0]['Id'] ?? null, '06A')) {
            throw new CapceeApiException('La liaison documentaire CAPCEE est ambiguë ou invalide.');
        }

        return $records[0]['Id'];
    }

    public function resolveTarget(string $reference, string $objectType = 'Operation__c'): array
    {
        $this->validateReference($reference, 255);
        if (! in_array($objectType, ['Operation__c', 'Action__c'], true)) {
            throw new InvalidArgumentException('Type de cible CAPCEE invalide.');
        }
        $result = $this->request('GET', '/services/apexrest/getObjectByClientReference', ['ref' => $reference]);
        if (! array_is_list($result) || count($result) !== 1 || ! is_array($result[0]) || ($result[0]['sObjectType'] ?? null) !== $objectType) {
            throw new CapceeApiException('La référence EMMY doit correspondre à une seule cible CAPCEE du type attendu. Aucun rapport envoyé.');
        }
        $id = $result[0]['entityId'] ?? null;
        if (! $this->validId($id)) {
            throw new CapceeApiException('CAPCEE a renvoyé un identifiant de cible inexploitable.');
        }

        return ['id' => $id, 'object_type' => $objectType];
    }

    public function findReport(string $externalReference, string $parentId): ?array
    {
        $this->validateReference($externalReference, 100);
        $this->requireId($parentId);
        $records = $this->query("SELECT Id, ContentDocumentId, TECH_ParentId__c FROM ContentVersion WHERE IsLatest = true AND ExternalReference__c = '".$this->escapeSoql($externalReference)."' LIMIT 2");
        if ($records === []) {
            return null;
        }
        if (count($records) !== 1 || ($records[0]['TECH_ParentId__c'] ?? null) !== $parentId) {
            throw new CapceeApiException('La référence du rapport est ambiguë ou déjà liée à une autre opération CAPCEE.');
        }
        if (! $this->validId($records[0]['Id'] ?? null, '068') || ! $this->validId($records[0]['ContentDocumentId'] ?? null, '069')) {
            throw new CapceeApiException('CAPCEE a renvoyé des identifiants de rapport invalides.');
        }

        return ['version_id' => $records[0]['Id'], 'document_id' => $records[0]['ContentDocumentId']];
    }

    public function createReportVersion(#[\SensitiveParameter] array $metadata, #[\SensitiveParameter] string $pdf, string $parentId, ?string $documentId = null): string
    {
        $payload = (new CapceeReportPayload)->build($metadata, $pdf, $parentId, $documentId);
        $result = $this->request('POST', $this->dataPath('sobjects/ContentVersion'), $payload);

        return $this->createdId($result, '068');
    }

    public function documentIdForVersion(string $versionId): string
    {
        $this->requireId($versionId, '068');
        $records = $this->query("SELECT ContentDocumentId FROM ContentVersion WHERE Id = '{$versionId}' LIMIT 2");
        if (count($records) !== 1 || ! $this->validId($records[0]['ContentDocumentId'] ?? null, '069')) {
            throw new CapceeApiException('Impossible de retrouver le document CAPCEE de cette version. Ne pas recréer le rapport.');
        }

        return $records[0]['ContentDocumentId'];
    }

    public function linkDocument(string $documentId, string $parentId): string
    {
        $this->requireId($documentId, '069');
        $this->requireId($parentId);
        $result = $this->request('POST', $this->dataPath('sobjects/ContentDocumentLink'), [
            'ContentDocumentId' => $documentId,
            'LinkedEntityId' => $parentId,
            'ShareType' => 'V',
            'Visibility' => 'AllUsers',
        ]);

        return $this->createdId($result, '06A');
    }

    private function query(string $query): array
    {
        $result = $this->request('GET', $this->dataPath('query'), ['q' => $query]);
        $records = $result['records'] ?? null;
        if (! is_array($records) || ! array_is_list($records) || ($result['done'] ?? false) !== true
            || ($result['totalSize'] ?? null) !== count($records) || count(array_filter($records, 'is_array')) !== count($records)) {
            throw new CapceeApiException('Réponse de recherche CAPCEE incomplète ou invalide.');
        }

        return $records;
    }

    private function createdId(array $result, string $prefix): string
    {
        if (($result['success'] ?? null) !== true || ! $this->validId($result['id'] ?? null, $prefix) || ($result['errors'] ?? []) !== []) {
            throw new CapceeApiException('Réponse de création CAPCEE non vérifiable. Contrôler le résultat distant avant tout nouvel envoi.', outcomeUnknown: true);
        }

        return $result['id'];
    }

    private function request(string $method, string $path, #[\SensitiveParameter] array $payload): array
    {
        $base = $this->baseUrl();
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $response = $this->send($method, $base, $path, $payload, $this->token($base));
            if ($response->status() === 401) {
                Cache::forget($this->tokenKey($base));
                if ($attempt === 0) {
                    continue;
                }
            }
            $this->checkResponse($response, $method, $path);
            $data = $response->json();
            if (! is_array($data)) {
                throw new CapceeApiException('Réponse CAPCEE non JSON. Vérifier le résultat distant avant de relancer.', $response->status(), outcomeUnknown: $method === 'POST');
            }

            return $data;
        }
        throw new CapceeApiException('Authentification CAPCEE impossible.', 401);
    }

    private function token(string $base): string
    {
        $key = $this->tokenKey($base);
        if ($token = $this->cachedToken($key)) {
            return $token;
        }

        try {
            return Cache::lock($key.':lock', 40)->block(5, function () use ($key, $base): string {
                if ($token = $this->cachedToken($key)) {
                    return $token;
                }
                $path = '/services/oauth2/token';
                $response = $this->send('POST', $base, $path, [
                    'grant_type' => 'client_credentials',
                    'client_id' => config('capcee.client_id'),
                    'client_secret' => config('capcee.client_secret'),
                ], null);
                $this->checkResponse($response, 'POST', $path, authentication: true);
                $data = $response->json();
                if (! is_array($data) || ! is_string($data['access_token'] ?? null) || trim($data['access_token']) === ''
                    || preg_match('/[\r\n]/', $data['access_token']) || ! is_string($data['token_type'] ?? null)
                    || strcasecmp($data['token_type'], 'Bearer') !== 0 || ! is_string($data['instance_url'] ?? null)
                    || rtrim($data['instance_url'], '/') !== $base) {
                    throw new CapceeApiException('Réponse OAuth CAPCEE invalide ou instance inattendue. Jeton non utilisé.');
                }
                $ttl = max(30, min(900, (int) config('capcee.token_cache_seconds', 300)));
                if (isset($data['expires_in'])) {
                    if (! is_numeric($data['expires_in']) || (int) $data['expires_in'] <= 0) {
                        throw new CapceeApiException('Durée de validité du jeton CAPCEE invalide.');
                    }
                    $ttl = min($ttl, max(1, (int) $data['expires_in'] - 10));
                }
                Cache::put($key, Crypt::encryptString($data['access_token']), $ttl);

                return $data['access_token'];
            });
        } catch (LockTimeoutException) {
            throw new CapceeApiException('Authentification CAPCEE déjà en cours. Réessaie ultérieurement.', retryable: true);
        }
    }

    private function cachedToken(string $key): ?string
    {
        $value = Cache::get($key);
        if (! is_string($value)) {
            return null;
        }
        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            Cache::forget($key);

            return null;
        }
    }

    private function send(string $method, string $base, string $path, #[\SensitiveParameter] array $payload, #[\SensitiveParameter] ?string $token): Response
    {
        $timeout = $token !== null && $method === 'POST' ? (int) config('capcee.upload_timeout', 90) : (int) config('capcee.timeout', 30);
        // Keep authentication shorter than the token lock lease.
        $timeout = max(1, min($token === null ? 30 : 120, $timeout));
        $connectTimeout = max(1, min($timeout, 10, (int) config('capcee.connect_timeout', 5)));
        $request = Http::acceptJson()->withoutRedirecting()->connectTimeout($connectTimeout)->timeout($timeout);
        $request = $token === null ? $request->asForm() : $request->asJson()->withToken($token);
        $started = microtime(true);
        try {
            $response = $request->send($method, $base.$path, [
                $method === 'GET' ? 'query' : ($token === null ? 'form_params' : 'json') => $payload,
            ]);
        } catch (ConnectionException) {
            // Do not retain the HTTP exception: its context can contain the token or PDF.
            Log::warning('CAPCEE : connexion interrompue.', ['method' => $method, 'path' => $path]);
            $unknown = $token !== null && $method === 'POST';
            throw new CapceeApiException('Connexion CAPCEE interrompue. '.($unknown ? 'Envoi potentiellement reçu : vérifier avant de renvoyer.' : 'Réessaie ultérieurement.'), retryable: ! $unknown, outcomeUnknown: $unknown);
        }
        Log::log($response->successful() ? 'info' : 'warning', 'CAPCEE : réponse HTTP.', [
            'environment' => config('capcee.environment'), 'method' => $method, 'path' => $path,
            'http_status' => $response->status(), 'elapsed_ms' => (int) ((microtime(true) - $started) * 1000),
        ]);

        return $response;
    }

    private function checkResponse(Response $response, string $method, string $path, bool $authentication = false): void
    {
        if ($response->successful()) {
            return;
        }
        $code = $response->json('0.errorCode') ?? $response->json('error');
        $known = [
            'INVALID_SESSION_ID', 'REQUEST_LIMIT_EXCEEDED', 'INSUFFICIENT_ACCESS_OR_READONLY',
            'INVALID_FIELD', 'REQUIRED_FIELD_MISSING', 'INVALID_OR_NULL_FOR_RESTRICTED_PICKLIST',
            'DUPLICATE_VALUE', 'invalid_client', 'invalid_grant', 'unsupported_grant_type',
        ];
        $safeCode = in_array($code, $known, true) ? $code : 'REMOTE_ERROR';
        $transient = in_array($response->status(), [408, 429], true) || $response->serverError() || $safeCode === 'REQUEST_LIMIT_EXCEEDED';
        $unknown = ! $authentication && $method === 'POST' && ($response->serverError() || $response->status() === 408);
        throw new CapceeApiException(
            "CAPCEE : {$method} {$path} refusé (HTTP {$response->status()}, {$safeCode}).".($unknown ? ' Résultat distant à vérifier avant de renvoyer.' : ''),
            $response->status(), $transient && ! $unknown, $unknown,
        );
    }

    private function baseUrl(): string
    {
        if (! config('crm.v2_enabled') || ! config('capcee.enabled')) {
            throw new CapceeApiException('Le connecteur CAPCEE V2 est désactivé.');
        }
        $base = self::HOSTS[config('capcee.environment')] ?? null;
        if ($base === null || blank(config('capcee.client_id')) || blank(config('capcee.client_secret'))) {
            throw new CapceeApiException('Configuration CAPCEE incomplète ou environnement invalide.');
        }

        return $base;
    }

    private function tokenKey(string $base): string
    {
        return 'capcee:token:'.hash('sha256', $base.'|'.config('capcee.client_id').'|'.config('capcee.client_secret'));
    }

    private function dataPath(string $path): string
    {
        $version = config('capcee.api_version');
        if (! is_string($version) || ! preg_match('/\Av[0-9]{2,3}\.0\z/', $version)) {
            throw new CapceeApiException('Version API CAPCEE invalide.');
        }

        return '/services/data/'.$version.'/'.$path;
    }

    private function validId(mixed $id, string $prefix = ''): bool
    {
        return is_string($id) && preg_match('/\A(?:[A-Za-z0-9]{15}|[A-Za-z0-9]{18})\z/', $id) && str_starts_with($id, $prefix);
    }

    private function requireId(string $id, string $prefix = ''): void
    {
        if (! $this->validId($id, $prefix)) {
            throw new InvalidArgumentException('Identifiant Salesforce invalide.');
        }
    }

    private function validateReference(string $value, int $max): void
    {
        if (trim($value) === '' || mb_strlen($value) > $max || preg_match('/[\x00-\x1F\x7F]/', $value)) {
            throw new InvalidArgumentException('Référence CAPCEE invalide.');
        }
    }

    private function escapeSoql(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }
}
