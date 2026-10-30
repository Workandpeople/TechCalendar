<?php

use App\Models\Appointment;
use App\Models\Dossier;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true]);
    Http::preventStrayRequests();
    $this->actor = User::factory()->create(['role' => 0, 'admin' => false]);
    $this->actingAs($this->actor);
    $this->service = Service::create(['name' => 'Fiche Test', 'type' => 'COFRAC', 'average_duration_minutes' => 45]);
    $this->makeDossier = function (string $name, string $created, ?string $starts): Dossier {
        $dossier = Dossier::create(['reference' => $name, 'company_name' => $name, 'address' => 'Rue test', 'city' => 'Paris']);
        $dossier->forceFill(['created_at' => $created])->save();
        $operation = $dossier->operations()->create(['service_id' => $this->service->id]);
        $operation->forceFill(['legacy_status' => 'RDV ATTENTE VISITE'])->save();
        if ($starts) {
            Appointment::forceCreate(['dossier_operation_id' => $operation->id, 'service_id' => $this->service->id,
                'technician_id' => $this->actor->id, 'created_by' => $this->actor->id,
                'customer_first_name' => 'Test', 'customer_last_name' => $name, 'customer_phone' => '', 'address' => 'Rue test',
                'starts_at' => $starts, 'ends_at' => Carbon::parse($starts)->addMinutes(45), 'duration_minutes' => 45, 'status' => Appointment::STATUS_SCHEDULED]);
        }

        return $dossier;
    };
    $this->matching = ($this->makeDossier)('RDV retenu', '2025-01-01 12:00:00', '2026-11-02 23:59:59');
    ($this->makeDossier)('Creation seule exclue', '2026-11-02 10:00:00', '2026-11-03 00:00:00');
    ($this->makeDossier)('Sans rendez vous exclu', '2026-11-02 10:00:00', null);
    $this->filters = ['date_from' => '2026-11-02', 'date_to' => '2026-11-02'];
});

it('always filters the appointment date including on old creation-date links in html ajax and counters', function (?string $legacyField) {
    $filters = [...$this->filters, ...($legacyField ? ['date_field' => $legacyField] : [])];
    $this->get(route('v2.dossiers.index', $filters))->assertOk()
        ->assertDontSee('Création du dossier')->assertDontSee('name="date_field"', false)
        ->assertViewHas('filters', fn ($data) => ! isset($data['date_field']))
        ->assertViewHas('dossiers', fn ($rows) => $rows->total() === 1 && $rows->first()->is($this->matching)
            && $rows->first()->listingAppointment->starts_at->format('Y-m-d H:i:s') === '2026-11-02 23:59:59')
        ->assertViewHas('categories', fn ($categories) => $categories['all']['count'] === 1 && $categories['awaiting_visit']['count'] === 1);
    $this->getJson(route('v2.dossiers.index', $filters))->assertOk()->assertJsonPath('total', 1);
    Http::assertNothingSent();
})->with([null, 'created', 'appointment']);

it('exports exactly the same appointment period rather than the dossier creation period', function () {
    $response = $this->get(route('v2.dossiers.export', [...$this->filters, 'date_field' => 'created']))->assertOk();
    $path = tempnam(sys_get_temp_dir(), 'rdv-period-');
    try {
        file_put_contents($path, $response->streamedContent());
        $zip = new ZipArchive;
        expect($zip->open($path))->toBeTrue();
        expect($zip->getFromName('xl/worksheets/sheet1.xml'))->toContain('RDV retenu')->not->toContain('Creation seule exclue', 'Sans rendez vous exclu');
        $zip->close();
    } finally {
        unlink($path);
    }
    Http::assertNothingSent();
});
