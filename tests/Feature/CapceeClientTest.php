<?php

use App\Services\Capcee\CapceeApiException;
use App\Services\Capcee\CapceeClient;
use App\Services\Capcee\CapceeReportPayload;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

function capceeMetadata(array $overrides = []): array
{
    return array_replace([
        'emmy_reference' => 'TPENE-ACT-1427466/OP-1931227',
        'report_reference' => 'RAPPORT-42',
        'issued_on' => '2026-10-13', 'inspected_on' => '2026-10-12',
        'inspection_type' => 'site', 'conclusion' => 'Satisfaisant',
        'object_type' => 'Operation__c', 'send_email' => false,
    ], $overrides);
}

beforeEach(function () {
    config([
        'crm.v2_enabled' => true, 'capcee.enabled' => true, 'capcee.environment' => 'uat2',
        'capcee.client_id' => 'test-client', 'capcee.client_secret' => 'test-secret',
        'cache.default' => 'array',
    ]);
    Cache::flush();
    Http::preventStrayRequests();
    Log::spy();
    $this->base = 'https://capcee--uat2.sandbox.my.salesforce.com';
    $this->token = [
        'access_token' => 'private-token', 'instance_url' => $this->base, 'token_type' => 'Bearer',
    ];
    $this->target = [['sObjectType' => 'Operation__c', 'entityId' => 'a0E5q000000NYbnEAG']];
    $this->pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF";
    $this->client = app(CapceeClient::class);
});

it('makes no requests while a required feature flag is disabled', function (string $flag) {
    config([$flag => false]);
    expect(fn () => $this->client->resolveTarget('EMMY-1'))->toThrow(CapceeApiException::class, 'désactivé');
    Http::assertNothingSent();
})->with(['crm.v2_enabled', 'capcee.enabled']);

it('rejects missing credentials and unknown destinations without sending secrets', function (array $settings) {
    config($settings);
    expect(fn () => $this->client->resolveTarget('EMMY-1'))->toThrow(CapceeApiException::class, 'Configuration');
    Http::assertNothingSent();
})->with([
    [['capcee.client_id' => '']], [['capcee.client_secret' => '']],
    [['capcee.environment' => 'https://other.example.test']],
]);

it('uses the documented OAuth flow and an encrypted environment-scoped cached token', function () {
    Http::fake([
        $this->base.'/services/oauth2/token' => Http::response($this->token),
        $this->base.'/services/apexrest/*' => Http::response($this->target),
    ]);
    $result = $this->client->resolveTarget('TPENE-ACT-1427466/OP-1931227');
    $this->client->resolveTarget('EMMY-2');
    expect($result)->toBe(['id' => 'a0E5q000000NYbnEAG', 'object_type' => 'Operation__c']);
    Http::assertSentCount(3);
    Http::assertSent(fn (Request $request) => $request->url() === $this->base.'/services/oauth2/token'
        && $request['grant_type'] === 'client_credentials' && $request['client_secret'] === 'test-secret'
        && $request->hasHeader('Content-Type', 'application/x-www-form-urlencoded'));
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'ref=TPENE-ACT-1427466%2FOP-1931227')
        && $request->hasHeader('Authorization', 'Bearer private-token'));
    $key = 'capcee:token:'.hash('sha256', $this->base.'|test-client|test-secret');
    expect(Cache::get($key))->not->toContain('private-token')
        ->and(Crypt::decryptString(Cache::get($key)))->toBe('private-token');
});

it('isolates production tokens and invalidates tokens after credential rotation', function () {
    $production = 'https://capcee.my.salesforce.com';
    Http::fake([
        $this->base.'/services/oauth2/token' => Http::response($this->token),
        $this->base.'/services/apexrest/*' => Http::response($this->target),
        $production.'/services/oauth2/token' => Http::response([...$this->token, 'instance_url' => $production]),
        $production.'/services/apexrest/*' => Http::response($this->target),
    ]);
    $this->client->resolveTarget('EMMY-1');
    config(['capcee.client_secret' => 'rotated-secret']);
    $this->client->resolveTarget('EMMY-1');
    config(['capcee.environment' => 'production']);
    $this->client->resolveTarget('EMMY-1');
    Http::assertSentCount(6);
});

it('rejects OAuth responses pointing at another instance', function () {
    Http::fake([$this->base.'/services/oauth2/token' => Http::response([...$this->token, 'instance_url' => 'https://other.example.test'])]);
    expect(fn () => $this->client->resolveTarget('EMMY-1'))->toThrow(CapceeApiException::class, 'instance inattendue');
    Http::assertSentCount(1);
});

