<?php

namespace App\Console\Commands;

use App\Services\Mymar\MymarCachedObservation;
use App\Services\Mymar\MymarPilotSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class MymarObservationStatus extends Command
{
    protected $signature = 'mymar:observation-status';

    protected $description = 'Verifie uniquement le cache MYMAR local, sans appel API ni modification.';

    public function handle(MymarPilotSettings $settings): int
    {
        try {
            $pilot = $settings->load();
            $snapshot = $pilot->syncEnabled ? Cache::get($pilot->cacheKey()) : null;
            if (! is_array($snapshot)) {
                throw new \RuntimeException;
            }
            MymarCachedObservation::validate($snapshot, $pilot);
            $age = now()->timestamp - MymarCachedObservation::instant($snapshot['generated_at'])->getTimestamp();
            $ready = $age >= -60 && $age <= 300 && ! Cache::has($pilot->cacheKey().':failure');
            $this->line(json_encode(['cache_ready' => $ready, 'periods' => count($snapshot['periods']),
                'pilot_technicians' => count($pilot->technicianMap), 'generated_at' => $snapshot['generated_at'],
                'window_start' => $snapshot['window_start'], 'window_end' => $snapshot['window_end'],
                'display_enabled' => $pilot->displayEnabled, 'blocking_enabled' => false, 'warnings' => $snapshot['warnings']], JSON_THROW_ON_ERROR));

            return $ready ? self::SUCCESS : self::FAILURE;
        } catch (Throwable) {
            $this->line('{"cache_ready":false,"blocking_enabled":false}');

            return self::FAILURE;
        }
    }
}
