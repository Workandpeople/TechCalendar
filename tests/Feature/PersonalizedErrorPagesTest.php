<?php

use App\Models\User;
use App\Services\Support\ReportContext;
use App\Services\Support\SupportReportDelivery;
use App\Services\Support\SupportReports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
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
})->with([400, 401, 403, 404, 405, 413, 419, 422, 429, 500, 502, 503, 504]);

it('preserves native JSON errors and HTTP-specific headers', function () {
    $this->getJson('/__support-test/404')->assertNotFound()->assertJsonStructure(['message'])->assertDontSee('<html');
    $this->get('/__support-test/429')->assertTooManyRequests()->assertHeader('Retry-After', '42');
});

it('offers login from a 404 and redirects already connected users to their own workspace', function () {
    $this->get('/__support-test/404')->assertNotFound()->assertSee('href="/login"', false)->assertSee('Se connecter');
    $this->get('/login')->assertOk();
    $user = User::factory()->create(['role' => 2, 'admin' => false]);
    $this->actingAs($user)->get('/login')->assertRedirect(route('dashboard'));
    $this->get(route('dashboard'))->assertRedirect(route('tech.planning'));
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
    $token = supportErrorToken($this->get('/__support-test/404')->getContent());
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
