<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('adds billing permission as a denied by default boolean for existing and new users and rolls back safely', function () {
    $previousConnection = DB::getDefaultConnection();
    config(['database.connections.billing_migration_test' => ['driver' => 'sqlite', 'database' => ':memory:']]);
    DB::setDefaultConnection('billing_migration_test');

    try {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->boolean('admin')->default(false);
            $table->string('email');
        });
        DB::table('users')->insert(['email' => 'existing@example.test']);
        $migration = require database_path('migrations/2026_10_19_110000_add_user_billing_permission.php');

        $migration->up();
        DB::table('users')->insert(['email' => 'new@example.test']);
        expect(Schema::hasColumn('users', 'can_manage_billing'))->toBeTrue()
            ->and(DB::table('users')->pluck('can_manage_billing')->all())->toBe([0, 0]);
        DB::table('users')->where('email', 'existing@example.test')->update(['can_manage_billing' => true]);

        $migration->down();
        expect(Schema::hasColumn('users', 'can_manage_billing'))->toBeFalse()
            ->and(DB::table('users')->pluck('email')->all())->toBe(['existing@example.test', 'new@example.test']);

        $migration->up();
        expect(DB::table('users')->pluck('can_manage_billing')->all())->toBe([0, 0]);
    } finally {
        DB::setDefaultConnection($previousConnection);
        DB::purge('billing_migration_test');
    }
});
