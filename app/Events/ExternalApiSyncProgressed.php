<?php

namespace App\Events;

use App\Models\ExternalApiSync;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ExternalApiSyncProgressed implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(private readonly ExternalApiSync $sync)
    {
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('external-api-sync.'.$this->sync->source),
        ];
    }

    public function broadcastAs(): string
    {
        return 'external-api-sync.progressed';
    }

    public function broadcastWith(): array
    {
        $metadata = $this->sync->metadata ?? [];

        return [
            'source' => $this->sync->source,
            'state' => $this->sync->state,
            'label' => $metadata['label'] ?? null,
            'message' => $this->sync->message,
            'progress' => (int) ($metadata['progress'] ?? 0),
            'stage' => $metadata['stage'] ?? $this->sync->message,
            'processed' => (int) ($metadata['processed'] ?? 0),
            'total' => (int) ($metadata['total'] ?? 0),
            'last_started_at' => $this->sync->last_started_at?->toIso8601String(),
            'last_finished_at' => $this->sync->last_finished_at?->toIso8601String(),
            'last_successful_at' => $this->sync->last_successful_at?->toIso8601String(),
        ];
    }
}
