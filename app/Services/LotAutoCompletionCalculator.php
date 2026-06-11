<?php

namespace App\Services;

use App\Models\Lot;
use App\Models\LotAppointment;
use Illuminate\Support\Collection;

class LotAutoCompletionCalculator
{
    /**
     * @param Collection<int, LotAppointment> $appointments
     * @return array{
     *     percentage:int,
     *     placed_count:int,
     *     target_count:int,
     *     total_count:int,
     *     detail:string,
     *     is_sampling:bool,
     *     sampling_percentage:float|null
     * }
     */
    public function calculate(Lot $lot, Collection $appointments): array
    {
        $totalCount = $appointments->count();
        $placedCount = $appointments
            ->filter(fn (LotAppointment $appointment): bool => $appointment->appointment_id !== null || $appointment->status === LotAppointment::STATUS_PLACED)
            ->count();
        $isSampling = Lot::requiresSamplingPercentageFor($lot->type);
        $samplingPercentage = $isSampling && $lot->sampling_percentage !== null
            ? max(0, min(100, (float) $lot->sampling_percentage))
            : null;
        $targetCount = $this->targetCount($totalCount, $isSampling, $samplingPercentage);
        $completedCount = min($placedCount, $targetCount);
        $percentage = $targetCount > 0
            ? (int) min(100, round(($completedCount / $targetCount) * 100))
            : 0;

        return [
            'percentage' => $percentage,
            'placed_count' => $placedCount,
            'target_count' => $targetCount,
            'total_count' => $totalCount,
            'detail' => $this->detail($completedCount, $targetCount, $placedCount, $isSampling, $samplingPercentage),
            'is_sampling' => $isSampling,
            'sampling_percentage' => $samplingPercentage,
        ];
    }

    private function targetCount(int $totalCount, bool $isSampling, ?float $samplingPercentage): int
    {
        if ($totalCount === 0) {
            return 0;
        }

        if (! $isSampling || $samplingPercentage === null) {
            return $totalCount;
        }

        return max(1, (int) ceil($totalCount * ($samplingPercentage / 100)));
    }

    private function detail(int $completedCount, int $targetCount, int $placedCount, bool $isSampling, ?float $samplingPercentage): string
    {
        if ($isSampling && $samplingPercentage !== null) {
            $samplingLabel = rtrim(rtrim(number_format($samplingPercentage, 2, ',', ' '), '0'), ',');

            return sprintf('%d/%d RDV objectif (%s%% du lot)', $completedCount, $targetCount, $samplingLabel);
        }

        if ($isSampling) {
            return sprintf('%d/%d RDV objectif (echantillonnage non defini)', $completedCount, $targetCount);
        }

        if ($placedCount > $targetCount) {
            return sprintf('%d/%d RDV objectif (%d places)', $completedCount, $targetCount, $placedCount);
        }

        return sprintf('%d/%d RDV places', $completedCount, $targetCount);
    }
}
