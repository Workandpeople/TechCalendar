<?php

use App\Models\MailTemplate;
use App\Services\Coffrac\CoffracNativeImporter;
use App\Services\Coffrac\NativeCommunicationTemplates;
use App\Services\Coffrac\ProductionDataset;
use App\Services\MailTemplateRenderer;
use App\Services\Sms\DossierSmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../Fixtures/CoffracProductionFixture.php';

uses(RefreshDatabase::class);

function coffracEncodedTemplate(array $fields, int $id = 1): array
{
    $row = ['id' => $id, 'created_at' => '2026-10-01 08:00:00', 'updated_at' => null];
    foreach ($fields as $field => $text) {
        $row[$field.'_base64'] = rtrim(chunk_split(base64_encode(mb_convert_encoding($text, 'UTF-32BE', 'UTF-8')), 76, "\n"), "\n");
        $row[$field.'_charset'] = 'utf32';
    }

    return $row;
}

beforeEach(function () {
    config(['crm.v2_enabled' => true, 'simulation.enabled' => false, 'coffrac.offline_rehearsal' => true,
        'admin.mail' => 'seed-admin@example.test', 'admin.password' => 'Seed-password-123',
        'services.smsfactor.enabled' => false, 'capcee.enabled' => false, 'mail.default' => 'array']);
    Http::preventStrayRequests();
    Mail::fake();
    Queue::fake();
    $this->datasetDirectory = productionCoffracFixture([
        'mail_templates' => [coffracEncodedTemplate(['subject' => 'Confirmation {client_name}',
            'text' => "Bonjour {client_name},\nRDV : {date_rdv}\nAdresse : {adresse}\nTechnicien : {technicien}\nClient : {societe}\nDossier #123 *urgent* [texte]"])],
        'sms_tempalte' => [coffracEncodedTemplate(['titre' => 'Rappel confirmé', 'message' => 'Bonjour {client_name}, merci de rappeler le {date_rappel}.'], 3)],
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->datasetDirectory);
});

it('imports inactive native mails and reuses encoded SMS without duplicates or outbound effects', function () {
    $dataset = new ProductionDataset($this->datasetDirectory);
    $report = app(CoffracNativeImporter::class)->rehearse($dataset);
    $matching = $report['matching']['communications'];
    $template = MailTemplate::sole();
    expect($template->id)->toBe($matching['mail_templates']['mapping'][1])
        ->and($template->slug)->toBe('coffrac-mail-1')
        ->and($template->is_active)->toBeFalse()
        ->and($template->mail_sender_id)->toBeNull()
        ->and($template->created_by_user_id)->toBeNull()
        ->and($template->subject)->toBe('Confirmation {{client_name}}')
        ->and($matching['mail_templates']['issues'])->toBe([])
        ->and($matching['sms_templates']['available_source_ids'])->toBe([3])
        ->and($matching['sms_templates']['unreadable_source_ids'])->toBe([])
        ->and(app(DossierSmsService::class)->templates())->toBe([['id' => 3, 'name' => 'Rappel confirmé', 'body' => 'Bonjour {client_name}, merci de rappeler le {date_rappel}.']])
        ->and(DB::table('department_user')->count())->toBe(0)
        ->and(DB::table('service_user')->count())->toBe(1)
        ->and($report['issues']['technician_sectors_pending_techcalendar_matching']['source_ids'])->toBe([21])
        ->and($report['matching']['technician_services']['policy'])->toBe('all_native_coffrac_services');
    $body = app(MailTemplateRenderer::class)->renderMarkdownBody($template, [
        'client_name' => 'Beneficiaire Test', 'appointment_date' => '30/10/2026', 'appointment_time' => '10:00',
        'appointment_end_time' => '11:00', 'address' => 'Rue Test', 'technician_name' => 'Technicien Test', 'company_name' => 'Societe Test',
    ]);
    expect($body)->toContain('Bonjour Beneficiaire Test', '30/10/2026 de 10:00 à 11:00', 'Rue Test', 'Technicien Test', 'Societe Test', '\#123 \*urgent\* \[texte\]')
        ->not->toContain('{client_name}', '{date_rdv}');
    $preview = app(MailTemplateRenderer::class)->preview($template, ['client_name' => 'Beneficiaire Test']);
    expect($preview['subject'])->toBe('Confirmation Beneficiaire Test')
        ->and($preview['html'])->toContain('Bonjour Beneficiaire Test', '*urgent*', '[texte]')
        ->not->toContain('<em>urgent</em>', '{client_name}');
    $raw = json_decode(DB::table('legacy_records')->where('source_table', 'mail_templates')->value('payload'), true);
    expect($raw)->toHaveKeys(['subject_base64', 'text_base64'])->not->toHaveKeys(['subject', 'text']);
    expect(app(CoffracNativeImporter::class)->rehearse($dataset))->toBe($report)
        ->and(MailTemplate::count())->toBe(1)
        ->and(DB::table('dossier_sms')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);
    Mail::assertNothingOutgoing();
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('leaves unsupported mail templates in the exact archive with an explicit review reason', function (array $overrides, string $reason) {
    $path = $this->datasetDirectory.'/mail_templates.jsonl';
    $row = coffracEncodedTemplate(['subject' => 'Confirmation', 'text' => 'Texte correct']);
    file_put_contents($path, json_encode(array_replace($row, $overrides), JSON_THROW_ON_ERROR)."\n");
    sealProductionCoffracFixture($this->datasetDirectory);
    $report = app(CoffracNativeImporter::class)->rehearse(new ProductionDataset($this->datasetDirectory));
    expect($report['matching']['communications']['mail_templates']['issues'])->toBe([1 => $reason])
        ->and(MailTemplate::count())->toBe(0)
        ->and(DB::table('legacy_records')->where('source_table', 'mail_templates')->count())->toBe(1);
    Http::assertNothingSent();
})->with([
    'encoding' => [['text_base64' => 'broken'], 'invalid_text_encoding'],
    'empty' => [coffracEncodedTemplate(['text' => '  ']), 'empty_template'],
    'HTML' => [coffracEncodedTemplate(['text' => '<img src="https://example.test/tracker">Texte']), 'markup_requires_review'],
    'Blade' => [coffracEncodedTemplate(['text' => '{{config("app.key")}}']), 'markup_requires_review'],
    'PHP' => [coffracEncodedTemplate(['text' => '<?php echo "unsafe"; ?>']), 'markup_requires_review'],
    'unknown variable' => [coffracEncodedTemplate(['text' => 'Bonjour {unknown}']), 'unmapped_variable'],
    'multiline header' => [coffracEncodedTemplate(['subject' => "Sujet\r\nBcc: invalid@example.test"]), 'multiline_subject'],
    'subject length' => [coffracEncodedTemplate(['subject' => str_repeat('a', 191)]), 'template_exceeds_native_limits'],
    'expanded subject length' => [coffracEncodedTemplate(['subject' => str_repeat('a', 150).'{date_rdv}']), 'template_exceeds_native_limits'],
    'expanded markdown length' => [coffracEncodedTemplate(['text' => str_repeat('*', 30001)]), 'template_exceeds_native_limits'],
]);

it('refuses a preexisting mail catalogue before importing any source account', function () {
    MailTemplate::create(['name' => 'Existing', 'slug' => 'existing', 'subject' => 'Existing', 'markdown_body' => 'Existing']);
    expect(fn () => app(CoffracNativeImporter::class)->rehearse(new ProductionDataset($this->datasetDirectory)))
        ->toThrow(RuntimeException::class, 'Cible non vide : mail_templates');
    expect(DB::table('users')->count())->toBe(0)->and(MailTemplate::count())->toBe(1);
});

it('grants all native Coffrac services only to imported technicians without reactivating inactive accounts', function () {
    $users = array_map(fn ($line) => json_decode($line, true), file($this->datasetDirectory.'/users.jsonl'));
    $inactive = [...$users[2], 'id' => 61, 'email' => 'inactive-tech@example.test', 'actif' => 0];
    file_put_contents($this->datasetDirectory.'/users.jsonl', json_encode($inactive)."\n", FILE_APPEND);
    $credentials = array_map(fn ($line) => json_decode($line, true), file($this->datasetDirectory.'/credentials.jsonl'));
    file_put_contents($this->datasetDirectory.'/credentials.jsonl', json_encode([...$credentials[2], 'id' => 61])."\n", FILE_APPEND);
    file_put_contents($this->datasetDirectory.'/fiche_type.jsonl', json_encode(['id' => 9, 'name' => 'Autre prestation'])."\n", FILE_APPEND);
    sealProductionCoffracFixture($this->datasetDirectory);
    $report = app(CoffracNativeImporter::class)->rehearse(new ProductionDataset($this->datasetDirectory));
    $techIds = [$report['matching']['users'][21], $report['matching']['users'][61]];
    expect(DB::table('service_user')->count())->toBe(4)
        ->and(DB::table('service_user')->whereNotIn('user_id', $techIds)->count())->toBe(0)
        ->and(DB::table('users')->where('id', $techIds[1])->value('deleted_at'))->not->toBeNull()
        ->and(DB::table('users')->where('id', $techIds[1])->value('admin'))->toBe(0)
        ->and(DB::table('department_user')->count())->toBe(0)
        ->and($report['matching']['technician_services'])->toBe(['policy' => 'all_native_coffrac_services',
            'technicians' => 2, 'services' => 2, 'grants' => 4, 'source_accounts_reactivated' => 0, 'sectors_inferred_from_source_dpt' => 0]);
});

it('cannot run communication projection against production even directly', function () {
    $dataset = new ProductionDataset($this->datasetDirectory);
    try {
        app()->detectEnvironment(fn () => 'production');
        expect(fn () => app(NativeCommunicationTemplates::class)->import($dataset))->toThrow(RuntimeException::class, 'Cible refusee');
    } finally {
        app()->detectEnvironment(fn () => 'testing');
    }
    expect(MailTemplate::count())->toBe(0);
});
