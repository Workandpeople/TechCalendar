<?php

use App\Models\Appointment;
use App\Models\Dossier;
use App\Models\DossierBilling;
use App\Models\Service;
use App\Models\User;
use App\Services\Crm\DossierRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true]);
    Http::preventStrayRequests();
    Mail::fake();
    Queue::fake();
    $this->travelTo(now()->setDate(2026, 10, 19)->setTime(12, 0));
    $this->actor = User::factory()->create(['admin' => false, 'role' => 0]);
    $this->actingAs($this->actor);
    $this->service = Service::create(['type' => 'COFFRAC', 'name' => 'Inspection', 'average_duration_minutes' => 60]);
    $this->makeRow = function (
        string $reference,
        int $day,
        string $technicianName,
        string $status = 'RAPPORT GENIUS',
        bool $billed = true,
        ?Dossier $dossier = null,
        ?Service $service = null,
    ): array {
        $dossier ??= Dossier::create(['reference' => $reference, 'company_name' => $reference, 'address' => 'Rue Test', 'city' => 'Lyon']);
        $service ??= $this->service;
        $technician = User::factory()->create(['role' => 2, 'first_name' => 'Technicien', 'last_name' => $technicianName]);
        $operation = $dossier->operations()->create(['service_id' => $service->id]);
        $operation->forceFill(['legacy_status' => $status])->save();
        if ($billed) {
            DossierBilling::create(['dossier_id' => $dossier->id, 'dossier_operation_id' => $operation->id,
                'source' => 'native', 'marked_at' => now(), 'marked_by' => $this->actor->id]);
        }
        $appointment = Appointment::forceCreate([
            'dossier_operation_id' => $operation->id, 'service_id' => $service->id,
            'technician_id' => $technician->id, 'created_by' => $this->actor->id,
            'customer_first_name' => 'Client', 'customer_last_name' => $reference,
            'customer_phone' => '0100000000', 'address' => $dossier->address, 'duration_minutes' => 60,
            'starts_at' => now()->addDays($day), 'ends_at' => now()->addDays($day)->addHour(),
            'status' => Appointment::STATUS_SCHEDULED,
        ]);

        return [$dossier, $appointment];
    };
});

afterEach(function () {
    Http::assertNothingSent();
    Mail::assertNothingOutgoing();
    Queue::assertNothingPushed();
});

it('shows the appointment and technician for persistent billing or historical invoiced status', function (string $status, bool $billed) {
    [$dossier, $appointment] = ($this->makeRow)('BILLED', 2, 'Martin', $status, $billed);

    $this->get(route('v2.dossiers.index', ['status' => 'invoiced']))->assertOk()
        ->assertViewHas('dossiers', function ($rows) use ($dossier, $appointment) {
            expect($rows->pluck('id')->all())->toBe([$dossier->id])
                ->and($rows->first()->listingAppointment?->id)->toBe($appointment->id)
                ->and($rows->first()->listingAppointment?->technician?->id)->toBe($appointment->technician_id);

            return true;
        })
        ->assertSee($appointment->starts_at->format('d/m/Y'))
        ->assertSee($appointment->technician->full_name_with_departments);

    expect($appointment->dossierOperation->fresh()->legacy_status)->toBe($status);
    $this->assertDatabaseCount('dossier_billings', $billed ? 1 : 0);
})->with([
    'report with persistent billing' => ['RAPPORT GENIUS', true],
    'RT control with persistent billing' => ['CONTROLE RT', true],
    'non conformity with persistent billing' => ['NON CONFORMITES', true],
    'recontrol with persistent billing' => ['RECONTROLE', true],
    'historical status without ledger' => ['DOSSIERS FACTURER', false],
]);

it('sorts billed appointments and technicians in both directions across native and historical billing', function (string $sort) {
    [$late] = ($this->makeRow)('LATE', 3, 'Alpha');
    [$early] = ($this->makeRow)('EARLY', 1, 'Zulu', 'CONTROLE RT');
    [$middle] = ($this->makeRow)('MIDDLE', 2, 'Mike', 'DOSSIERS FACTURER', false);
    ($this->makeRow)('UNBILLED', 0, 'Aardvark', billed: false);
    $ascending = $sort === 'appointment_at' ? [$early->id, $middle->id, $late->id] : [$late->id, $middle->id, $early->id];

    foreach (['asc' => $ascending, 'desc' => array_reverse($ascending)] as $direction => $expected) {
        $this->get(route('v2.dossiers.index', ['status' => 'invoiced', 'sort' => $sort, 'direction' => $direction]))
            ->assertOk()->assertViewHas('dossiers', fn ($rows) => $rows->pluck('id')->all() === $expected);
    }
})->with(['appointment_at', 'technician']);

it('keeps billing scoped to the selected operation dossier service and eligible appointments', function () {
    [$dossier, $selected] = ($this->makeRow)('TARGET', 5, 'Selected');
    ($this->makeRow)('UNBILLED-SIBLING', 1, 'Unbilled', billed: false, dossier: $dossier);
    $otherService = Service::create(['type' => 'COFFRAC', 'name' => 'Other inspection', 'average_duration_minutes' => 60]);
    ($this->makeRow)('OTHER-SERVICE', 2, 'Other', dossier: $dossier, service: $otherService);
    ($this->makeRow)('OTHER-DOSSIER', 0, 'Foreign');
    $deleted = $selected->replicate();
    $deleted->forceFill(['starts_at' => now(), 'ends_at' => now()->addHour(), 'deleted_at' => now()])->save();
    $archive = $selected->replicate();
    $archive->forceFill(['starts_at' => now()->subDay(), 'ends_at' => now()->subDay()->addHour(),
        'deleted_at' => now(), 'external_source' => 'coffrac_archive'])->save();
    $filters = ['q' => 'TARGET', 'status' => 'invoiced', 'service_id' => $this->service->id];

    $rows = app(DossierRegistry::class)->search($this->actor, $filters);
    expect($rows->pluck('id')->all())->toBe([$dossier->id])
        ->and($rows->first()->listingAppointment?->id)->toBe($selected->id);

    $selected->delete();
    expect(app(DossierRegistry::class)->search($this->actor, $filters)->first()->listingAppointment?->id)->toBe($archive->id);
});

it('does not let persistent billing broaden appointment selection for other status filters', function () {
    [$dossier, $selected] = ($this->makeRow)('RT', 2, 'Selected', 'CONTROLE RT');
    ($this->makeRow)('REPORT-SIBLING', 1, 'Other', dossier: $dossier);

    $rows = app(DossierRegistry::class)->search($this->actor, ['status' => 'rt_control']);
    expect($rows->first()->listingAppointment?->id)->toBe($selected->id);
});
