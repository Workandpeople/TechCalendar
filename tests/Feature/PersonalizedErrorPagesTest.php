<?php

use App\Models\User;
use App\Services\Support\ReportContext;
use App\Services\Support\SupportReportDelivery;
use App\Services\Support\SupportReports;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['app.debug' => false, 'cache.stores.file' => ['driver' => 'array']]);
    Http::preventStrayRequests();
    Mail::fake();
    $this->root = sys_get_temp_dir().'/cofrac-error-test-'.Str::uuid();
    $this->delivery = Mockery::mock(SupportReportDelivery::class);
    $this->delivery->shouldReceive('send')->andReturn('sent')->byDefault();
    $reports = Mockery::mock(SupportReports::class, [$this->delivery])->makePartial();
    $reports->shouldReceive('root')->andReturn($this->root);
    app()->instance(SupportReports::class, $reports);
    Route::get('/__support-test/{status}', function (int $status) {
        if ($status === 500) {
            throw new RuntimeException('NEVER-SEND: password=secret token=private SQL=personal');
        }
        abort($status, 'NEVER-SEND', ['Retry-After' => '42']);
    })->withoutMiddleware('web')->name('support.fixture');
});

afterEach(function () {
    Http::assertNothingSent();
    Mail::assertNothingOutgoing();
    if (is_dir($this->root)) {
        File::deleteDirectory($this->root);
    }
});

function supportErrorToken(string $html): string
{
    $dom = new DOMDocument;
    @$dom->loadHTML($html, LIBXML_NONET);

    return (new DOMXPath($dom))->query('//input[@name="error_token"]')->item(0)?->getAttribute('value') ?? '';
}

it('renders branded standalone error pages without sending automatically or exposing exception data', function (int $status) {
    $response = $this->get('/__support-test/'.$status.'?token=NEVER-SEND')->assertStatus($status)
        ->assertSee('Espace Cofrac')->assertSee('Envoyer au développeur')->assertSee('Référence')
        ->assertHeader('Cache-Control', 'no-store, private')->assertDontSee('NEVER-SEND')->assertDontSee('SQL=personal');
    $token = supportErrorToken($response->getContent());
    expect($token)->not->toBeEmpty();
    $context = app(ReportContext::class)->open($token);
    expect($context['page'])->toBe('/__support-test/{status}')->and($context['status'])->toBe($status);
    expect(json_encode($context))->not->toContain('password=secret', 'SQL=personal', '?token');
    $this->delivery->shouldNotHaveReceived('send');
})->with([400, 401, 403, 405, 413, 419, 422, 429, 500, 502, 503, 504]);

it('preserves native JSON errors and HTTP-specific headers', function () {
    $this->getJson('/__support-test/404')->assertNotFound()->assertJsonStructure(['message'])->assertDontSee('<html');
    $this->get('/__support-test/429')->assertTooManyRequests()->assertHeader('Retry-After', '42');
});

it('offers login from a 404 and redirects already connected users to their own workspace', function () {
    foreach (['/__support-test/404', '/old-nonexistent-url'] as $url) {
        $response = $this->get($url)->assertNotFound()->assertSee('href="/login"', false)->assertSee('Se connecter')
            ->assertSee('Retour à mon espace')->assertDontSee('Envoyer au développeur')->assertDontSee('Référence')
            ->assertDontSee('name="error_token"', false)->assertHeader('Cache-Control', 'no-store, private');
        expect(supportErrorToken($response->getContent()))->toBe('');
    }
    $this->delivery->shouldNotHaveReceived('send');
    $this->get('/login')->assertOk();
    $user = User::factory()->create(['role' => 2, 'admin' => false]);
    $this->actingAs($user)->get('/login')->assertRedirect(route('dashboard'));
    $this->get(route('dashboard'))->assertRedirect(route('tech.planning'));
});

it('rejects 404 reports from pages opened before the change without sending or storing them', function () {
    $context = app(ReportContext::class)->error(Request::create('/missing-page'), new RuntimeException('private'), 404);
    $token = app(ReportContext::class)->seal($context);
    $this->postJson(route('support.error'), ['error_token' => $token])->assertForbidden()
        ->assertJsonPath('message', 'Les pages introuvables ne sont pas transmises au développeur.');
    $this->delivery->shouldNotHaveReceived('send');
    expect(is_dir($this->root))->toBeFalse();
});

it('sends only after an explicit click and deduplicates repeated error submissions', function () {
    $token = supportErrorToken($this->get('/__support-test/500')->getContent());
    $this->postJson(route('support.error'), ['error_token' => $token, 'recipient' => 'attacker@example.test'])
        ->assertOk()->assertJsonPath('delivery', 'sent');
    $this->postJson(route('support.error'), ['error_token' => $token])->assertOk();
    $this->delivery->shouldHaveReceived('send')->once()->withArgs(function (array $report) {
        expect($report['exception_class'])->toBe(RuntimeException::class)->and($report['trace'])->not->toBeEmpty();
        expect(json_encode($report))->not->toContain('NEVER-SEND', 'password=', 'SQL=', 'attacker@example.test');

        return true;
    });
});

