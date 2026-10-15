<?php

use App\Models\Appointment;
use App\Models\Dossier;
use App\Models\ExternalDelegataire;
use App\Models\Service;
use App\Models\User;
use App\Services\Crm\DossierRegistry;
use App\Services\Crm\DossierTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true]);
    Http::preventStrayRequests();
    $this->travelTo(now()->setDate(2026, 10, 14)->setTime(12, 0));
    $this->actor = User::factory()->create(['admin' => false, 'role' => 0]);
    $this->actingAs($this->actor);
    $this->makeRow = function (string $letter, int $day) {
        $company = ExternalDelegataire::create(['source' => 'test', 'external_id' => $letter, 'name' => $letter.' société']);
        $tech = User::factory()->create(['role' => 2, 'first_name' => 'Technicien', 'last_name' => $letter]);
        $service = Service::create(['type' => 'COFFRAC', 'name' => $letter.' prestation', 'average_duration_minutes' => 60]);
        $dossier = Dossier::create(['reference' => $letter, 'company_name' => $letter.' client', 'address' => $letter.' adresse', 'city' => 'Lyon', 'phone' => $letter.' téléphone', 'delegataire_id' => $company->id]);
        $planner = User::factory()->create(['role' => 1, 'first_name' => 'Planning', 'last_name' => $letter]);
        $dossier->forceFill(['planning_user_id' => $planner->id])->save();
        $dossier->forceFill(['legacy_lot_name' => $letter.' lot', 'created_at' => now()->subDays(10)->addDays($day), 'updated_at' => now()->subDays(10)->addDays($day)])->save();
        $operation = $dossier->operations()->create(['service_id' => $service->id]);
        $operation->forceFill(['legacy_status' => 'RDV ATTENTE VISITE'])->save();
        $dossier->activities()->create(['kind' => 'updated', 'body' => 'Modification test', 'changed_fields' => ['address', 'city']])
            ->forceFill(['created_at' => now()->subDays(5)->addDays($day)])->save();
        $appointment = Appointment::forceCreate(['dossier_operation_id' => $operation->id, 'service_id' => $service->id,
            'technician_id' => $tech->id, 'created_by' => $this->actor->id, 'customer_first_name' => 'Client', 'customer_last_name' => $letter,
            'customer_phone' => '0100000000', 'address' => $dossier->address, 'duration_minutes' => 60,
            'starts_at' => now()->addDays($day), 'ends_at' => now()->addDays($day)->addHour(), 'status' => 'scheduled']);

        return [$dossier, $operation, $appointment];
    };
});

it('sorts the full result set in both directions for every visible column', function (string $sort) {
    [$z] = ($this->makeRow)('Z', 2);
    [$a] = ($this->makeRow)('A', 1);
    foreach (['asc' => [$a->id, $z->id], 'desc' => [$z->id, $a->id]] as $direction => $expected) {
        $this->get(route('v2.dossiers.index', compact('sort', 'direction')))->assertOk()
            ->assertViewHas('dossiers', fn ($rows) => $rows->pluck('id')->all() === $expected);
    }
    Http::assertNothingSent();
})->with(array_keys(DossierTable::COLUMNS));

it('cycles ascending descending and neutral while retaining filters and resetting pagination', function () {
    $filters = ['q' => 'example', 'status' => 'pending', 'per_page' => 50, 'page' => 2, 'all_columns' => 1];
    foreach ([null => 'asc', 'asc' => 'desc', 'desc' => null] as $current => $next) {
        $response = $this->get(route('v2.dossiers.index', [...$filters, ...($current ? ['sort' => 'client', 'direction' => $current] : [])]))->assertOk();
        $dom = new DOMDocument;
        @$dom->loadHTML($response->getContent());
        $xpath = new DOMXPath($dom);
        $link = $xpath->query('//th[@data-column="client"]/a')->item(0);
        parse_str(parse_url($link->getAttribute('href'), PHP_URL_QUERY), $params);
        expect($params['q'])->toBe('example')->and($params['status'])->toBe('pending')->and($params['per_page'])->toBe('50')
            ->and($params)->not->toHaveKey('page')->and($params['direction'] ?? null)->toBe($next);
        if ($next === null) {
            expect($params)->not->toHaveKey('sort');
        }
    }
});

