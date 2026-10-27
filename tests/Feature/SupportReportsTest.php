<?php

use App\Models\ExternalDelegataire;
use App\Models\User;
use App\Services\Support\SupportReportDelivery;
use App\Services\Support\SupportReports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true, 'app.debug' => false, 'cache.stores.file' => ['driver' => 'array']]);
    Http::preventStrayRequests();
    Mail::fake();
    $this->root = sys_get_temp_dir().'/cofrac-support-test-'.Str::uuid();
    $this->delivery = Mockery::mock(SupportReportDelivery::class);
    $this->delivery->shouldReceive('send')->andReturn('sent')->byDefault();
    $reports = Mockery::mock(SupportReports::class, [$this->delivery])->makePartial();
    $reports->shouldReceive('root')->andReturn($this->root);
    app()->instance(SupportReports::class, $reports);
    $this->actor = User::factory()->create(['role' => 0, 'admin' => false]);
    $this->data = ['submission_id' => (string) Str::uuid(), 'kind' => 'bug', 'title' => 'Document inaccessible',
        'message' => 'Le document ne s’ouvre pas après un clic sur aperçu.',
        'page_path' => '/v2/dossiers/123?token=NEVER-COLLECT&email=secret@example.test', 'viewport' => '390x844'];
});

afterEach(function () {
    Http::assertNothingSent();
    Mail::assertNothingOutgoing();
    if (is_dir($this->root)) {
        File::deleteDirectory($this->root);
    }
});

it('offers the beta dialog to internal users and sends a private report with validated screenshots', function (int $role) {
    $this->actor->update(['role' => $role]);
    $this->actingAs($this->actor)->get(route('profile'))->assertOk()->assertSee('data-beta-open', false)
        ->assertSee('Bêta')->assertSee('id="beta-feedback-dialog"', false);
    $image = UploadedFile::fake()->image('private-original-name.png', 100, 80);
    $this->postJson(route('support.feedback'), [...$this->data, 'screenshots' => [$image], 'recipient' => 'attacker@example.test'])
        ->assertOk()->assertJsonPath('delivery', 'sent');
    $path = $this->root.'/'.$this->data['submission_id'];
    $report = json_decode(file_get_contents($path.'/report.json'), true);
    expect($report['actor_id'])->toBe($this->actor->id)->and($report['actor_email'])->toBe($this->actor->email)
        ->and($report['page'])->toBe('/v2/dossiers/{dossier}')
        ->and($report['identifiers'])->toBe(['dossier' => '123'])
        ->and($report['screenshots'])->toBe([['name' => 'capture-1.png', 'mime' => 'image/png']]);
    expect(file_get_contents($path.'/report.json'))->not->toContain('NEVER-COLLECT', 'private-original-name', 'attacker@example.test');
    expect(file_exists($path.'/capture-1.png'))->toBeFalse()
        ->and(fileperms($path) & 0777)->toBe(0700)->and(fileperms($path.'/report.json') & 0777)->toBe(0600);
    $this->delivery->shouldHaveReceived('send')->once();
})->with([0, 1, 2]);

it('allows active client and delegataire accounts to send feedback without opening internal pages', function (string $kind) {
    $organisation = ExternalDelegataire::create(['source' => 'native', 'external_id' => 'SUPPORT', 'kind' => $kind,
        'name' => 'Organisation test', 'is_active' => true]);
    $this->actor->forceFill(['role' => 3, 'external_client_id' => $organisation->id, 'portal_enabled' => true])->save();
    $this->actingAs($this->actor)->postJson(route('support.feedback'), $this->data)->assertOk();
    $this->get(route('admin.users'))->assertForbidden();
})->with(['client', 'delegataire']);

it('requires authentication and refuses disabled portal accounts', function () {
    $this->postJson(route('support.feedback'), $this->data)->assertUnauthorized();
    $this->actor->forceFill(['role' => 3, 'portal_enabled' => false])->save();
    $this->actingAs($this->actor)->postJson(route('support.feedback'), $this->data)->assertForbidden();
    expect(is_dir($this->root))->toBeFalse();
});

