<?php

namespace App\Jobs;

use App\Models\LotReportExport;
use App\Services\Crm\LotReportExports;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class BuildLotReportExport implements ShouldQueue
{
    use Queueable;

    public int $tries = 60;

    public int $timeout = 600;

    public int $maxExceptions = 3;

    public bool $failOnTimeout = true;

    public function __construct(public readonly string $exportId)
    {
        $this->onQueue('report-exports');
    }

    public function handle(LotReportExports $exports): void
    {
        if (! $exports->build($this->exportId)) {
            $this->release(15);
        }
    }

    public function failed(): void
    {
        LotReportExport::query()->whereKey($this->exportId)->whereIn('status', ['queued', 'building'])
            ->update(['status' => 'failed', 'failure_message' => 'La préparation a été interrompue. Demandez une nouvelle archive.']);
    }
}
