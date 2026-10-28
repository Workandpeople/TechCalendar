<?php

use App\Models\Dossier;
use App\Models\MailTemplate;
use App\Models\Service;
use App\Services\Crm\CofracLabelCorrection;
use App\Support\CofracText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('corrects prose and casing but preserves URLs email paths and template keys', function () {
    expect(CofracText::normalize('Coffrac COFFRAC coffrac cOfFrAc'))->toBe('Cofrac COFRAC cofrac Cofrac')
        ->and(CofracText::normalize('Coffrac. https://coffrac.example/doc contact@coffrac.example /srv/coffrac/file.pdf {{ coffrac }} coffrac_id'))
        ->toBe('Cofrac. https://coffrac.example/doc contact@coffrac.example /srv/coffrac/file.pdf {{ coffrac }} coffrac_id')
        ->and(CofracText::normalize(null))->toBeNull()
        ->and(CofracText::normalize('<b>Coffrac</b><a href="https://coffrac.example">COFFRAC</a>'))
        ->toBe('<b>Cofrac</b><a href="https://coffrac.example">COFRAC</a>');
});

it('displays the corrected type and labels without changing the service protocol', function () {
    $service = Service::create(['type' => 'COFFRAC', 'name' => 'Audit COFFRAC', 'average_duration_minutes' => 40]);
    expect($service->type)->toBe('COFFRAC')->and($service->display_type)->toBe('COFRAC')
        ->and($service->name)->toBe('Audit COFRAC');
    $this->assertDatabaseHas('services', ['id' => $service->id, 'type' => 'COFFRAC', 'name' => 'Audit COFRAC']);
    $mail = new MailTemplate(['subject' => 'Bienvenue chez Coffrac', 'markdown_body' => 'COFFRAC {{ client_name }}']);
    expect($mail->subject)->toBe('Bienvenue chez Cofrac')->and($mail->markdown_body)->toBe('COFRAC {{ client_name }}');
});

it('audits and corrects old soft deleted text with reversible private backups and no side effects', function () {
    Http::preventStrayRequests();
    Mail::fake();
    Queue::fake();
    $dossier = Dossier::create(['reference' => 'C-1', 'source' => 'coffrac', 'source_reference' => 'coffrac-42', 'address' => 'Paris', 'city' => 'Paris']);
    $activity = $dossier->activities()->create(['kind' => 'legacy_history', 'body' => 'Placeholder', 'changed_fields' => ['source' => 'coffrac']]);
    DB::table('dossier_activities')->where('id', $activity->id)->update(['body' => 'Ancienne note Coffrac']);
    $doc = $dossier->documents()->create(['name' => 'Placeholder', 'path' => 'coffrac/docs/file.pdf', 'size' => 1, 'mime_type' => 'application/pdf']);
    DB::table('dossier_documents')->where('id', $doc->id)->update(['name' => 'Rapport COFFRAC', 'deleted_at' => now()]);
    DB::table('legacy_records')->insert(['source_table' => 'dossier_document', 'source_id' => '88', 'dossier_id' => $dossier->id, 'payload' => json_encode(['name' => 'COFFRAC', 'path' => 'coffrac/file.pdf'])]);
    $before = DB::table('dossier_activities')->where('id', $activity->id)->first();
    $correction = app(CofracLabelCorrection::class);
    $counts = $correction->run();
    expect($counts)->toMatchArray(['dossier_documents' => 1, 'dossier_activities' => 1]);
    $this->assertDatabaseHas('dossier_activities', ['id' => $activity->id, 'body' => 'Ancienne note Coffrac']);
    $dir = sys_get_temp_dir().'/cofrac-label-test-'.bin2hex(random_bytes(8));
    mkdir($dir, 0700);
    $backup = $dir.'/backup.jsonl';
    try {
        expect($correction->run($backup))->toBe($counts);
        expect(fileperms($backup) & 0777)->toBe(0600);
        $this->assertDatabaseHas('dossier_activities', ['id' => $activity->id, 'body' => 'Ancienne note Cofrac', 'updated_at' => $before->updated_at]);
        $this->assertDatabaseHas('dossier_documents', ['id' => $doc->id, 'name' => 'Rapport COFRAC', 'path' => 'coffrac/docs/file.pdf']);
        $this->assertDatabaseHas('dossiers', ['id' => $dossier->id, 'source' => 'coffrac', 'source_reference' => 'coffrac-42']);
        expect(DB::table('legacy_records')->value('payload'))->toContain('COFFRAC');
        expect($correction->run())->toBe([]);
        // A new comment edit must not be overwritten by a rollback.
        DB::table('dossier_activities')->where('id', $activity->id)->update(['body' => 'Nouvelle note']);
        expect($correction->restore($backup))->toBe(['restored' => 1, 'already_restored' => 0, 'conflicts' => 1]);
        $this->assertDatabaseHas('dossier_activities', ['id' => $activity->id, 'body' => 'Nouvelle note']);
        expect($correction->restore($backup))->toBe(['restored' => 0, 'already_restored' => 1, 'conflicts' => 1]);
        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
        Queue::assertNothingPushed();
    } finally {
        @unlink($backup);
        rmdir($dir);
    }
});

