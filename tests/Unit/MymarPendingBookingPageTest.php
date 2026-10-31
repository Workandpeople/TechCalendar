<?php

use App\Services\Mymar\MymarPendingBookingPage;

function pendingMarPageFixture(): array
{
    return ['format' => 'cofrac.mymar-pending-bookings', 'version' => 1, 'items' => [
        ['dossier_id' => 123, 'status_id' => 1, 'phase' => 'initial', 'expected_revision' => str_repeat('a', 64),
            'first_name' => 'Test', 'last_name' => 'Beneficiary', 'address' => 'Test address', 'postal_code' => '01560',
            'city' => 'Test city', 'phone' => '', 'email' => null, 'latitude' => null, 'longitude' => null],
    ], 'next_after' => 123];
}

it('validates a complete typed page and preserves postal leading zeroes', function () {
    $data = pendingMarPageFixture();
    $page = MymarPendingBookingPage::fromArray($data, 0, 1);
    expect($page->items)->toBe($data['items'])->and($page->items[0]['postal_code'])->toBe('01560')->and($page->nextAfter)->toBe(123);
    expect(MymarPendingBookingPage::fromArray(array_replace($data, ['items' => [], 'next_after' => null]), 123, 50)->items)->toBe([]);
});

it('refuses incomplete, excessive, inconsistent or looping pages', function (string $case) {
    $data = pendingMarPageFixture();
    switch ($case) {
        case 'missing cursor': unset($data['next_after']);
            break;
        case 'wrong format': $data['format'] = 'other';
            break;
        case 'extra field': $data['items'][0]['password'] = 'secret';
            break;
        case 'missing field': unset($data['items'][0]['city']);
            break;
        case 'repeated dossier': $data['items'][] = $data['items'][0];
            break;
        case 'out of order': $data['items'][] = array_replace($data['items'][0], ['dossier_id' => 122]);
            break;
        case 'string id': $data['items'][0]['dossier_id'] = '123';
            break;
        case 'not pending': $data['items'][0]['status_id'] = 3;
            break;
        case 'wrong phase': $data['items'][0]['phase'] = 'after_works';
            break;
        case 'wrong revision': $data['items'][0]['expected_revision'] = 'invalid';
            break;
        case 'wrong coordinates': $data['items'][0]['latitude'] = 91.0;
            $data['items'][0]['longitude'] = 2.0;
            break;
        case 'partial coordinates': $data['items'][0]['latitude'] = 48.0;
            break;
        case 'string coordinates': $data['items'][0]['latitude'] = '48.0';
            $data['items'][0]['longitude'] = '2.0';
            break;
        case 'oversized text': $data['items'][0]['address'] = str_repeat('a', 4097);
            break;
        case 'control character': $data['items'][0]['last_name'] = "bad\0name";
            break;
        case 'invalid UTF8': $data['items'][0]['last_name'] = "\xff";
            break;
        case 'backward cursor': $data['next_after'] = 122;
            break;
    }
    expect(fn () => MymarPendingBookingPage::fromArray($data, 0, 1))->toThrow(RuntimeException::class);
})->with(['missing cursor', 'wrong format', 'extra field', 'missing field', 'repeated dossier', 'out of order', 'string id',
    'not pending', 'wrong phase', 'wrong revision', 'wrong coordinates', 'partial coordinates', 'string coordinates',
    'oversized text', 'control character', 'invalid UTF8', 'backward cursor']);

it('does not accept a next cursor on a non-full page or a dossier at or behind the current cursor', function () {
    $data = pendingMarPageFixture();
    expect(fn () => MymarPendingBookingPage::fromArray($data, 0, 2))->toThrow(RuntimeException::class)
        ->and(fn () => MymarPendingBookingPage::fromArray($data, 123, 1))->toThrow(RuntimeException::class);
});

it('turns null-island coordinates into a missing position, not a routable point', function () {
    $data = pendingMarPageFixture();
    $data['items'][0]['latitude'] = $data['items'][0]['longitude'] = 0;
    $page = MymarPendingBookingPage::fromArray($data, 0, 1);
    expect($page->items[0]['latitude'])->toBeNull()->and($page->items[0]['longitude'])->toBeNull();
});