it('refuses absent altered and expired diagnostic tokens', function () {
    $token = supportErrorToken($this->get('/__support-test/500')->getContent());
    $this->postJson(route('support.error'), [])->assertUnprocessable();
    $this->postJson(route('support.error'), ['error_token' => $token.'bad'])->assertForbidden();
    $this->travel(31)->minutes();
    $this->postJson(route('support.error'), ['error_token' => $token])->assertForbidden();
    $this->delivery->shouldNotHaveReceived('send');
});

it('accepts a valid error diagnostic without database-backed sessions or SQL access', function () {
    $token = supportErrorToken($this->get('/__support-test/500')->getContent());
    config(['session.driver' => 'database']);
    $database = app('db');
    DB::shouldReceive('connection')->never();
    try {
        $this->postJson(route('support.error'), ['error_token' => $token])->assertOk();
    } finally {
        DB::swap($database);
    }
});

it('snapshots the connected account identity without SQL or collecting submitted form data', function () {
    $actor = User::factory()->make(['first_name' => 'Camille', 'last_name' => 'Martin', 'email' => 'camille@example.test']);
    $actor->id = 53;
    Auth::guard('web')->setUser($actor);
    $request = Request::create('/login?token=NEVER-COLLECT', 'POST', [
        'email' => 'submitted@example.test', 'password' => 'NEVER-COLLECT',
    ]);
    $database = app('db');
    DB::shouldReceive('connection')->never();
    try {
        $context = app(ReportContext::class)->error($request, new RuntimeException('NEVER-COLLECT'), 403);
        $token = app(ReportContext::class)->seal($context);
        expect($context)->toMatchArray(['actor_id' => 53, 'actor_name' => 'Camille Martin', 'actor_email' => 'camille@example.test']);
        expect(json_encode($context))->not->toContain('submitted@example.test', 'NEVER-COLLECT');
        expect($token)->not->toContain('camille@example.test', 'Camille Martin');

        Auth::guard('web')->forgetUser();
        config(['session.driver' => 'database']);
        $this->postJson(route('support.error'), ['error_token' => $token,
            'actor_id' => 999, 'actor_name' => 'Fake user', 'actor_email' => 'forged@example.test'])
            ->assertOk()->assertJsonPath('delivery', 'sent');
        $this->delivery->shouldHaveReceived('send')->once()->withArgs(function (array $report) {
            expect($report)->toMatchArray(['actor_id' => 53, 'actor_name' => 'Camille Martin', 'actor_email' => 'camille@example.test']);
            expect(json_encode($report))->not->toContain('forged@example.test', 'Fake user', 'submitted@example.test', 'NEVER-COLLECT');

            return true;
        });
    } finally {
        DB::swap($database);
    }
});

it('does not resolve a guest from the session or mistake a submitted email for an authenticated identity', function () {
    Auth::guard('web')->forgetUser();
    $provider = Mockery::mock(UserProvider::class);
    $provider->shouldNotReceive('retrieveById');
    Auth::guard('web')->setProvider($provider);
    $request = Request::create('/login', 'POST', ['email' => 'unverified@example.test']);
    $database = app('db');
    DB::shouldReceive('connection')->never();
    try {
        $context = app(ReportContext::class)->error($request, new RuntimeException('private'), 500);
        expect($context)->toMatchArray(['actor_id' => null, 'actor_name' => null, 'actor_email' => null]);
        expect(json_encode($context))->not->toContain('unverified@example.test');
    } finally {
        DB::swap($database);
    }
});

it('bounds and sanitizes the identity snapshot without publishing it on the error page', function () {
    $actor = User::factory()->make(['first_name' => str_repeat('É', 300), 'last_name' => "Martin\r\nInjected", 'email' => "camille@example.test\r\nInjected"]);
    $actor->id = 53;
    $this->actingAs($actor);
    $response = $this->get('/__support-test/500')->assertStatus(500)
        ->assertSee('Le nom et l’email du compte connecté')->assertDontSee('camille@example.test');
    $context = app(ReportContext::class)->open(supportErrorToken($response->getContent()));
    expect(mb_strlen($context['actor_name']))->toBeLessThanOrEqual(255);
    expect($context['actor_email'])->not->toContain("\r", "\n");
});

it('works without JavaScript and gives an honest receipt when mail cannot be delivered', function () {
    $this->delivery->shouldReceive('send')->once()->andReturn('failed');
    $token = supportErrorToken($this->get('/__support-test/500')->getContent());
    $this->post(route('support.error'), ['error_token' => $token])->assertServiceUnavailable()
        ->assertSee('Signalement conservé')->assertDontSee('a été envoyé au développeur');
});

it('limits public error reports with a private file-cache limiter', function () {
    for ($i = 0; $i < 6; $i++) {
        $token = app(ReportContext::class)->seal(app(ReportContext::class)->error(Request::create('/missing'), new RuntimeException('private'), 500));
        $response = $this->postJson(route('support.error'), ['error_token' => $token]);
        $i < 5 ? $response->assertOk() : $response->assertTooManyRequests();
    }
    $this->delivery->shouldHaveReceived('send')->times(5);
});
