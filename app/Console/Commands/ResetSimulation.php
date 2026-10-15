<?php

namespace App\Console\Commands;

use App\Services\Simulation\SimulationSafety;
use App\Services\Simulation\SnapshotReader;
use Database\Seeders\ProductionSnapshotSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class ResetSimulation extends Command
{
    protected $signature = 'simulation:reset {--force : Confirmer la suppression de la base locale de simulation}';

    protected $description = 'Recree uniquement la base locale *_simulation depuis les snapshots verifies.';

    public function handle(SimulationSafety $safety): int
    {
        $safety->assertSeedTarget();
        if (! $this->option('force')) {
            $this->error('Action destructive sur la simulation uniquement : ajouter --force pour confirmer.');

            return self::FAILURE;
        }
        // Validate every input before migrate:fresh can delete anything.
        $snapshot = new SnapshotReader(config('simulation.dataset'));
        foreach (SnapshotReader::TABLES as $table) {
            foreach ($snapshot->rows($table) as $_) {
                // Full streaming validation, including row counts and uniqueness.
            }
        }
        if (strlen((string) config('admin.password')) < 8 || ! filter_var(config('admin.mail'), FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Configurer le compte administrateur local avant le reset.');
        }
        $safety->activate();

        return Cache::store('file')->lock('simulation-reset', 3600)->block(1, function (): int {
            if ($this->call('migrate:fresh', ['--force' => true]) !== self::SUCCESS) {
                return self::FAILURE;
            }

            return $this->call('db:seed', ['--class' => ProductionSnapshotSeeder::class, '--force' => true]);
        });
    }
}
