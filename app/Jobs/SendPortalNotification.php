<?php

namespace App\Jobs;

use App\Services\Crm\PortalNotifications;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendPortalNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public int $userId, public string $event, public int $dossierId, public int $sourceId, public ?string $status = null)
    {
        $this->afterCommit();
    }

    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(PortalNotifications $notifications): void
    {
        $notifications->deliver($this->userId, $this->event, $this->dossierId, $this->sourceId, $this->status);
    }
}