it('does not follow OAuth redirects', function () {
    Http::fake([$this->base.'/services/oauth2/token' => Http::response('', 302, ['Location' => 'https://other.example.test'])]);
    expect(fn () => $this->client->resolveTarget('EMMY-1'))->toThrow(CapceeApiException::class, 'HTTP 302');
    Http::assertSentCount(1);
});

it('refreshes an expired access token once without an authentication loop', function (bool $stillUnauthorized) {
    Http::fake([
        $this->base.'/services/oauth2/token' => Http::sequence()->push($this->token)->push([...$this->token, 'access_token' => 'renewed-token']),
        $this->base.'/services/apexrest/*' => Http::sequence()->push([['errorCode' => 'INVALID_SESSION_ID']], 401)
            ->push($stillUnauthorized ? [['errorCode' => 'INVALID_SESSION_ID']] : $this->target, $stillUnauthorized ? 401 : 200),
    ]);
    if ($stillUnauthorized) {
        expect(fn () => $this->client->resolveTarget('EMMY-1'))->toThrow(CapceeApiException::class, 'HTTP 401');
    } else {
        expect($this->client->resolveTarget('EMMY-1')['id'])->toBe('a0E5q000000NYbnEAG');
    }
    Http::assertSentCount(4);
    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer renewed-token'));
})->with([false, true]);

it('never guesses between missing ambiguous or malformed targets', function (array $target) {
    Http::fake([
        $this->base.'/services/oauth2/token' => Http::response($this->token),
        $this->base.'/services/apexrest/*' => Http::response($target),
    ]);
    expect(fn () => $this->client->resolveTarget('EMMY-1'))->toThrow(CapceeApiException::class);
    Http::assertSentCount(2);
})->with([
    [[]],
    [[['sObjectType' => 'Action__c', 'entityId' => 'a0E5q000000NYbnEAG']]],
    [[['sObjectType' => 'Operation__c', 'entityId' => 'invalid']]],
    [[['sObjectType' => 'Operation__c', 'entityId' => 'a0E5q000000NYbnEAG'], ['sObjectType' => 'Operation__c', 'entityId' => 'a0E5q000000NYbnEAG']]],
]);

it('escapes SOQL literals and requires the report to belong to the expected target', function () {
    Http::fake([
        $this->base.'/services/oauth2/token' => Http::response($this->token),
        $this->base.'/services/data/v58.0/query*' => Http::response(['totalSize' => 1, 'done' => true, 'records' => [[
            'Id' => '0681x000001s2hHAAQ', 'ContentDocumentId' => '0691x000001rwSwAAI', 'TECH_ParentId__c' => 'a0E5q000000NYbnEAG',
        ]]]),
    ]);
    $result = $this->client->findReport("GC_ABC' OR Id != ''", 'a0E5q000000NYbnEAG');
    expect($result['document_id'])->toBe('0691x000001rwSwAAI');
    Http::assertSent(fn (Request $request) => ($request['q'] ?? null) === "SELECT Id, ContentDocumentId, TECH_ParentId__c FROM ContentVersion WHERE IsLatest = true AND ExternalReference__c = 'GC_ABC\\' OR Id != \\'\\'' LIMIT 2");
    expect(fn () => $this->client->findReport('GC_ABC', 'a0E5q000000NYbnEAZ'))->toThrow(CapceeApiException::class, 'autre opération');
});

it('only treats a verified empty search as a missing report', function (array $response, bool $empty) {
    Http::fake([
        $this->base.'/services/oauth2/token' => Http::response($this->token),
        $this->base.'/services/data/v58.0/query*' => Http::response($response),
    ]);
    if ($empty) {
        expect($this->client->findReport('GC_ABC', 'a0E5q000000NYbnEAG'))->toBeNull();
    } else {
        expect(fn () => $this->client->findReport('GC_ABC', 'a0E5q000000NYbnEAG'))->toThrow(CapceeApiException::class);
    }
})->with([
    [['totalSize' => 0, 'done' => true, 'records' => []], true],
    [['totalSize' => 0, 'done' => false, 'records' => []], false],
    [['totalSize' => 1, 'done' => true, 'records' => []], false],
    [['error' => 'unexpected payload'], false],
]);

