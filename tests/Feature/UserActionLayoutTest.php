<?php

use App\Models\User;
use Dom\HTMLDocument;
use Dom\XPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('keeps user row actions in a compact nonwrapping group including AJAX and disabled accounts', function (string $area, bool $disabled, bool $ajax) {
    Http::preventStrayRequests();
    $actor = User::factory()->create(['role' => 0, 'admin' => $area === 'admin']);
    $target = User::factory()->create(['role' => 2, 'admin' => false, 'email' => 'compact-actions@example.test']);
    if ($disabled) {
        $target->delete();
    }

    $response = $this->actingAs($actor)->get(route($area.'.users', ['q' => $target->email, 'status' => 'all']),
        $ajax ? ['X-Requested-With' => 'XMLHttpRequest'] : [])->assertOk();
    $dom = HTMLDocument::createFromString($response->getContent(), LIBXML_NOERROR | \Dom\HTML_NO_DEFAULT_NS);
    $xpath = new XPath($dom);
    $groups = $xpath->query('//tbody//div[contains(concat(" ", normalize-space(@class), " "), " gc-user-actions ")]');
    expect($groups->length)->toBe(1);
    $group = $groups->item(0);
    expect($group->className)->not->toContain('flex-wrap');
    expect($group->parentNode->className)->toContain('whitespace-nowrap', 'w-px');
    $row = $group->parentNode->parentNode;
    expect(str_contains($row->className, 'gc-user-row--inactive'))->toBe($disabled)
        ->and($xpath->query('.//span[@data-user-status="'.($disabled ? 'inactive' : 'active').'"]', $row)->length)->toBe(1);
    $badge = $xpath->query('.//span[@data-user-status]', $row)->item(0);
    expect(trim($badge->textContent))->toBe($disabled ? 'Désactivé' : 'Actif');
    expect($xpath->query('.//button', $group)->length)->toBe($disabled ? 2 : ($area === 'admin' ? 3 : 4));
    expect($xpath->query('ancestor::div[contains(concat(" ", normalize-space(@class), " "), " overflow-x-auto ")]', $group)->length)->toBe(1);
    if ($disabled) {
        $this->post(route($area.'.users.restore', $target->id))->assertRedirect();
        $this->get(route($area.'.users', ['q' => $target->email, 'status' => 'all']),
            $ajax ? ['X-Requested-With' => 'XMLHttpRequest'] : [])->assertOk()
            ->assertSee('data-user-status="active"', false)->assertDontSee('gc-user-row--inactive', false);
    }
    Http::assertNothingSent();
})->with(['admin', 'manager'])->with([false, true])->with([false, true]);
