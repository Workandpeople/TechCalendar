<?php

use App\Services\SidebarNotifications;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Http::preventStrayRequests();
    Mail::fake();
    $this->withoutVite();
});

afterEach(function () {
    Http::assertNothingSent();
    Mail::assertNothingOutgoing();
});

it('keeps the normal application sidebar and booking landmarks with fictitious data and no SQL', function () {
    app()->instance('env', 'local');
    DB::shouldReceive('connection')->never();
    $this->get(route('local.calendar'))->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertSee('id="app-sidebar"', false)
        ->assertSee('TechCalendar')->assertSee('Prise de RDV')->assertSee('Choisir un dossier')
        ->assertSee('RDV à placer')->assertSee('Techniciens éligibles')->assertSee('Disponibilités visuelles')
        ->assertSee('Données fictives.');
});

it('never exposes a fictitious authenticated user or calendar lab in production or demo', function (string $environment) {
    app()->instance('env', $environment);
    $notifications = app(SidebarNotifications::class);
    app()->instance(SidebarNotifications::class, $notifications);
    $this->get(route('local.calendar'))->assertNotFound()->assertDontSee('calendar-lab.js');
    $this->assertGuest();
    expect(app(SidebarNotifications::class))->toBe($notifications);
})->with(['production', 'demo']);

it('only opts into the native engine on an explicit local request', function () {
    config(['calendar.engine' => 'fullcalendar']);
    app()->instance('request', Request::create('/planner/book?calendar=native'));
    app()->instance('env', 'production');
    expect(view('planner.partials.calendar-engine')->render())->toContain('fullcalendar@6.1.15')->not->toContain('Essai local');
    app()->instance('env', 'local');
    expect(view('planner.partials.calendar-engine')->render())->toContain('Essai local')->not->toContain('fullcalendar@6.1.15');
    app()->instance('request', Request::create('/planner/book'));
    expect(view('planner.partials.calendar-engine')->render())->toContain('fullcalendar@6.1.15');
});

it('switches only the calendar assets through the independent deployment setting', function () {
    app()->instance('env', 'production');
    app()->instance('request', Request::create('/planner/book'));
    config(['calendar.engine' => 'native']);
    expect(view('planner.partials.calendar-engine')->render())->not->toContain('fullcalendar@6.1.15')->not->toContain('Essai local');
    config(['calendar.engine' => 'fullcalendar']);
    expect(view('planner.partials.calendar-engine')->render())->toContain('fullcalendar@6.1.15');
});
