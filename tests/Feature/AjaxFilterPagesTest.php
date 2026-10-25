<?php

use App\Models\Lot;
use App\Models\User;
use Dom\HTMLDocument;
use Dom\XPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

uses(RefreshDatabase::class);

it('keeps filter forms outside the replaceable results and renders syntactically valid modal handlers', function (string $route, string $key) {
    config(['crm.v2_enabled' => true]);
    Http::preventStrayRequests();
    $this->actingAs(User::factory()->create(['role' => 0, 'admin' => true]));
    $parameters = $route === 'manager.lots.show' ? [Lot::create(['name' => 'AJAX test', 'type' => Lot::TYPE_FULL_CONTACT_CONTROL])->id] : [];
    $response = $this->get(route($route, $parameters))->assertOk()->assertSee('data-ajax-list="'.$key.'"', false)
        ->assertSee('data-ajax-filters', false)->assertSee('data-ajax-results=', false);
    $dom = HTMLDocument::createFromString($response->getContent(), LIBXML_NOERROR | \Dom\HTML_NO_DEFAULT_NS);
    $xpath = new XPath($dom);
    expect($xpath->query('//*[@data-ajax-results]//form[@data-ajax-filters]')->length)->toBe(0);
    $node = (new ExecutableFinder)->find('node');
    if (! $node) {
        $this->markTestSkipped('Node.js is required to check inline JavaScript.');
    }
    foreach ($xpath->query('//script[not(@src) and (not(@type) or @type="module")]') as $script) {
        $process = new Process([$node, '--check', '--input-type=module']);
        $process->setInput($script->textContent)->run();
        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
    }
    Http::assertNothingSent();
})->with([
    ['admin.users', 'admin-users'], ['manager.users', 'manager-users'], ['manager.services', 'services'],
    ['manager.mail-templates', 'mail-templates'], ['manager.delegataires', 'clients'],
    ['manager.lots', 'lots'], ['manager.lots.show', 'lot-dossiers'],
]);