it('requires explicit production confirmation and never targets the old database', function () {
    config(['crm.v2_enabled' => true]);
    app()->detectEnvironment(fn () => 'production');
    $this->artisan('cofrac:correct-labels')->assertFailed();
    $this->artisan('cofrac:correct-labels', ['--apply' => true, '--confirm-production' => true])->assertFailed();
    config(['crm.v2_enabled' => false]);
    app()->detectEnvironment(fn () => 'testing');
    $this->artisan('cofrac:correct-labels')->assertFailed();
});

it('applies and restores through the guarded command with a private journal and no model events', function () {
    config(['crm.v2_enabled' => true]);
    Mail::fake();
    Queue::fake();
    $service = Service::create(['type' => 'COFFRAC', 'name' => 'Placeholder', 'average_duration_minutes' => 40]);
    DB::table('services')->where('id', $service->id)->update(['name' => 'Audit Coffrac']);
    $storage = sys_get_temp_dir().'/cofrac-label-cli-'.bin2hex(random_bytes(8));
    $previous = storage_path();
    app()->useStoragePath($storage);
    try {
        $this->artisan('cofrac:correct-labels')->assertSuccessful();
        $this->assertDatabaseHas('services', ['id' => $service->id, 'name' => 'Audit Coffrac']);
        $this->artisan('cofrac:correct-labels', ['--apply' => true])->assertSuccessful();
        $files = glob($storage.'/app/private/cofrac-label-corrections/*.jsonl');
        expect($files)->toHaveCount(1)->and(fileperms(dirname($files[0])) & 0777)->toBe(0700);
        $this->assertDatabaseHas('services', ['id' => $service->id, 'name' => 'Audit Cofrac', 'type' => 'COFFRAC']);
        $this->artisan('cofrac:correct-labels', ['--restore' => pathinfo($files[0], PATHINFO_FILENAME)])->assertSuccessful();
        $this->assertDatabaseHas('services', ['id' => $service->id, 'name' => 'Audit Coffrac']);
        $this->artisan('cofrac:correct-labels', ['--restore' => '../outside'])->assertFailed();
        Mail::assertNothingOutgoing();
        Queue::assertNothingPushed();
    } finally {
        app()->useStoragePath($previous);
        foreach (glob($storage.'/app/private/cofrac-label-corrections/*') as $file) {
            unlink($file);
        }
        rmdir($storage.'/app/private/cofrac-label-corrections');
        rmdir($storage.'/app/private');
        rmdir($storage.'/app');
        rmdir($storage);
    }
});

it('refuses catalogue collisions before changing any text or creating a backup', function () {
    $old = Service::create(['type' => 'COFFRAC', 'name' => 'Placeholder', 'average_duration_minutes' => 40]);
    DB::table('services')->where('id', $old->id)->update(['name' => 'Inspection Coffrac']);
    Service::create(['type' => 'COFFRAC', 'name' => 'Inspection Cofrac', 'average_duration_minutes' => 40]);
    $backup = sys_get_temp_dir().'/cofrac-collision-'.bin2hex(random_bytes(8)).'.jsonl';
    expect(fn () => app(CofracLabelCorrection::class)->run($backup))->toThrow(DomainException::class)
        ->and(file_exists($backup))->toBeFalse();
    $this->assertDatabaseHas('services', ['id' => $old->id, 'name' => 'Inspection Coffrac']);
});
