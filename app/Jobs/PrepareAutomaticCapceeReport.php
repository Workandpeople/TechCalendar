<?php

namespace App\Jobs;

use App\Services\Capcee\AutomaticCapceeReports;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PrepareAutomaticCapceeReport implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 600;

    public function __construct(public readonly int $submissionId) {}

    public function uniqueId(): string
    {
        return (string) $this->submissionId;
    }

    public function handle(AutomaticCapceeReports $reports): void
    {
        $reports->prepare($this->submissionId);
    }
}
