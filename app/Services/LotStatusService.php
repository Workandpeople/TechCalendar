<?php

namespace App\Services;

use App\Models\Lot;
use App\Models\LotAppointment;
use Illuminate\Support\Collection;

class LotStatusService
{
    public function __construct(
        private readonly LotAutoCompletionCalculator $autoCompletion,
    ) {
    }

    public function refresh(?Lot $lot): void
    {
        if (! $lot || Lot::isArchivedStatus($lot->status)) {
            return;
        }

        $lot->loadMissing('appointments');

        $status = $this->resolve($lot, $lot->appointments);

        if ($lot->status !== $status) {
            $lot->update(['status' => $status]);
        }
    }

    /**
     * @param Collection<int, LotAppointment>|null $appointments
     */
    public function resolve(Lot $lot, ?Collection $appointments = null): string
    {
        if (Lot::isArchivedStatus($lot->status)) {
            return Lot::STATUS_ARCHIVED;
        }

        $appointments ??= $lot->appointments;
        $statsAppointments = $appointments
            ->reject(fn (LotAppointment $appointment): bool => (bool) $appointment->excluded_from_lot_stats)
            ->values();
        $completion = $this->autoCompletion->calculate($lot, $statsAppointments);
        $targetCount = (int) ($completion['target_count'] ?? 0);
        $satisfactionRemainingCount = (int) ($completion['satisfaction_remaining_count'] ?? $targetCount);

        return $targetCount > 0 && $satisfactionRemainingCount <= 0
            ? Lot::STATUS_TO_INVOICE
            : Lot::STATUS_IN_PROGRESS;
    }
}