it('adapts columns to dossier state but allows showing everything', function () {
    $table = app(DossierTable::class);
    expect($table->columns(['status' => 'pending']))->not->toHaveKeys(['appointment_at', 'technician'])
        ->and($table->columns(['status' => 'invoiced']))->not->toHaveKey('phone')
        ->and($table->columns(['status' => 'pending', 'all_columns' => true]))->toBe(DossierTable::COLUMNS);
    $this->get(route('v2.dossiers.index', ['status' => 'pending']))->assertOk()->assertDontSee('data-column="appointment_at"', false);
    $this->get(route('v2.dossiers.index', ['status' => 'pending', 'all_columns' => 1]))->assertOk()->assertSee('data-column="appointment_at"', false);
});

it('redistributes the whole data width between the visible columns', function (array $filters) {
    $table = app(DossierTable::class);
    $shares = $table->columnShares($filters);

    expect(array_keys($shares))->toBe(array_keys($table->columns($filters)))
        ->and(abs(array_sum($shares) - 1))->toBeLessThan(0.000001);

    if (($filters['status'] ?? null) === 'pending' && empty($filters['all_columns'])) {
        expect($shares['address'])->toBeGreaterThan($table->columnShares([])['address']);
    }

    $response = $this->get(route('v2.dossiers.index', $filters))->assertOk();
    foreach ($shares as $share) {
        $response->assertSee('width: '.number_format($share * 100, 6, '.', '').'%', false);
    }
})->with([
    [[]], [['status' => 'pending']], [['status' => 'cancelled']], [['status' => 'invoiced']],
    [['status' => 'pending', 'all_columns' => 1]],
]);

it('selects the next active booking rather than an obsolete historical or deleted booking', function () {
    [$dossier, $operation, $appointment] = ($this->makeRow)('A', 2);
    $past = $appointment->replicate();
    $past->forceFill(['starts_at' => now()->subDays(3), 'ends_at' => now()->subDays(3)->addHour()])->save();
    $next = $appointment->replicate();
    $next->forceFill(['starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour()])->save();
    $deleted = $appointment->replicate();
    $deleted->forceFill(['starts_at' => now(), 'ends_at' => now()->addHour(), 'deleted_at' => now()])->save();
    $archive = $deleted->replicate();
    $archive->external_source = 'coffrac_archive';
    $archive->save();
    $rows = app(DossierRegistry::class)->search($this->actor, []);
    expect($rows->first()->listingAppointment->id)->toBe($next->id);
    $appointment->delete();
    $next->delete();
    $past->delete();
    expect(app(DossierRegistry::class)->search($this->actor, [])->first()->listingAppointment->id)->toBe($archive->id);
});

it('shows the modification date and changed fields safely', function () {
    [$dossier] = ($this->makeRow)('A', 1);
    $this->get(route('v2.dossiers.index'))->assertOk()->assertSee('Modification : adresse, ville')->assertSee('A lot');
    $dossier->activities()->create(['kind' => 'legacy_history', 'body' => '&lt;script&gt;alert(1)&lt;/script&gt;']);
    $this->get(route('v2.dossiers.index'))->assertOk()->assertDontSee('<script>alert(1)</script>', false);
});

it('rejects untrusted sorting fields and directions', function (array $filters) {
    $this->getJson(route('v2.dossiers.index', $filters))->assertUnprocessable();
})->with([
    [['sort' => 'id desc; DROP TABLE dossiers']], [['sort' => 'client', 'direction' => 'anything']], [['all_columns' => 'yes']],
]);
