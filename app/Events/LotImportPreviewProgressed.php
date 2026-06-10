<?php

namespace App\Events;

use App\Models\LotImportPreview;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LotImportPreviewProgressed implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(private readonly LotImportPreview $preview)
    {
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('lot-import-preview.'.$this->preview->uuid),
        ];
    }

    public function broadcastAs(): string
    {
        return 'lot-import-preview.progressed';
    }

    public function broadcastWith(): array
    {
        return [
            'uuid' => $this->preview->uuid,
            'status' => $this->preview->status,
            'progress' => $this->preview->progress,
            'stage' => $this->preview->stage,
            'error_message' => $this->preview->error_message,
            'total_rows' => $this->preview->total_rows,
            'normalized_rows' => $this->preview->normalized_rows,
            'rejected_rows' => $this->preview->rejected_rows,
            'completed_at' => $this->preview->completed_at?->toIso8601String(),
        ];
    }
}
