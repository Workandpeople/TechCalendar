<?php

use App\Models\Appointment;
use App\Models\Service;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;

uses(RefreshDatabase::class);

it('only seeds the admin user in production', function () {
    $this->app->detectEnvironment(fn (): string => 'production');

    Config::set('admin.mail', 'admin@example.test');
    Config::set('admin.password', 'secure-admin-password');

    Artisan::call('db:seed', [
        '--class' => DatabaseSeeder::class,
        '--force' => true,
    ]);

    expect(User::query()->count())->toBe(1)
        ->and(User::query()->first()->admin)->toBeTrue()
        ->and(Service::query()->count())->toBe(0)
        ->and(Appointment::query()->count())->toBe(0);
});
