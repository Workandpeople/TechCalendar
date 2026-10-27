<?php

use App\Models\ApplicationSetting;
use App\Models\MailSender;
use App\Models\User;
use App\Services\Admin\SmtpConnectionProbe;
use App\Services\ApplicationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

function diagnosticMailSender(array $attributes): MailSender
{
    return MailSender::create($attributes + ['mail_host' => 'smtp.example.test', 'mail_port' => 587,
        'mail_from_address' => 'contact@example.test', 'mail_from_name' => 'Contact']);
}

beforeEach(function () {
    Cache::forget(ApplicationSettings::CACHE_KEY);
    Http::preventStrayRequests();
    Mail::fake();
    config(['crm.v2_enabled' => true, 'simulation.enabled' => false]);
    $this->admin = User::factory()->create(['admin' => true, 'role' => 0]);
    $this->actingAs($this->admin);
});

it('only allows an authenticated super administrator to run predefined tests', function () {
    $this->actingAs(User::factory()->create(['admin' => false, 'role' => 0]));
    $this->postJson(route('admin.settings.diagnostics'), ['check' => 'database'])->assertForbidden();
    $this->actingAs($this->admin);
    $this->postJson(route('admin.settings.diagnostics'), ['check' => 'https://localhost', 'url' => 'http://169.254.169.254'])->assertUnprocessable();
    $this->postJson(route('admin.settings.diagnostics'), ['check' => ['database']])->assertUnprocessable();
    $this->postJson(route('admin.settings.diagnostics'), ['check' => 'mail', 'mail_sender_id' => -1])->assertUnprocessable();
    Http::assertNothingSent();
});

it('does not run diagnostics while rendering the settings page or disclose secrets', function () {
    diagnosticMailSender(['name' => 'Contact SMTP', 'is_active' => true, 'mail_password' => 'smtp-do-not-render']);
    diagnosticMailSender(['name' => 'Inactive SMTP', 'is_active' => false]);
    $this->get(route('admin.settings'))->assertOk()->assertSee('Tests de connexion')
        ->assertSee('data-settings-search', false)->assertSee('Non renseignées')
        ->assertDontSee('data-diagnostic-mail-sender', false)->assertDontSee('Contact SMTP')->assertDontSee('Inactive SMTP')->assertDontSee('smtp-do-not-render');
    Http::assertNothingSent();
    Mail::assertNothingSent();
    Mail::assertNothingQueued();
});

it('runs readonly database and cache tests and returns uncached timed results', function (string $key) {
    $this->postJson(route('admin.settings.diagnostics'), ['check' => $key])->assertOk()
        ->assertJsonPath('key', $key)->assertJsonStructure(['status', 'message', 'duration_ms', 'checked_at'])
        ->assertHeader('Cache-Control', 'no-store, private');
    Http::assertNothingSent();
})->with(['database', 'cache']);

it('validates Mapbox tokens including an invalid token with HTTP 200', function (string $code, string $status) {
    config(['services.mapbox.token' => 'mapbox-secret']);
    Http::fake(['https://api.mapbox.com/tokens/v2*' => Http::response(['code' => $code])]);
    $this->postJson(route('admin.settings.diagnostics'), ['check' => 'mapbox'])->assertOk()
        ->assertJsonPath('status', $status)->assertDontSee('mapbox-secret');
    Http::assertSent(fn ($request) => $request->method() === 'GET' && $request['access_token'] === 'mapbox-secret');
})->with([['TokenValid', 'ok'], ['TokenRevoked', 'error'], ['TokenExpired', 'error']]);

it('uses recorded settings for an OpenAI readonly authentication check without generation', function () {
    app(ApplicationSettings::class)->update(['services.openai.api_key' => 'saved-openai-secret'], $this->admin->id);
    Http::fake(function ($request, $options) {
        expect($options['allow_redirects'])->toBeFalse()->and($options['verify'])->toBeTrue()
            ->and($options['timeout'])->toBe(8)->and($options['connect_timeout'])->toBe(3);

        return Http::response(['object' => 'list', 'data' => []]);
    });
    $this->postJson(route('admin.settings.diagnostics'), ['check' => 'openai', 'token' => 'untrusted-token'])->assertOk()
        ->assertJsonPath('status', 'ok')->assertDontSee('saved-openai-secret')->assertDontSee('untrusted-token');
    Http::assertSent(fn ($request) => $request->method() === 'GET'
        && $request->url() === 'https://api.openai.com/v1/models'
        && $request->hasHeader('Authorization', 'Bearer saved-openai-secret'));
    Http::assertSentCount(1);
});

