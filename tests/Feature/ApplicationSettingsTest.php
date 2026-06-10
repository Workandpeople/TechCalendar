<?php

use App\Models\ApplicationSetting;
use App\Models\ApplicationSettingAudit;
use App\Models\User;
use App\Services\ApplicationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

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

it('keeps existing cached config values when env is unavailable', function () {
    config([
        'services.cached.secret' => 'cached-config-secret',
        'application_settings.definitions' => [
            'test.cached.secret' => [
                'group' => 'Test',
                'label' => 'Cached secret',
                'type' => 'password',
                'env' => 'MISSING_CACHED_SECRET',
                'config' => 'services.cached.secret',
                'rules' => ['nullable', 'string'],
                'secret' => true,
            ],
        ],
    ]);

    $settings = app(ApplicationSettings::class);
    $settings->applyToConfig();

    expect($settings->get('test.cached.secret'))->toBe('cached-config-secret')
        ->and(config('services.cached.secret'))->toBe('cached-config-secret');
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

it('exposes the effective source used by configurable settings', function () {
    Cache::forget(ApplicationSettings::CACHE_KEY);

    config([
        'services.test.env_setting' => 'cached-env-value',
        'services.test.database_setting' => 'cached-env-secret',
        'application_settings.definitions' => [
            'test.env_setting' => [
                'group' => 'Test',
                'label' => 'Env setting',
                'type' => 'string',
                'env' => 'MISSING_ENV_SETTING',
                'config' => 'services.test.env_setting',
                'rules' => ['nullable', 'string'],
            ],
            'test.database_setting' => [
                'group' => 'Test',
                'label' => 'Database setting',
                'type' => 'password',
                'env' => 'MISSING_DATABASE_SETTING',
                'config' => 'services.test.database_setting',
                'rules' => ['nullable', 'string'],
                'secret' => true,
            ],
        ],
    ]);

    ApplicationSetting::query()->create([
        'key' => 'test.database_setting',
        'group' => 'Test',
        'label' => 'Database setting',
        'type' => 'password',
        'value' => 'database-secret',
        'is_secret' => true,
        'is_active' => true,
    ]);

    $rows = collect(app(ApplicationSettings::class)->formRows())->keyBy('key');

    expect($rows['test.env_setting']['source'])->toBe('env')
        ->and($rows['test.env_setting']['has_env_value'])->toBeTrue()
        ->and($rows['test.env_setting']['env_key'])->toBe('MISSING_ENV_SETTING')
        ->and($rows['test.database_setting']['source'])->toBe('bdd')
        ->and($rows['test.database_setting']['has_database_value'])->toBeTrue()
        ->and($rows['test.database_setting']['has_env_value'])->toBeTrue();
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

it('renders setting source badges in the admin settings page', function () {
    Cache::forget(ApplicationSettings::CACHE_KEY);

    $admin = User::query()->create([
        'first_name' => 'Ada',
        'last_name' => 'Admin',
        'email' => 'admin-settings-source@example.test',
        'password' => bcrypt('password'),
        'admin' => true,
        'role' => 0,
    ]);

    config([
        'services.test.visible_setting' => 'visible-env-value',
        'application_settings.definitions' => [
            'test.visible_setting' => [
                'group' => 'Test',
                'label' => 'Visible setting',
                'type' => 'string',
                'env' => 'VISIBLE_SETTING',
                'config' => 'services.test.visible_setting',
                'rules' => ['nullable', 'string'],
            ],
        ],
    ]);

    $this->actingAs($admin)
        ->get(route('admin.settings'))
        ->assertOk()
        ->assertSee('.ENV actif')
        ->assertSee('.env: VISIBLE_SETTING')
        ->assertSee('Valeur .env disponible');
});

it('renders OpenAI model as a select list', function () {
    $admin = User::query()->create([
        'first_name' => 'Ada',
        'last_name' => 'Admin',
        'email' => 'admin-openai-model@example.test',
        'password' => bcrypt('password'),
        'admin' => true,
        'role' => 0,
    ]);

    expect(config('application_settings.definitions')['services.openai.model']['type'])->toBe('select');
    expect(config('application_settings.definitions')['services.openai.import_chunk_size']['type'])->toBe('integer');

    $this->actingAs($admin)
        ->get(route('admin.settings'))
        ->assertOk()
        ->assertSee('GPT-5.4 mini')
        ->assertSee('GPT-4o mini')
        ->assertSee('OpenAI lignes par paquet');
});
