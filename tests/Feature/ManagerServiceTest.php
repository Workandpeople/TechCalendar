<?php

use App\Models\Service;
use App\Models\ExternalServiceAlias;
use App\Models\User;
use App\Services\CoffracAppointmentService;
use Database\Seeders\CoffracServiceAliasSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders active technicians in the service creation form', function () {
    $manager = User::factory()->create([
        'role' => 0,
        'admin' => false,
    ]);
    $technician = User::factory()->create([
        'role' => 2,
        'admin' => false,
        'first_name' => 'Nora',
        'last_name' => 'Tech',
        'department_code' => '69',
    ]);
    $planningUser = User::factory()->create([
        'role' => 1,
        'admin' => false,
        'first_name' => 'Paul',
        'last_name' => 'Planning',
    ]);

    $this->actingAs($manager)
        ->get(route('manager.services'))
        ->assertOk()
        ->assertSee('Attribuer à des techniciens')
        ->assertSee($technician->full_name_with_departments)
        ->assertSee('Départements 69')
        ->assertSee($technician->email)
        ->assertDontSee($planningUser->email);
});

it('assigns a new service to selected technicians', function () {
    $manager = User::factory()->create([
        'role' => 0,
        'admin' => false,
    ]);
    $firstTechnician = User::factory()->create([
        'role' => 2,
        'admin' => false,
    ]);
    $secondTechnician = User::factory()->create([
        'role' => 2,
        'admin' => false,
    ]);
    $otherTechnician = User::factory()->create([
        'role' => 2,
        'admin' => false,
    ]);

    $this->actingAs($manager)
        ->post(route('manager.services.store'), [
            'type' => Service::TYPE_AUDIT,
            'name' => 'Audit terrain renforcé',
            'average_duration_minutes' => 135,
            'technician_ids' => [
                $firstTechnician->id,
                $secondTechnician->id,
                $secondTechnician->id,
            ],
        ])
        ->assertRedirect(route('manager.services'))
        ->assertSessionHas('status', 'Prestation créée avec succès.');

    $service = Service::query()->where('name', 'Audit terrain renforcé')->firstOrFail();

    expect($service->technicians()->pluck('users.id')->all())
        ->toEqualCanonicalizing([$firstTechnician->id, $secondTechnician->id])
        ->and($otherTechnician->services()->whereKey($service->id)->exists())->toBeFalse();
});

it('stores coffrac aliases for a service', function () {
    $manager = User::factory()->create([
        'role' => 0,
        'admin' => false,
    ]);

    $this->actingAs($manager)
        ->post(route('manager.services.store'), [
            'type' => Service::TYPE_COFFRAC,
            'name' => 'Résidentiel EC 104',
            'average_duration_minutes' => 90,
            'external_aliases' => implode("\n", [
                'RES EC 104 (01/01/25)',
                'RES EC 104 LUMINAIRE',
                'COFFRAC | SAV - RES EC 104',
            ]),
        ])
        ->assertRedirect(route('manager.services'))
        ->assertSessionHas('status', 'Prestation créée avec succès.');

    $service = Service::query()->where('name', 'Résidentiel EC 104')->firstOrFail();
    $aliases = ExternalServiceAlias::query()
        ->where('service_id', $service->id)
        ->orderBy('external_name')
        ->get();

    expect($aliases)->toHaveCount(3)
        ->and($aliases->pluck('source')->unique()->all())->toBe([CoffracAppointmentService::SOURCE])
        ->and($aliases->pluck('normalized_external_name')->all())->toContain('res ec 104 01 01 25')
        ->and($aliases->pluck('normalized_external_name')->all())->toContain('sav res ec 104');
});

it('seeds coffrac service aliases against existing techcalendar services', function () {
    $services = collect([
        'AGRI TH 117',
        'BAR EN 101',
        'BAR EN 103',
        'BAR TH 125 TERTIAIRE',
        'BAR TH 127',
        'BAR TH 145 APRES TRAVAUX',
        'BAR TH 145 AUDIT',
        'BAR TH 160',
        'BAR TH 161',
        'BAR TH 171',
        'BAR TH 177 P1 AVANT TRAVAUX',
        'BAR TH 177 P2',
        'BAT EQ 127 (VOLONTAIRE)',
        'BAT TH 146',
        'BAT TH 155',
        'RES EC 104',
        'RES EC 104 2025',
    ])->mapWithKeys(fn (string $name): array => [
        $name => Service::query()->create([
            'type' => Service::TYPE_COFFRAC,
            'name' => $name,
            'average_duration_minutes' => 90,
        ]),
    ]);

    $this->seed(CoffracServiceAliasSeeder::class);

    $aliasServiceName = fn (string $alias): ?string => ExternalServiceAlias::query()
        ->with('service:id,name')
        ->where('source', CoffracAppointmentService::SOURCE)
        ->where('normalized_external_type', ExternalServiceAlias::normalizeValue(Service::TYPE_COFFRAC))
        ->where('normalized_external_name', ExternalServiceAlias::normalizeValue($alias))
        ->first()
        ?->service
        ?->name;

    expect($aliasServiceName('BAR 145 AUDIT'))->toBe('BAR TH 145 AUDIT')
        ->and($aliasServiceName('BAR 145 TRAVAUX'))->toBe('BAR TH 145 APRES TRAVAUX')
        ->and($aliasServiceName('RES EC 104 (01/01/25)'))->toBe('RES EC 104 2025')
        ->and($aliasServiceName('SAV - RES EC 104'))->toBe('RES EC 104')
        ->and($aliasServiceName('BAT-TH-125'))->toBe('BAR TH 125 TERTIAIRE')
        ->and($aliasServiceName('BAR-TH-171 (2026)'))->toBe('BAR TH 171')
        ->and($aliasServiceName('BAR EN 102 ISOLATION DES MURS'))->toBeNull()
        ->and(ExternalServiceAlias::query()->count())->toBe(28);
});

it('rejects non technician users when assigning a service during creation', function () {
    $manager = User::factory()->create([
        'role' => 0,
        'admin' => false,
    ]);
    $planningUser = User::factory()->create([
        'role' => 1,
        'admin' => false,
    ]);

    $this->actingAs($manager)
        ->from(route('manager.services'))
        ->post(route('manager.services.store'), [
            'type' => Service::TYPE_MAR,
            'name' => 'Contrôle MAR sensible',
            'average_duration_minutes' => 90,
            'technician_ids' => [$planningUser->id],
        ])
        ->assertRedirect(route('manager.services'))
        ->assertSessionHasErrors('technician_ids.0');

    expect(Service::query()->where('name', 'Contrôle MAR sensible')->exists())->toBeFalse();
});