it('redacts failed HTTP bodies and exception messages', function (int $code) {
    config(['services.openai.api_key' => 'secret-in-header']);
    Http::fake(['*' => Http::response(['error' => 'secret-in-body <script>alert(1)</script>'], $code)]);
    $response = $this->postJson(route('admin.settings.diagnostics'), ['check' => 'openai'])->assertOk()
        ->assertJsonPath('status', 'error')->assertDontSee('secret-in-body')->assertDontSee('secret-in-header')->assertDontSee('<script>');
    expect($response->json('message'))->toContain((string) $code);
})->with([401, 403, 429, 503]);

it('does not follow an HTTP redirect or treat an HTML login page as success', function (int $code) {
    config(['services.openai.api_key' => 'secret-in-header']);
    Http::fake(['*' => Http::response('<html>Login</html>', $code, ['Location' => 'http://127.0.0.1'])]);
    $this->postJson(route('admin.settings.diagnostics'), ['check' => 'openai'])->assertOk()->assertJsonPath('status', 'error');
    Http::assertSentCount(1);
})->with([200, 302]);

it('returns a safe timeout error without exposing a URL or token', function () {
    config(['services.mapbox.token' => 'timeout-secret']);
    Http::fake(fn () => throw new ConnectionException('https://example.test?token=timeout-secret'));
    $this->postJson(route('admin.settings.diagnostics'), ['check' => 'mapbox'])->assertOk()
        ->assertJsonPath('status', 'error')->assertDontSee('timeout-secret')->assertDontSee('https://example.test');
});

it('skips API tests when credentials are missing', function (string $key) {
    config(['services.mapbox.token' => null, 'services.openai.api_key' => null, 'services.global_plus.api_key' => null,
        'services.smsfactor.enabled' => true, 'services.smsfactor.token' => null, 'capcee.enabled' => true,
        'capcee.client_id' => null, 'capcee.client_secret' => null]);
    $this->postJson(route('admin.settings.diagnostics'), ['check' => $key])->assertOk()->assertJsonPath('status', 'warning');
    Http::assertNothingSent();
})->with(['mapbox', 'openai', 'global_plus', 'capcee', 'smsfactor']);

it('only authenticates Global Plus without creating data or changing its environment', function () {
    config(['services.global_plus.api_key' => 'gp-secret', 'services.global_plus.api_url' => 'https://cee-api.test.globalplus.fr']);
    Http::fake(['https://cee-api.test.globalplus.fr/api/Auth/token' => Http::response(['token' => 'gp-returned-token'])]);
    $this->postJson(route('admin.settings.diagnostics'), ['check' => 'global_plus'])->assertOk()
        ->assertJsonPath('status', 'ok')->assertDontSee('gp-secret')->assertDontSee('gp-returned-token')->assertSee('TEST');
    Http::assertSentCount(1);
    expect(config('services.global_plus.api_url'))->toBe('https://cee-api.test.globalplus.fr')
        ->and(Cache::has('global_plus:bearer_token'))->toBeFalse();
});

it('blocks unrecognised Global Plus destinations before sending credentials', function (string $base) {
    config(['services.global_plus.api_key' => 'gp-secret', 'services.global_plus.api_url' => $base]);
    $this->postJson(route('admin.settings.diagnostics'), ['check' => 'global_plus'])->assertOk()->assertJsonPath('status', 'warning');
    Http::assertNothingSent();
})->with(['http://cee-api.test.globalplus.fr', 'https://cee-api.test.globalplus.fr.evil.test', 'http://127.0.0.1', 'https://cee-api.test.globalplus.fr@evil.test']);

it('does not activate a disabled CAPCEE or SMSFactor connector', function (string $key) {
    config(['capcee.enabled' => false, 'services.smsfactor.enabled' => false]);
    $this->postJson(route('admin.settings.diagnostics'), ['check' => $key])->assertOk()->assertJsonPath('status', 'warning');
    Http::assertNothingSent();
    expect(config('capcee.enabled'))->toBeFalse()->and(config('services.smsfactor.enabled'))->toBeFalse();
})->with(['capcee', 'smsfactor']);

it('only authenticates CAPCEE when enabled and never persists the returned token', function () {
    config(['capcee.enabled' => true, 'capcee.environment' => 'uat2', 'capcee.client_id' => 'client-id', 'capcee.client_secret' => 'client-secret']);
    Http::fake(['https://capcee--uat2.sandbox.my.salesforce.com/services/oauth2/token' => Http::response(['access_token' => 'capcee-returned-token'])]);
    $this->postJson(route('admin.settings.diagnostics'), ['check' => 'capcee'])->assertOk()
        ->assertJsonPath('status', 'ok')->assertDontSee('client-secret')->assertDontSee('capcee-returned-token');
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request['grant_type'] === 'client_credentials');
    expect(DB::table('capcee_deliveries')->count())->toBe(0);
});

