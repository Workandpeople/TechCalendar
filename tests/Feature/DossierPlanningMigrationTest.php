<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('preserves first version appointment links and dependent records through a migration round trip', function () {
    // Schema rebuilds must run outside a test transaction, on an isolated database.
    $previous = DB::getDefaultConnection();
    config(['database.connections.crm_migration_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]]);
    DB::setDefaultConnection('crm_migration_test');
    try {
        Schema::create('appointments', fn (Blueprint $table) => $table->id());
        Schema::create('dossier_operations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('appointment_id')->nullable()->unique()->constrained()->restrictOnDelete();
        });
        Schema::create('appointment_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('appointment_id')->constrained()->cascadeOnDelete();
        });
        Schema::create('operation_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dossier_operation_id')->constrained()->restrictOnDelete();
        });
        DB::table('appointments')->insert(['id' => 42]);
        DB::table('dossier_operations')->insert(['id' => 7, 'appointment_id' => 42]);
        DB::table('appointment_notes')->insert(['appointment_id' => 42]);
        DB::table('operation_notes')->insert(['dossier_operation_id' => 7]);
        $migration = require database_path('migrations/2026_10_14_150000_link_appointments_to_dossier_operations.php');
        $migration->up();
        expect(Schema::hasColumn('dossier_operations', 'appointment_id'))->toBeFalse()
            ->and(DB::table('appointments')->where('id', 42)->value('dossier_operation_id'))->toBe(7)
            ->and(DB::table('appointment_notes')->count())->toBe(1)
            ->and(DB::table('operation_notes')->count())->toBe(1)
            ->and(DB::select('PRAGMA foreign_key_check'))->toBe([]);
        $migration->down();
        expect(DB::table('dossier_operations')->where('id', 7)->value('appointment_id'))->toBe(42)
            ->and(DB::table('appointment_notes')->count())->toBe(1)
            ->and(DB::table('operation_notes')->count())->toBe(1)
            ->and(DB::select('PRAGMA foreign_key_check'))->toBe([]);
        $migration->up();
        DB::table('appointments')->insert(['id' => 43, 'dossier_operation_id' => 7]);
        expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Retour arrière refusé');
        expect(DB::table('appointments')->where('dossier_operation_id', 7)->count())->toBe(2);
    } finally {
        DB::setDefaultConnection($previous);
        DB::purge('crm_migration_test');
    }
});
