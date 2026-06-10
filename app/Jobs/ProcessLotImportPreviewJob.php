<?php

namespace App\Jobs;

use App\Events\LotImportPreviewProgressed;
use App\Models\LotImportPreview;
use App\Services\LotImportPreviewProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessLotImportPreviewJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 1500;

    public function __construct(private readonly int $previewId)
    {
    }

    public function handle(LotImportPreviewProcessor $processor): void
    {
        $preview = LotImportPreview::query()->findOrFail($this->previewId);

        try {
            $processor->process($preview);
        } catch (Throwable $exception) {
            $this->markPreviewAsFailed($preview->refresh(), $exception);

            Log::error('Lot import preview processing failed.', [
                'preview_id' => $preview->id,
                'stage' => $preview->stage,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    public function failed(Throwable $exception): void
    {
        $preview = LotImportPreview::query()->find($this->previewId);

        if (! $preview || $preview->status === LotImportPreview::STATUS_FAILED) {
            return;
        }

        $this->markPreviewAsFailed($preview, $exception);
    }

    private function markPreviewAsFailed(LotImportPreview $preview, Throwable $exception): void
    {
        $preview->update([
            'status' => LotImportPreview::STATUS_FAILED,
            'progress' => 100,
            'stage' => $preview->stage ? 'Erreur pendant: '.$preview->stage : 'Import en erreur.',
            'error_message' => $exception->getMessage(),
        ]);

        try {
            broadcast(new LotImportPreviewProgressed($preview->refresh()));
        } catch (Throwable $broadcastException) {
            Log::warning('Lot import failure broadcast failed.', [
                'preview_id' => $preview->id,
                'exception' => $broadcastException->getMessage(),
            ]);
        }
    }
}