it('reads SMS credits without sending a simulated or real SMS', function () {
    config(['services.smsfactor.enabled' => true, 'services.smsfactor.mode' => 'simulate', 'services.smsfactor.token' => 'sms-secret']);
    Http::fake(['https://api.smsfactor.com/credits' => Http::response(['status' => 1, 'credits' => '100'])]);
    $this->postJson(route('admin.settings.diagnostics'), ['check' => 'smsfactor'])->assertOk()->assertJsonPath('status', 'ok')->assertDontSee('sms-secret');
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->method() === 'GET');
    expect(config('services.smsfactor.mode'))->toBe('simulate')->and(DB::table('dossier_sms')->count())->toBe(0);
});

it('checks SMTP with delivery disabled without sending or mutating the mailer', function () {
    config(['mail.default' => 'smtp', 'mail.delivery_enabled' => false, 'mail.mailers.smtp.host' => 'smtp.example.test']);
    $before = config('mail');
    $this->mock(SmtpConnectionProbe::class)->shouldReceive('check')->once()
        ->with(Mockery::on(fn ($config) => $config['host'] === 'smtp.example.test'))->andReturn(true);
    $this->postJson(route('admin.settings.diagnostics'), ['check' => 'mail'])->assertOk()->assertJsonPath('status', 'ok')
        ->assertJsonPath('message', fn ($message) => str_contains($message, 'La livraison réelle est désactivée sur ce serveur.'));
    expect(config('mail'))->toBe($before);
    Mail::assertNothingSent();
    Mail::assertNothingQueued();
});

it('checks an active stored mail sender and redacts failed SMTP details', function () {
    config(['crm.v2_enabled' => false]);
    $sender = diagnosticMailSender(['name' => 'Contact', 'is_active' => true, 'mail_password' => 'smtp-secret']);
    $this->mock(SmtpConnectionProbe::class)->shouldReceive('check')->once()
        ->with(Mockery::on(fn ($config) => $config['password'] === 'smtp-secret'))
        ->andThrow(new RuntimeException('Authentication refused: smtp-secret'));
    $this->postJson(route('admin.settings.diagnostics'), ['check' => 'mail', 'mail_sender_id' => $sender->id])->assertOk()
        ->assertJsonPath('status', 'error')->assertDontSee('smtp-secret');
    Mail::assertNothingSent();
});

it('never tests a disabled or deleted SMTP sender', function (bool $deleted) {
    config(['crm.v2_enabled' => false]);
    $sender = diagnosticMailSender(['name' => 'Inactive', 'is_active' => $deleted]);
    if ($deleted) {
        $sender->delete();
    }
    $this->mock(SmtpConnectionProbe::class)->shouldNotReceive('check');
    $this->postJson(route('admin.settings.diagnostics'), ['check' => 'mail', 'mail_sender_id' => $sender->id])->assertOk()->assertJsonPath('status', 'warning');
})->with([true, false]);

it('does not connect SMTP in an isolated simulation or with a log mailer', function (bool $simulation) {
    config(['simulation.enabled' => $simulation, 'simulation.allow_external_apis' => false, 'mail.default' => $simulation ? 'smtp' : 'log']);
    $this->mock(SmtpConnectionProbe::class)->shouldNotReceive('check');
    $this->postJson(route('admin.settings.diagnostics'), ['check' => 'mail'])->assertOk()->assertJsonPath('status', 'warning');
})->with([true, false]);

it('throttles repeated diagnostics', function () {
    for ($i = 0; $i < 20; $i++) {
        $this->postJson(route('admin.settings.diagnostics'), ['check' => 'database'])->assertOk();
    }
    $this->postJson(route('admin.settings.diagnostics'), ['check' => 'database'])->assertTooManyRequests();
});

it('loads all setting form rows with bounded queries and reports inactive values correctly', function () {
    config(['application_settings.definitions' => collect(range(1, 30))->mapWithKeys(fn ($i) => ['fixture.'.$i => [
        'group' => 'Fixture', 'label' => 'Fixture '.$i, 'fallback' => null,
    ]])->all()]);
    ApplicationSetting::create(['key' => 'fixture.1', 'group' => 'Fixture', 'label' => 'Inactive', 'is_active' => false, 'value' => 'inactive-secret']);
    ApplicationSetting::create(['key' => 'fixture.2', 'group' => 'Fixture', 'label' => 'Zero', 'is_active' => true, 'value' => '0']);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $rows = collect(app(ApplicationSettings::class)->formRows())->keyBy('key');
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();
    expect($count)->toBeLessThanOrEqual(3)->and($rows['fixture.1']['source'])->toBe('fallback')
        ->and($rows['fixture.1']['is_missing'])->toBeTrue()->and($rows['fixture.1']['value'])->toBeNull()
        ->and($rows['fixture.2']['is_missing'])->toBeFalse()->and($rows['fixture.2']['source'])->toBe('bdd');
});