it('preserves CSRF protection for authenticated feedback', function () {
    $this->actingAs($this->actor);
    app()->instance('env', 'production');
    try {
        $this->postJson(route('support.feedback'), $this->data)->assertStatus(419);
        $this->withSession(['_token' => 'support-csrf-fixture'])->withHeader('X-CSRF-TOKEN', 'support-csrf-fixture')
            ->postJson(route('support.feedback'), $this->data)->assertOk();
    } finally {
        app()->instance('env', 'testing');
    }
});

it('prevents double submissions and rejects reusing a reference for different content', function () {
    $this->actingAs($this->actor)->postJson(route('support.feedback'), $this->data)->assertOk();
    $this->postJson(route('support.feedback'), $this->data)->assertOk()->assertJsonPath('delivery', 'sent');
    $this->postJson(route('support.feedback'), [...$this->data, 'message' => 'Un autre message avec le même identifiant.'])->assertConflict();
    $this->delivery->shouldHaveReceived('send')->once();
});

it('retains failed reports and private captures and retries explicitly without losing the reference', function () {
    $this->delivery->shouldReceive('send')->once()->ordered()->andThrow(new RuntimeException('secret SMTP message'));
    $this->delivery->shouldReceive('send')->once()->ordered()->andReturn('sent');
    $data = [...$this->data, 'screenshots' => [UploadedFile::fake()->image('test.png')]];
    $this->actingAs($this->actor)->postJson(route('support.feedback'), $data)->assertServiceUnavailable()
        ->assertJsonPath('reference', $data['submission_id'])->assertJsonPath('delivery', 'failed')->assertDontSee('secret SMTP');
    $path = $this->root.'/'.$data['submission_id'];
    expect(file_exists($path.'/capture-1.png'))->toBeTrue();
    $this->postJson(route('support.feedback'), $this->data)->assertOk()->assertJsonPath('delivery', 'sent');
    expect(file_exists($path.'/capture-1.png'))->toBeFalse();
    $this->postJson(route('support.feedback'), $this->data)->assertOk();
});

it('refuses unsafe images excessive attachments and injected headers', function () {
    $this->actingAs($this->actor);
    $this->postJson(route('support.feedback'), [...$this->data, 'screenshots' => [UploadedFile::fake()->createWithContent('screen.svg', '<svg onload="alert(1)"></svg>')]])
        ->assertUnprocessable()->assertJsonValidationErrors('screenshots.0');
    $this->postJson(route('support.feedback'), [...$this->data, 'screenshots' => [UploadedFile::fake()->createWithContent('fake.png', '<?php echo "unsafe";')]])
        ->assertUnprocessable()->assertJsonValidationErrors('screenshots.0');
    $this->postJson(route('support.feedback'), [...$this->data, 'screenshots' => array_fill(0, 4, UploadedFile::fake()->image('test.png'))])
        ->assertUnprocessable()->assertJsonValidationErrors('screenshots');
    $this->postJson(route('support.feedback'), [...$this->data, 'screenshots' => [UploadedFile::fake()->image('test.png')->size(4097)]])
        ->assertUnprocessable()->assertJsonValidationErrors('screenshots.0');
    $this->postJson(route('support.feedback'), [...$this->data, 'title' => "Erreur\r\nBcc: attacker@example.test"])
        ->assertUnprocessable()->assertJsonValidationErrors('title');
    expect(is_dir($this->root))->toBeFalse();
    $this->delivery->shouldNotHaveReceived('send');
});

it('limits authenticated submissions and only prunes expired support data', function () {
    $this->actingAs($this->actor);
    for ($i = 0; $i < 10; $i++) {
        $this->postJson(route('support.feedback'), [...$this->data, 'submission_id' => (string) Str::uuid()])->assertOk();
    }
    $this->postJson(route('support.feedback'), $this->data)->assertTooManyRequests()->assertHeader('Retry-After', '3600');
    $folders = glob($this->root.'/*', GLOB_ONLYDIR);
    touch($folders[0].'/report.json', now()->subDays(31)->timestamp);
    mkdir($this->root.'/unrelated', 0700);
    file_put_contents($this->root.'/unrelated/keep.txt', 'keep');
    $this->artisan('support:prune')->assertSuccessful();
    expect(is_dir($folders[0]))->toBeFalse()->and(is_dir($folders[1]))->toBeTrue()
        ->and(file_exists($this->root.'/unrelated/keep.txt'))->toBeTrue();
});
