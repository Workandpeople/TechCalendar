<?php

use App\Models\ApplicationSetting;
use App\Models\ApplicationSettingAudit;
use App\Models\User;
use App\Services\ApplicationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('falls back to env or configured fallback when no database value exists', function () {
    config(['application_settings.definitions' => [
        'test.setting' => [
            'group' => 'Test',
            'label' => 'Test setting',
            'type' => 'string',
            'env' => 'MISSING_TEST_ENV',
            'fallback' => 'fallback-value',
            'config' => 'services.test.setting',
            'rules' => ['nullable', 'string'],
        ],
    ]]);

    expect(app(ApplicationSettings::class)->get('test.setting'))->toBe('fallback-value');
});

it('uses encrypted database values before env values and applies them to config', function () {
    config(['application_settings.definitions' => [
        'test.secret' => [
            'group' => 'Test',
            'label' => 'Secret setting',
            'type' => 'password',
            'env' => 'MISSING_TEST_SECRET',
            'fallback' => 'env-value',
            'config' => 'services.test.secret',
            'rules' => ['nullable', 'string'],
            'secret' => true,
        ],
    ]]);

    ApplicationSetting::query()->create([
        'key' => 'test.secret',
        'group' => 'Test',
        'label' => 'Secret setting',
        'type' => 'password',
        'value' => 'database-secret',
        'is_secret' => true,
        'is_active' => true,
    ]);

    $settings = app(ApplicationSettings::class);
    $settings->applyToConfig();

    expect($settings->get('test.secret'))->toBe('database-secret')
        ->and(config('services.test.secret'))->toBe('database-secret')
        ->and(ApplicationSetting::query()->first()->getRawOriginal('value'))->not->toContain('database-secret');
});

it('audits updates and can reset a setting to fallback', function () {
    $admin = User::query()->create([
        'first_name' => 'Ada',
        'last_name' => 'Admin',
        'email' => 'admin-settings@example.test',
        'password' => bcrypt('password'),
        'admin' => true,
        'role' => 0,
    ]);

    config(['application_settings.definitions' => [
        'test.setting' => [
            'group' => 'Test',
            'label' => 'Test setting',
            'type' => 'string',
            'fallback' => 'fallback-value',
            'config' => 'services.test.setting',
            'rules' => ['nullable', 'string'],
        ],
    ]]);

    $settings = app(ApplicationSettings::class);
    $settings->update(['test.setting' => 'custom-value'], $admin->id);

    expect($settings->get('test.setting'))->toBe('custom-value')
        ->and(ApplicationSettingAudit::query()->count())->toBe(1);

    $settings->forget('test.setting', $admin->id);

    expect($settings->get('test.setting'))->toBe('fallback-value')
        ->and(ApplicationSettingAudit::query()->count())->toBe(2);
});

it('renders settings page only for admins', function () {
    $admin = User::query()->create([
        'first_name' => 'Ada',
        'last_name' => 'Admin',
        'email' => 'admin-settings-page@example.test',
        'password' => bcrypt('password'),
        'admin' => true,
        'role' => 0,
    ]);
    $planner = User::query()->create([
        'first_name' => 'Paul',
        'last_name' => 'Planning',
        'email' => 'planner-settings-page@example.test',
        'password' => bcrypt('password'),
        'admin' => false,
        'role' => 1,
    ]);

    $this->actingAs($admin)
        ->get(route('admin.settings'))
        ->assertOk()
        ->assertSee('Parametres');

    $this->actingAs($planner)
        ->get(route('admin.settings'))
        ->assertForbidden();
});
