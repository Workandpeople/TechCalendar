<?php

use App\Models\User;
use App\Services\ExternalAppointmentSourceRegistry;
use App\Services\SidebarNotifications;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true]);
    Http::preventStrayRequests();
    $this->navigation = function (string $html): DOMXPath {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NONET);

        return new DOMXPath($dom);
    };
});

it('renders collapsible admin technician manager data and planner sections with unique panels', function () {
    $user = User::factory()->create(['admin' => true, 'role' => 2]);
    $response = $this->actingAs($user)->get(route('profile'))->assertOk();
    $xpath = ($this->navigation)($response->getContent());
    foreach (['admin', 'technician-planning', 'manager', 'manager-data', 'planner'] as $key) {
        expect($xpath->query('//*[@id="app-sidebar"]//button[@data-sidebar-section-toggle="'.$key.'"]')->length)->toBe(1);
    }
    $buttons = $xpath->query('//button[@data-sidebar-section-toggle]');
    expect($buttons->length)->toBe(10);
    foreach ($buttons as $button) {
        expect($button->getAttribute('aria-expanded'))->toBe('true')
            ->and($xpath->query('//*[@id="'.$button->getAttribute('aria-controls').'"]')->length)->toBe(1);
    }
    expect($xpath->query('//*[@data-sidebar-storage-key="techcalendar.sidebar.sections.v2.'.$user->id.'"]')->length)->toBe(1)
        ->and($xpath->query('//*[@id="sidebar-desktop-manager-data-items"]//a')->length)->toBe(4)
        ->and($xpath->query('//*[@id="app-sidebar"]//a[normalize-space(.)="Les RDV"]')->length)->toBe(2)
        ->and($xpath->query('//*[@id="app-sidebar"]//a[normalize-space(.)="Les lots"]')->length)->toBe(1)
        ->and($xpath->query('//nav//a[contains(@href,"/appointments/modify")]')->length)->toBe(0);
    $response->assertDontSee('Suivi des RDV')->assertDontSee('Gestion des RDV')->assertDontSee('Mon planning');
});

it('keeps navigation scoped to the authenticated role', function (int $role, array $allowed, array $absent) {
    $user = User::factory()->create(['role' => $role, 'admin' => false]);
    $response = $this->actingAs($user)->get(route('profile'))->assertOk();
    $xpath = ($this->navigation)($response->getContent());
    foreach ($allowed as $label) {
        expect($xpath->query('//*[@id="app-sidebar"]//section[@aria-label="'.$label.'"]')->length)->toBe(1);
    }
    foreach ($absent as $label) {
        expect($xpath->query('//*[@id="app-sidebar"]//section[@aria-label="'.$label.'"]')->length)->toBe(0);
    }
    if ($role === 2) {
        expect($xpath->query('//*[@id="app-sidebar" or @id="crm-mobile-navigation"]')->length)->toBe(0);
        expect($xpath->query('//*[@id="sidebar-toggle"]')->length)->toBe(0);
    }
})->with([
    [0, ['Gérant', 'Gestion des données'], ['Admin', 'Planning', 'Planning technicien']],
    [1, ['Planning'], ['Admin', 'Gérant', 'Planning technicien']],
    [2, [], ['Admin', 'Gérant', 'Planning', 'Planning technicien']],
]);

it('places the same dossiers page inside the allowed manager and planner sections', function (int $role, bool $admin, array $sections) {
    $user = User::factory()->create(['role' => $role, 'admin' => $admin]);
    $response = $this->actingAs($user)->get(route('v2.dossiers.index'))->assertOk()
        ->assertDontSee('Espace dossiers V2');
    $xpath = ($this->navigation)($response->getContent());
    expect($xpath->query('//h1[normalize-space(.)="Les dossiers"]')->length)->toBe(1);
    foreach (['desktop', 'mobile'] as $scope) {
        foreach ($sections as $section) {
            $links = $xpath->query('//*[@id="sidebar-'.$scope.'-'.$section.'-items"]//a[normalize-space(.)="Les dossiers"]');
            expect($links->length)->toBe(1)
                ->and($links->item(0)->getAttribute('href'))->toBe(route('v2.dossiers.index'))
                ->and($links->item(0)->getAttribute('aria-current'))->toBe('page');
        }
    }
    expect($xpath->query('//nav//a[normalize-space(.)="Les dossiers"]')->length)->toBe(count($sections) * 2);
})->with([
    [0, false, ['manager']],
    [1, false, ['planner']],
    [2, true, ['manager', 'planner']],
]);

it('hides dossier links and rejects the page when the registry is disabled', function (int $role, bool $admin) {
    config(['crm.v2_enabled' => false]);
    $user = User::factory()->create(['role' => $role, 'admin' => $admin]);
    $this->actingAs($user)->get(route('profile'))->assertOk()->assertDontSee('Les dossiers');
    $this->get(route('v2.dossiers.index'))->assertNotFound();
})->with([[0, false], [1, false], [2, true]]);

