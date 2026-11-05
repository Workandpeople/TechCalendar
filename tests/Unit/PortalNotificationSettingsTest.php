<?php

use App\Models\User;
use App\Services\Crm\PortalNotificationSettings;

it('normalizes recipient addresses without making any external call', function () {
    $settings = new PortalNotificationSettings;
    expect($settings->normalizeRecipients([' Team@Example.com ', 'direction@example.com', 'TEAM@example.com']))
        ->toBe(['team@example.com', 'direction@example.com']);
});

it('fails closed on invalid stored recipient lists', function (mixed $recipients) {
    expect((new PortalNotificationSettings)->normalizeRecipients($recipients))->toBe([]);
})->with([
    [null], [false], ['team@example.com'], [[123]], [[null]], [[['team@example.com']]], [['not-an-email']],
    [["team@example.com\r\nBcc: other@example.com"]], [['Team <team@example.com>']],
    [array_fill(0, 11, 'team@example.com')], [[str_repeat('a', 250).'@example.com']],
]);

it('uses the login email only for preferences without an explicit recipient field', function () {
    $settings = new PortalNotificationSettings;
    $user = new User(['email' => 'LOGIN@example.com']);
    $user->forceFill(['portal_notification_preferences' => ['email_enabled' => true, 'statuses' => ['report']]]);
    expect($settings->values($user)['recipients'])->toBe(['login@example.com']);
    $user->forceFill(['portal_notification_preferences' => ['email_enabled' => true, 'recipients' => []]]);
    expect($settings->values($user)['recipients'])->toBe([]);
    $user->forceFill(['portal_notification_preferences' => null]);
    expect($settings->values($user)['email_enabled'])->toBeFalse();
});