it('maps site and contact reports with an explicit beneficiary email decision', function (string $type, string $documentType, string $controlType) {
    $payload = (new CapceeReportPayload)->build(capceeMetadata(['inspection_type' => $type]), $this->pdf, 'a0E5q000000NYbnEAG');
    expect($payload['TypeDocument__c'])->toBe($documentType)
        ->and($payload['TypeRapportInspection__c'])->toBe($controlType)
        ->and($payload['StatutEnvoiEmail__c'])->toBe('NePasEnvoyer')
        ->and($payload['ExternalReference__c'])->toBe('GC_RAPPORT-42')
        ->and($payload['TECH_ParentId__c'])->toBe('a0E5q000000NYbnEAG')
        ->and($payload['Origine__c'])->toBe('GENIUS Contrôle')
        ->and($payload['PathOnClient'])->toMatch('/\A[A-Za-z0-9-]+\.pdf\z/')
        ->and(base64_decode($payload['VersionData']))->toBe($this->pdf);
})->with([['site', 'RISITE', 'ControleSurSite'], ['contact', 'RINSPCON', 'ControleParContact']]);

it('retains the logical document and external reference for a new report version', function () {
    $payload = (new CapceeReportPayload)->build(capceeMetadata([
        'reason_for_change' => 'Correction du rapport', 'send_email' => true, 'object_type' => 'Action__c',
    ]), $this->pdf, 'a0E5q000000NYbnEAG', '0691x000001rwSwAAI');
    expect($payload['ContentDocumentId'])->toBe('0691x000001rwSwAAI')
        ->and($payload['ReasonForChange'])->toBe('Correction du rapport')
        ->and($payload['StatutEnvoiEmail__c'])->toBe('AEnvoyer')
        ->and($payload['Objet__c'])->toBe('Action')
        ->and($payload['ExternalReference__c'])->toBe('GC_RAPPORT-42');
});

it('validates metadata and refuses implicit email notifications or silent revisions', function () {
    foreach ([['send_email' => null], ['inspection_type' => 'unknown'], ['conclusion' => 'ok'], ['issued_on' => 'invalid'], ['inspected_on' => '2026-10-14']] as $invalid) {
        expect(fn () => (new CapceeReportPayload)->build(capceeMetadata($invalid), $this->pdf, 'a0E5q000000NYbnEAG'))
            ->toThrow(ValidationException::class);
    }
    expect(fn () => (new CapceeReportPayload)->build(capceeMetadata(), $this->pdf, 'a0E5q000000NYbnEAG', '0691x000001rwSwAAI'))
        ->toThrow(ValidationException::class);
    Http::assertNothingSent();
});

it('rejects non-PDF or oversized reports before any request', function () {
    expect(fn () => $this->client->createReportVersion(capceeMetadata(), '<script>evil</script>', 'a0E5q000000NYbnEAG'))
        ->toThrow(ValidationException::class);
    // Build the large fixture in place instead of allocating a second 35 MB copy.
    $pdf = str_repeat('0', CapceeReportPayload::MAX_PDF_BYTES + 1);
    foreach (str_split('%PDF-1.4') as $index => $character) {
        $pdf[$index] = $character;
    }
    expect(fn () => $this->client->createReportVersion(capceeMetadata(), $pdf, 'a0E5q000000NYbnEAG'))
        ->toThrow(ValidationException::class);
    Http::assertNothingSent();
});

it('bounds long report titles and sanitized filenames without dropping the extension', function () {
    $payload = (new CapceeReportPayload)->build(capceeMetadata(['emmy_reference' => str_repeat('é/ ', 85)]), $this->pdf, 'a0E5q000000NYbnEAG');
    expect(mb_strlen($payload['Title']))->toBeLessThanOrEqual(255)
        ->and(strlen($payload['PathOnClient']))->toBeLessThanOrEqual(255)
        ->and($payload['PathOnClient'])->toMatch('/\A[A-Za-z0-9-]+\.pdf\z/');
});

it('refuses encoding before exhausting the worker memory budget', function () {
    $previous = ini_get('memory_limit');
    try {
        ini_set('memory_limit', (string) (memory_get_usage(true) + 4 * 1024 * 1024));
        expect(fn () => (new CapceeReportPayload)->build(capceeMetadata(), $this->pdf, 'a0E5q000000NYbnEAG'))
            ->toThrow(ValidationException::class, 'Mémoire insuffisante');
    } finally {
        ini_set('memory_limit', $previous);
    }
    Http::assertNothingSent();
});