it('keeps the same sidebar on dossier pages and highlights the active nested reference page', function () {
    $user = User::factory()->create(['admin' => true, 'role' => 0]);
    $this->actingAs($user)->get(route('v2.dossiers.index'))->assertOk()
        ->assertSee('data-sidebar-section-toggle="admin"', false)
        ->assertSee('data-sidebar-section-toggle="manager-data"', false)
        ->assertSee('data-sidebar-section-toggle="planner"', false);
    $response = $this->get(route('manager.delegataires'))->assertOk();
    $xpath = ($this->navigation)($response->getContent());
    expect($xpath->query('//*[@id="sidebar-desktop-manager-data-items"]//a[@aria-current="page"]')->length)->toBe(1)
        ->and($xpath->query('//*[@id="app-sidebar"]//button[@data-sidebar-section-toggle="manager-data" and contains(@class,"sidebar-section-current")]')->length)->toBe(1);
});

it('keeps the sidebar toggle in the header instead of inside the hidden navigation', function () {
    $user = User::factory()->create(['admin' => true]);
    $response = $this->actingAs($user)->get(route('profile'))->assertOk();
    $xpath = ($this->navigation)($response->getContent());
    $buttons = $xpath->query('//header//button[@id="sidebar-toggle"]');
    expect($buttons->length)->toBe(1)
        ->and($buttons->item(0)->getAttribute('aria-controls'))->toBe('app-sidebar')
        ->and($buttons->item(0)->getAttribute('aria-expanded'))->toBe('true')
        ->and($xpath->query('//*[@id="app-sidebar"]//*[@id="sidebar-toggle"]')->length)->toBe(0)
        ->and($xpath->query('//html[@data-sidebar-collapse-storage-key="techcalendar.sidebar.collapsed"]')->length)->toBe(1);
    $response->assertDontSee('sidebar-collapsed');
});

it('removes API maintenance without querying or clearing the external appointment cache', function () {
    $this->mock(ExternalAppointmentSourceRegistry::class, function ($mock) {
        $mock->shouldNotReceive('resetRows');
        $mock->shouldNotReceive('resetLocalCache');
    });
    $user = User::factory()->create(['admin' => true]);
    $this->actingAs($user)->get(route('admin.settings'))->assertOk()
        ->assertDontSee('Maintenance API')->assertDontSee('Réinitialisation des RDV externes')
        ->assertDontSee(route('admin.settings.external-appointments.reset'))
        ->assertSee('Enregistrer les paramètres');
});

it('uses the shared Les RDV page title for managers and planners', function (int $role, string $route) {
    $user = User::factory()->create(['admin' => false, 'role' => $role]);
    $this->actingAs($user)->get(route($route))->assertOk()->assertViewHas('title', 'Les RDV');
})->with([[0, 'manager.appointments'], [1, 'planner.tracking']]);

it('renders matching link and folded section notification totals on desktop and mobile', function () {
    $user = User::factory()->create(['admin' => true, 'role' => 2]);
    $this->mock(SidebarNotifications::class)->shouldReceive('forUser')->once()
        ->andReturn(['settings' => 3, 'today' => 2, 'pending' => 12]);
    $response = $this->actingAs($user)->get(route('profile'))->assertOk();
    $xpath = ($this->navigation)($response->getContent());

    foreach (['desktop', 'mobile'] as $scope) {
        foreach (['admin' => 3, 'technician-planning' => 2, 'planner' => 12] as $section => $count) {
            $button = '//*[@aria-controls="sidebar-'.$scope.'-'.$section.'-items"]';
            $badge = $xpath->query($button.'/*[contains(@class,"sidebar-section-notification")]');
            expect($badge->length)->toBe(1)->and(trim($badge->item(0)->textContent))->toBe((string) $count);
        }
        $pending = $xpath->query('//*[@id="sidebar-'.$scope.'-planner-items"]//a[@href="'.route('planner.book').'"]//span[@aria-label="12 RDV à placer"]');
        expect($pending->length)->toBe(1);
    }
    expect($xpath->query('//button[@data-sidebar-section-toggle="manager"]/*[contains(@class,"sidebar-section-notification")]')->length)->toBe(0);
});

it('sums only leaf badges through nested sections without counting section totals twice', function () {
    $link = ['route' => 'profile', 'label' => 'Test', 'icon' => 'book', 'badge_label' => 'notifications'];
    $section = ['id' => 'root', 'label' => 'Root', 'collapsible' => true, 'items' => [
        $link + ['badge' => 3],
        ['id' => 'child', 'label' => 'Child', 'badge' => 99, 'collapsible' => true, 'items' => [
            $link + ['badge' => 2],
            ['id' => 'grandchild', 'label' => 'Grandchild', 'collapsible' => true, 'items' => [$link + ['badge' => 4]]],
        ]],
        $link + ['badge' => 0],
    ]];
    $html = Blade::render('<x-sidebar-section :section="$section" scope="test" />', compact('section'));
    $xpath = ($this->navigation)($html);
    foreach (['root' => 9, 'child' => 6, 'grandchild' => 4] as $id => $count) {
        $badge = $xpath->query('//button[@data-sidebar-section-toggle="'.$id.'"]/*[contains(@class,"sidebar-section-notification")]');
        expect($badge->length)->toBe(1)->and(trim($badge->item(0)->textContent))->toBe((string) $count);
    }
});
