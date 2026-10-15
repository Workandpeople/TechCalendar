<?php

namespace Database\Seeders;

use App\Services\Simulation\SnapshotImporter;
use App\Services\Simulation\SnapshotReader;
use Illuminate\Database\Seeder;

class ProductionSnapshotSeeder extends Seeder
{
    public function run(): void
    {
        $report = app(SnapshotImporter::class)->import(new SnapshotReader(config('simulation.dataset')));
        foreach ($report['counts'] as $label => $count) {
            $this->command?->line($label.' : '.$count);
        }
        foreach ($report['issues'] as $label => $issue) {
            $this->command?->warn($label.' : '.$issue['count']);
        }
    }
}
