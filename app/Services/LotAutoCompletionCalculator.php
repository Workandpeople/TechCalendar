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
     *     sampling_percentage:float|null,
     *     physical:array<string, mixed>|null,
     *     contact:array<string, mixed>|null
     * }
     */
    public function calculate(Lot $lot, Collection $appointments): array
    {
        $appointments = $appointments
            ->reject(fn (LotAppointment $appointment): bool => (bool) $appointment->excluded_from_lot_stats)
            ->values();

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

        $result = [
            'percentage' => $percentage,
            'placed_count' => $placedCount,
            'target_count' => $targetCount,
            'total_count' => $totalCount,
            'detail' => $this->detail($completedCount, $targetCount, $placedCount, $isSampling, $samplingPercentage),
            'is_sampling' => $isSampling,
            'sampling_percentage' => $samplingPercentage,
        ];

        $result['physical'] = $lot->supportsPhysicalProcessing()
            ? $this->channelCompletion(
                $appointments,
                'physique',
                $lot->physicalSamplingPercentage(),
                $lot->type === Lot::TYPE_SAMPLE_CONTROL || $lot->isHybrid(),
                fn (LotAppointment $appointment): bool => $appointment->physical_satisfaction !== null
                    || $appointment->appointment_id !== null
                    || $appointment->status === LotAppointment::STATUS_PLACED,
            )
            : null;
        $result['contact'] = $lot->supportsContactProcessing()
            ? $this->channelCompletion(
                $appointments,
                'contact',
                $lot->contactSamplingPercentage(),
                $lot->type === Lot::TYPE_SAMPLE_CONTACT_CONTROL || $lot->isHybrid(),
                fn (LotAppointment $appointment): bool => $appointment->contact_satisfaction !== null
                    || $appointment->status === LotAppointment::STATUS_CONTACT_PROCESSED,
            )
            : null;

        return $result;
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

    /**
     * @param Collection<int, LotAppointment> $appointments
     * @return array{
     *     label:string,
     *     percentage:int,
     *     completed_count:int,
     *     target_count:int,
     *     total_count:int,
     *     detail:string,
     *     is_sampling:bool,
     *     sampling_percentage:float|null
     * }
     */
    private function channelCompletion(
        Collection $appointments,
        string $label,
        ?float $samplingPercentage,
        bool $isSampling,
        callable $isCompleted,
    ): array {
        $totalCount = $appointments->count();
        $safeSamplingPercentage = $isSampling && $samplingPercentage !== null
            ? max(0, min(100, (float) $samplingPercentage))
            : null;
        $targetCount = $this->targetCount($totalCount, $isSampling, $safeSamplingPercentage);
        $completedCount = min($appointments->filter($isCompleted)->count(), $targetCount);
        $percentage = $targetCount > 0
            ? (int) min(100, round(($completedCount / $targetCount) * 100))
            : 0;

        return [
            'label' => $label,
            'percentage' => $percentage,
            'completed_count' => $completedCount,
            'target_count' => $targetCount,
            'total_count' => $totalCount,
            'detail' => $this->channelDetail($label, $completedCount, $targetCount, $isSampling, $safeSamplingPercentage),
            'is_sampling' => $isSampling,
            'sampling_percentage' => $safeSamplingPercentage,
        ];
    }

    private function channelDetail(string $label, int $completedCount, int $targetCount, bool $isSampling, ?float $samplingPercentage): string
    {
        if ($isSampling && $samplingPercentage !== null) {
            $samplingLabel = rtrim(rtrim(number_format($samplingPercentage, 2, ',', ' '), '0'), ',');

            return sprintf('%d/%d %s (%s%%)', $completedCount, $targetCount, $label, $samplingLabel);
        }

        if ($isSampling) {
            return sprintf('%d/%d %s (échantillonnage non défini)', $completedCount, $targetCount, $label);
        }

        return sprintf('%d/%d %s', $completedCount, $targetCount, $label);
    }

    private function detail(int $completedCount, int $targetCount, int $placedCount, bool $isSampling, ?float $samplingPercentage): string
    {
        if ($isSampling && $samplingPercentage !== null) {
            $samplingLabel = rtrim(rtrim(number_format($samplingPercentage, 2, ',', ' '), '0'), ',');

            return sprintf('%d/%d RDV objectif (%s%% du lot)', $completedCount, $targetCount, $samplingLabel);
        }

        if ($isSampling) {
            return sprintf('%d/%d RDV objectif (échantillonnage non défini)', $completedCount, $targetCount);
        }

        if ($placedCount > $targetCount) {
            return sprintf('%d/%d RDV objectif (%d places)', $completedCount, $targetCount, $placedCount);
        }

        return sprintf('%d/%d RDV placés', $completedCount, $targetCount);
    }
}
