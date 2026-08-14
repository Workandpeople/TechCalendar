<?php

namespace App\Jobs;

use App\Models\LotAppointmentDocument;
use App\Services\CoffracAppointmentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class PushLotAppointmentDocumentToCoffracJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 180;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function __construct(private readonly int $documentId)
    {
    }

    public function handle(CoffracAppointmentService $coffracAppointments): void
    {
        $document = LotAppointmentDocument::query()
            ->with(['lotAppointment.appointment', 'appointment', 'uploader'])
            ->find($this->documentId);

        if (! $document) {
            return;
        }

        $appointment = $document->appointment ?: $document->lotAppointment?->appointment;

        if (! $appointment) {
            $document->update([
                'status' => LotAppointmentDocument::STATUS_PENDING,
                'error_message' => null,
            ]);

            return;
        }

        $disk = Storage::disk($document->disk);

        if (! $disk->exists($document->path)) {
            throw new RuntimeException('Le fichier local du document est introuvable.');
        }

        $document->update([
            'appointment_id' => $appointment->id,
            'status' => LotAppointmentDocument::STATUS_QUEUED,
            'error_message' => null,
        ]);

        $localPath = $disk->path($document->path);
        $uploadedFile = new UploadedFile(
            $localPath,
            $document->original_name ?: $document->name,
            $document->mime ?: 'application/octet-stream',
            null,
            true,
        );

        $remoteDocument = $coffracAppointments->uploadDocument(
            $appointment,
            $uploadedFile,
            $document->name,
            'Document rattaché au dossier de lot TechCalendar.',
            $document->uploader,
            (bool) $document->is_private,
        );

        $document->update([
            'status' => LotAppointmentDocument::STATUS_UPLOADED,
            'pushed_at' => now(),
            'remote_document' => $remoteDocument,
            'error_message' => null,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        LotAppointmentDocument::query()
            ->whereKey($this->documentId)
            ->update([
                'status' => LotAppointmentDocument::STATUS_FAILED,
                'error_message' => $exception->getMessage(),
            ]);
    }
}
