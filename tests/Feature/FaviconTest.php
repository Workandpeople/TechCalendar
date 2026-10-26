<?php

use App\Models\ExternalDelegataire;
use App\Models\User;
use App\Services\Crm\ClientAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true]);
    Http::preventStrayRequests();
    $this->assertLogoFavicon = function (string $html): void {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NONET);
        $xpath = new DOMXPath($dom);
        $url = asset('images/logo.png').'?v='.substr(hash_file('sha256', public_path('images/logo.png')), 0, 12);
        foreach (['icon', 'apple-touch-icon'] as $relation) {
            $links = $xpath->query('//head/link[@rel="'.$relation.'"]');
            expect($links->length)->toBe(1)
                ->and($links->item(0)->getAttribute('href'))->toBe($url);
        }
        expect($xpath->query('//head/link[@rel="icon"]')->item(0)->getAttribute('type'))->toBe('image/png');
    };
});

it('uses the application logo on all authentication pages', function (string $path) {
    ($this->assertLogoFavicon)($this->get($path)->assertOk()->getContent());
})->with(['/login', '/forgot-password', '/reset-password/test-token']);

it('uses the same favicon throughout internal workspaces', function (int $role, bool $admin) {
    $this->actingAs(User::factory()->create(['role' => $role, 'admin' => $admin]));
    ($this->assertLogoFavicon)($this->get(route('profile'))->assertOk()->getContent());
})->with([[0, true], [0, false], [1, false], [2, false]]);

it('uses the same favicon for clients and delegataires', function (string $kind) {
    $organisation = ExternalDelegataire::create(['source' => 'native', 'external_id' => 'FAVICON-'.$kind,
        'kind' => $kind, 'name' => 'Organisation test', 'is_active' => true]);
    $this->actingAs(User::factory()->create(['role' => User::ROLE_EXTERNAL, 'admin' => false,
        'external_client_id' => $organisation->id, 'portal_enabled' => true, 'must_change_password' => false,
        'portal_permissions' => ClientAccess::defaults($kind)]));

    ($this->assertLogoFavicon)($this->get(route('client.dossiers'))->assertOk()->getContent());
})->with(['client', 'delegataire']);

it('also brands standalone browser views', function (string $view) {
    $this->actingAs(User::factory()->create());
    ($this->assertLogoFavicon)(view($view)->render());
})->with(['welcome', 'dashboard']);

it('provides a real multiresolution ico fallback using the same logo for error pages and browser defaults', function () {
    $contents = file_get_contents(public_path('favicon.ico'));
    $header = unpack('vreserved/vtype/vcount', substr($contents, 0, 6));
    expect($header)->toBe(['reserved' => 0, 'type' => 1, 'count' => 4]);

    $logo = imagecreatefrompng(public_path('images/logo.png'));
    foreach ([16, 32, 48, 256] as $index => $size) {
        $entry = unpack('Cwidth/Cheight/Ccolors/Creserved/vplanes/vbits/Vlength/Voffset', substr($contents, 6 + $index * 16, 16));
        $png = substr($contents, $entry['offset'], $entry['length']);
        $image = imagecreatefromstring($png);
        $expected = imagecreatetruecolor($size, $size);
        imagecopyresampled($expected, $logo, 0, 0, 0, 0, $size, $size, imagesx($logo), imagesy($logo));

        expect($entry['width'])->toBe($size % 256)
            ->and($entry['height'])->toBe($size % 256)
            ->and($entry['bits'])->toBe(32)
            ->and(imagesx($image))->toBe($size)
            ->and(imagesy($image))->toBe($size);
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if (imagecolorat($image, $x, $y) !== imagecolorat($expected, $x, $y)) {
                    throw new RuntimeException('The favicon does not match the application logo.');
                }
            }
        }
    }
});
