<?php

namespace App\Jobs;

use App\Services\Capcee\CapceeDeliveryProcessor;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendCapceeReport implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 480;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $deliveryId) {}

    public function uniqueId(): string
    {
        return (string) $this->deliveryId;
    }

    public function handle(CapceeDeliveryProcessor $processor): void
    {
        $processor->process($this->deliveryId);
    }
}
