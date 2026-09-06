<?php

namespace App\Jobs;

use App\Models\LotAppointment;
use App\Models\LotAppointmentDocument;
use App\Services\GlobalPlus\GlobalPlusAppointmentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SyncLotAppointmentDocumentsToGlobalPlusJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function __construct(private readonly int $lotAppointmentId) {}

    public function handle(GlobalPlusAppointmentService $globalPlusAppointments): void
    {
        $lotAppointment = LotAppointment::query()
            ->with(['documents', 'lot'])
            ->find($this->lotAppointmentId);

        if (! $lotAppointment || ! filled($lotAppointment->global_plus_demand_id)) {
            return;
        }

        $globalPlusAppointments->syncDocuments($lotAppointment);
    }

    public function failed(Throwable $exception): void
    {
        LotAppointment::query()
            ->whereKey($this->lotAppointmentId)
            ->update([
                'global_plus_status' => GlobalPlusAppointmentService::STATUS_DOCUMENTS_FAILED,
                'global_plus_error_message' => $exception->getMessage(),
            ]);

        LotAppointmentDocument::query()
            ->where('lot_appointment_id', $this->lotAppointmentId)
            ->update([
                'global_plus_error_message' => $exception->getMessage(),
            ]);
    }
}