it('supports the documented creation document lookup and attachment sequence', function () {
    Http::fake([
        $this->base.'/services/oauth2/token' => Http::response($this->token),
        $this->base.'/services/data/v58.0/sobjects/ContentVersion' => Http::response(['id' => '0681x000001s2hHAAQ', 'success' => true, 'errors' => []], 201),
        $this->base.'/services/data/v58.0/query*' => Http::response(['totalSize' => 1, 'done' => true, 'records' => [['ContentDocumentId' => '0691x000001rwSwAAI']]]),
        $this->base.'/services/data/v58.0/sobjects/ContentDocumentLink' => Http::response(['id' => '06A1x000001s2hHAAQ', 'success' => true, 'errors' => []], 201),
    ]);
    $version = $this->client->createReportVersion(capceeMetadata(), $this->pdf, 'a0E5q000000NYbnEAG');
    $document = $this->client->documentIdForVersion($version);
    $link = $this->client->linkDocument($document, 'a0E5q000000NYbnEAG');
    expect($version)->toBe('0681x000001s2hHAAQ')->and($document)->toBe('0691x000001rwSwAAI')->and($link)->toBe('06A1x000001s2hHAAQ');
    Http::assertSentCount(4);
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/ContentDocumentLink')
        && $request['Visibility'] === 'AllUsers' && $request['ShareType'] === 'V');
});

it('does not replay a creation after an ambiguous server error', function () {
    Http::fake([
        $this->base.'/services/oauth2/token' => Http::response($this->token),
        $this->base.'/services/data/v58.0/sobjects/ContentVersion' => Http::response('private server trace', 500),
    ]);
    try {
        $this->client->createReportVersion(capceeMetadata(), $this->pdf, 'a0E5q000000NYbnEAG');
        $this->fail('Expected a remote error.');
    } catch (CapceeApiException $exception) {
        expect($exception->httpStatus)->toBe(500)->and($exception->outcomeUnknown)->toBeTrue()
            ->and($exception->retryable)->toBeFalse()->and($exception->getMessage())->not->toContain('private server trace');
    }
    Http::assertSentCount(2);
});

it('does not retry a timed-out creation and does not expose the transport exception', function () {
    Http::fake([
        $this->base.'/services/oauth2/token' => Http::response($this->token),
        $this->base.'/services/data/v58.0/sobjects/ContentVersion' => Http::failedConnection('private transport details'),
    ]);
    try {
        $this->client->createReportVersion(capceeMetadata(), $this->pdf, 'a0E5q000000NYbnEAG');
        $this->fail('Expected a connection error.');
    } catch (CapceeApiException $exception) {
        expect($exception->outcomeUnknown)->toBeTrue()->and($exception->retryable)->toBeFalse()
            ->and($exception->getPrevious())->toBeNull()
            ->and($exception->getMessage())->not->toContain('private transport details');
    }
    Http::assertSentCount(2);
});

it('marks an unverifiable successful creation as an unknown result', function () {
    Http::fake([
        $this->base.'/services/oauth2/token' => Http::response($this->token),
        $this->base.'/services/data/v58.0/sobjects/ContentVersion' => Http::response(['success' => true], 201),
    ]);
    try {
        $this->client->createReportVersion(capceeMetadata(), $this->pdf, 'a0E5q000000NYbnEAG');
        $this->fail('Expected an unverifiable response.');
    } catch (CapceeApiException $exception) {
        expect($exception->outcomeUnknown)->toBeTrue()->and($exception->retryable)->toBeFalse();
    }
    Http::assertSentCount(2);
});

it('distinguishes a read quota failure from a permissions refusal', function (string $code, bool $retryable) {
    Http::fake([
        $this->base.'/services/oauth2/token' => Http::response($this->token),
        $this->base.'/services/apexrest/*' => Http::response([['errorCode' => $code, 'message' => 'private response details']], 403),
    ]);
    try {
        $this->client->resolveTarget('EMMY-1');
        $this->fail('Expected a remote error.');
    } catch (CapceeApiException $exception) {
        expect($exception->retryable)->toBe($retryable)->and($exception->outcomeUnknown)->toBeFalse()
            ->and($exception->getMessage())->not->toContain('private response details');
    }
})->with([['REQUEST_LIMIT_EXCEEDED', true], ['INSUFFICIENT_ACCESS_OR_READONLY', false]]);

it('logs only safe request metadata without secrets reference or report content', function () {
    Http::fake([
        $this->base.'/services/oauth2/token' => Http::response($this->token),
        $this->base.'/services/data/v58.0/sobjects/ContentVersion' => Http::response(['id' => '0681x000001s2hHAAQ', 'success' => true], 201),
    ]);
    $this->client->createReportVersion(capceeMetadata(), $this->pdf, 'a0E5q000000NYbnEAG');
    Log::shouldHaveReceived('log')->withArgs(function ($level, $message, $context) {
        expect(array_keys($context))->toBe(['environment', 'method', 'path', 'http_status', 'elapsed_ms']);
        expect(json_encode($context))->not->toContain('private-token', 'test-secret', 'EMMY', 'VersionData', 'RAPPORT-42');

        return true;
    })->twice();
});
