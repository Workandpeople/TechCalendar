<?php

namespace App\Jobs;

use App\Services\CoffracAppointmentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SyncCoffracAppointmentsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800;

    public int $uniqueFor = 1800;

    public function __construct(public bool $incremental = true)
    {
    }

    public function uniqueId(): string
    {
        return CoffracAppointmentService::SOURCE;
    }

    public function handle(CoffracAppointmentService $coffracAppointments): void
    {
        $coffracAppointments->sync(incremental: $this->incremental);
    }

    public function failed(Throwable $exception): void
    {
        app(CoffracAppointmentService::class)->markSyncFailed(
            'Synchronisation Coffrac interrompue: '.$exception->getMessage(),
        );
    }
}
