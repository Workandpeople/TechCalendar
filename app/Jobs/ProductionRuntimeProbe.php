<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/** A deployment-only round trip through the dedicated worker, without external calls. */
final class ProductionRuntimeProbe implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 10;

    public function __construct(public string $nonce) {}

    public function handle(): void
    {
        if (! app()->environment('production') || config('database.connections.mysql.database') !== 'cofrac2production'
            || ! in_array(config('app.url'), ['https://cofrac2.geniuscontrole.com', 'https://cofrac.geniuscontrole.com'], true)
            || ! preg_match('/^[a-f0-9]{32}$/D', $this->nonce)) {
            throw new RuntimeException('Sonde du worker hors de sa cible de production V2.');
        }
        Cache::put('cofrac2.runtime-probe.'.$this->nonce, 'processed', 60);
    }
}
