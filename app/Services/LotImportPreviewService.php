<?php

namespace App\Services;

use App\Jobs\ProcessLotImportPreviewJob;
use App\Models\LotImportPreview;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class LotImportPreviewService
{
    public function createFromUpload(
        UploadedFile $file,
        int $userId,
        string $lotType,
        ?float $samplingPercentage = null,
        ?string $requestedLotName = null,
    ): LotImportPreview {
        $storedFile = $this->storeOriginalFile($file);

        $preview = LotImportPreview::query()->create([
            'uuid' => (string) Str::uuid(),
            'status' => LotImportPreview::STATUS_PENDING,
            'progress' => 0,
            'stage' => 'Import en attente dans la file.',
            'name' => filled($requestedLotName) ? trim((string) $requestedLotName) : null,
            'type' => $lotType,
            'sampling_percentage' => $samplingPercentage,
            'original_filename' => $file->getClientOriginalName(),
            'original_file_disk' => $storedFile['disk'],
            'original_file_path' => $storedFile['path'],
            'original_file_size' => $storedFile['size'],
            'original_file_mime' => $storedFile['mime'],
            'ai_model' => config('services.openai.model'),
            'created_by' => $userId,
        ]);

        ProcessLotImportPreviewJob::dispatch($preview->id);

        return $preview;
    }

    public function retry(LotImportPreview $preview): LotImportPreview
    {
        if ($preview->status !== LotImportPreview::STATUS_FAILED) {
            throw new RuntimeException('Seul un import en erreur peut etre relance.');
        }

        if (! filled($preview->original_file_disk) || ! filled($preview->original_file_path)) {
            throw new RuntimeException('Le fichier original de cet import est introuvable.');
        }

        if (! Storage::disk((string) $preview->original_file_disk)->exists((string) $preview->original_file_path)) {
            throw new RuntimeException('Le fichier original de cet import n existe plus en stockage.');
        }

        $preview->update([
            'status' => LotImportPreview::STATUS_PENDING,
            'progress' => 0,
            'stage' => 'Import relance et en attente dans la file.',
            'total_rows' => 0,
            'normalized_rows' => 0,
            'rejected_rows' => 0,
            'ai_model' => config('services.openai.model'),
            'payload' => null,
            'error_message' => null,
            'completed_at' => null,
        ]);

        ProcessLotImportPreviewJob::dispatch($preview->id);

        return $preview->refresh();
    }

    /**
     * @return array{disk:string,path:string,size:int|null,mime:string|null}
     */
    private function storeOriginalFile(UploadedFile $file): array
    {
        $disk = 'local';
        $extension = strtolower($file->getClientOriginalExtension() ?: 'bin');
        $path = $file->storeAs(
            'lot-import-previews/'.now()->format('Y/m'),
            Str::uuid()->toString().'.'.$extension,
            $disk,
        );

        if (! $path) {
            throw new \RuntimeException('Impossible de sauvegarder le fichier original du lot.');
        }

        return [
            'disk' => $disk,
            'path' => $path,
            'size' => $file->getSize(),
            'mime' => $file->getClientMimeType(),
        ];
    }
}
