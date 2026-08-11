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
     *     completed_count:int,
     *     remaining_count:int,
     *     target_count:int,
     *     total_count:int,
     *     satisfied_count:int,
     *     raw_satisfied_count:int,
     *     unsatisfied_count:int,
     *     raw_unsatisfied_count:int,
     *     satisfaction_answered_count:int,
     *     satisfaction_remaining_count:int,
     *     satisfaction_percentage:int,
     *     dissatisfaction:array<string, mixed>,
     *     total_satisfaction:array<string, mixed>,
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
        $totalSatisfiedCount = $appointments
            ->filter(fn (LotAppointment $appointment): bool => (
                $lot->supportsPhysicalProcessing() && $appointment->physical_satisfaction === true
            ) || (
                $lot->supportsContactProcessing() && $appointment->contact_satisfaction === true
            ))
            ->count();
        $placedCount = $appointments
            ->filter(fn (LotAppointment $appointment): bool => $appointment->appointment_id !== null || $appointment->status === LotAppointment::STATUS_PLACED)
            ->count();
        $physical = $lot->supportsPhysicalProcessing()
            ? $this->channelCompletion(
                $appointments,
                'physique',
                $lot->physicalSamplingPercentage(),
                $lot->type === Lot::TYPE_SAMPLE_CONTROL || $lot->isHybrid(),
                fn (LotAppointment $appointment): bool => $appointment->physical_satisfaction !== null
                    || $appointment->processing_mode === LotAppointment::PROCESSING_MODE_PHYSICAL
                    || $appointment->appointment_id !== null
                    || $appointment->status === LotAppointment::STATUS_PLACED,
                fn (LotAppointment $appointment): ?bool => $appointment->processing_mode === LotAppointment::PROCESSING_MODE_CONTACT
                    ? null
                    : $appointment->physical_satisfaction,
                fn (LotAppointment $appointment): bool => $appointment->processing_mode !== LotAppointment::PROCESSING_MODE_CONTACT,
            )
            : null;
        $contact = $lot->supportsContactProcessing()
            ? $this->channelCompletion(
                $appointments,
                'contact',
                $lot->contactSamplingPercentage(),
                $lot->type === Lot::TYPE_SAMPLE_CONTACT_CONTROL || $lot->isHybrid(),
                fn (LotAppointment $appointment): bool => $appointment->contact_satisfaction !== null
                    || $appointment->processing_mode === LotAppointment::PROCESSING_MODE_CONTACT
                    || $appointment->status === LotAppointment::STATUS_CONTACT_PROCESSED,
                fn (LotAppointment $appointment): ?bool => $appointment->processing_mode === LotAppointment::PROCESSING_MODE_PHYSICAL
                    ? null
                    : $appointment->contact_satisfaction,
                fn (LotAppointment $appointment): bool => $appointment->processing_mode !== LotAppointment::PROCESSING_MODE_PHYSICAL,
            )
            : null;
        $channels = collect([$physical, $contact])->filter();

        if ($channels->isNotEmpty()) {
            $targetCount = (int) $channels->sum('target_count');
            $completedCount = min((int) $channels->sum('completed_count'), $targetCount);
            $rawSatisfiedCount = (int) $channels->sum('raw_satisfied_count');
            $rawUnsatisfiedCount = (int) $channels->sum('raw_unsatisfied_count');
            $satisfiedCount = min((int) $channels->sum('satisfied_count'), $targetCount);
            $unsatisfiedCount = min((int) $channels->sum('unsatisfied_count'), max(0, $targetCount - $satisfiedCount));
            $dissatisfactionProcessedCount = (int) $channels->sum('dissatisfaction_processed_count');
            $dissatisfiedCount = (int) $channels->sum('dissatisfied_count');
            $percentage = $targetCount > 0
                ? (int) min(100, round(($completedCount / $targetCount) * 100))
                : 0;
            $isSampling = $channels->contains(fn (array $channel): bool => (bool) $channel['is_sampling']);
            $samplingPercentage = $channels->count() === 1
                ? $channels->first()['sampling_percentage']
                : null;
            $detail = $this->aggregateDetail($channels, $completedCount, $targetCount);
        } else {
            $isSampling = Lot::requiresSamplingPercentageFor($lot->type);
            $samplingPercentage = $isSampling && $lot->sampling_percentage !== null
                ? max(0, min(100, (float) $lot->sampling_percentage))
                : null;
            $targetCount = $this->targetCount($totalCount, $isSampling, $samplingPercentage);
            $completedCount = min($placedCount, $targetCount);
            $satisfiedCount = 0;
            $unsatisfiedCount = 0;
            $rawSatisfiedCount = 0;
            $rawUnsatisfiedCount = 0;
            $dissatisfactionProcessedCount = $placedCount;
            $dissatisfiedCount = 0;
            $percentage = $targetCount > 0
                ? (int) min(100, round(($completedCount / $targetCount) * 100))
                : 0;
            $detail = $this->detail($completedCount, $targetCount, $placedCount, $isSampling, $samplingPercentage);
        }

        $satisfactionAnsweredCount = min($satisfiedCount + $unsatisfiedCount, $targetCount);

        $result = [
            'percentage' => $percentage,
            'placed_count' => $placedCount,
            'completed_count' => $completedCount,
            'remaining_count' => max(0, $targetCount - $completedCount),
            'target_count' => $targetCount,
            'total_count' => $totalCount,
            'satisfied_count' => $satisfiedCount,
            'raw_satisfied_count' => $rawSatisfiedCount,
            'unsatisfied_count' => $unsatisfiedCount,
            'raw_unsatisfied_count' => $rawUnsatisfiedCount,
            'satisfaction_answered_count' => $satisfactionAnsweredCount,
            'satisfaction_remaining_count' => max(0, $targetCount - $satisfactionAnsweredCount),
            'satisfaction_percentage' => $targetCount > 0
                ? (int) min(100, round(($satisfactionAnsweredCount / $targetCount) * 100))
                : 0,
            'dissatisfaction' => [
                'percentage' => $dissatisfactionProcessedCount > 0
                    ? min(100, round(($dissatisfiedCount / $dissatisfactionProcessedCount) * 100, 2))
                    : 0,
                'dissatisfied_count' => $dissatisfiedCount,
                'processed_count' => $dissatisfactionProcessedCount,
            ],
            'total_satisfaction' => [
                'percentage' => $totalCount > 0
                    ? min(100, round(($totalSatisfiedCount / $totalCount) * 100, 2))
                    : 0,
                'satisfied_count' => $totalSatisfiedCount,
                'total_count' => $totalCount,
            ],
            'detail' => $detail,
            'is_sampling' => $isSampling,
            'sampling_percentage' => $samplingPercentage,
            'physical' => $physical,
            'contact' => $contact,
        ];

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
     *     remaining_count:int,
     *     target_count:int,
     *     total_count:int,
     *     satisfied_count:int,
     *     raw_satisfied_count:int,
     *     unsatisfied_count:int,
     *     raw_unsatisfied_count:int,
     *     satisfaction_answered_count:int,
     *     satisfaction_remaining_count:int,
     *     satisfaction_percentage:int,
     *     dissatisfaction_processed_count:int,
     *     dissatisfied_count:int,
     *     dissatisfaction_percentage:float,
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
        ?callable $satisfactionValue,
        ?callable $isEligible = null,
    ): array {
        $eligibleAppointments = $isEligible === null
            ? $appointments
            : $appointments->filter($isEligible)->values();
        $totalCount = $appointments->count();
        $safeSamplingPercentage = $isSampling && $samplingPercentage !== null
            ? max(0, min(100, (float) $samplingPercentage))
            : null;
        $targetCount = $this->targetCount($totalCount, $isSampling, $safeSamplingPercentage);
        $processedCount = $eligibleAppointments->filter($isCompleted)->count();
        $completedCount = min($processedCount, $targetCount);
        $satisfiedCount = 0;
        $unsatisfiedCount = 0;

        if ($satisfactionValue !== null) {
            foreach ($eligibleAppointments as $appointment) {
                $satisfaction = $satisfactionValue($appointment);

                if ($satisfaction === true) {
                    $satisfiedCount++;
                }

                if ($satisfaction === false) {
                    $unsatisfiedCount++;
                }
            }
        }

        $rawSatisfiedCount = $satisfiedCount;
        $satisfiedCount = min($satisfiedCount, $targetCount);
        $unsatisfiedCount = min($unsatisfiedCount, max(0, $targetCount - $satisfiedCount));
        $satisfactionAnsweredCount = min($satisfiedCount + $unsatisfiedCount, $targetCount);
        $rawUnsatisfiedCount = $eligibleAppointments
            ->filter(fn (LotAppointment $appointment): bool => $satisfactionValue !== null && $satisfactionValue($appointment) === false)
            ->count();
        $percentage = $targetCount > 0
            ? (int) min(100, round(($completedCount / $targetCount) * 100))
            : 0;

        return [
            'label' => $label,
            'percentage' => $percentage,
            'completed_count' => $completedCount,
            'remaining_count' => max(0, $targetCount - $completedCount),
            'target_count' => $targetCount,
            'total_count' => $totalCount,
            'satisfied_count' => $satisfiedCount,
            'raw_satisfied_count' => $rawSatisfiedCount,
            'unsatisfied_count' => $unsatisfiedCount,
            'raw_unsatisfied_count' => $rawUnsatisfiedCount,
            'satisfaction_answered_count' => $satisfactionAnsweredCount,
            'satisfaction_remaining_count' => max(0, $targetCount - $satisfactionAnsweredCount),
            'satisfaction_percentage' => $targetCount > 0
                ? (int) min(100, round(($satisfactionAnsweredCount / $targetCount) * 100))
                : 0,
            'dissatisfaction_processed_count' => $processedCount,
            'dissatisfied_count' => $rawUnsatisfiedCount,
            'dissatisfaction_percentage' => $processedCount > 0
                ? min(100, round(($rawUnsatisfiedCount / $processedCount) * 100, 2))
                : 0,
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

    /**
     * @param Collection<int, array<string, mixed>> $channels
     */
    private function aggregateDetail(Collection $channels, int $completedCount, int $targetCount): string
    {
        if ($channels->count() === 1) {
            return (string) $channels->first()['detail'];
        }

        return sprintf(
            '%d/%d traitements objectif · %s',
            $completedCount,
            $targetCount,
            $channels
                ->map(fn (array $channel): string => sprintf(
                    '%d/%d %s',
                    (int) $channel['completed_count'],
                    (int) $channel['target_count'],
                    (string) $channel['label'],
                ))
                ->join(' · '),
        );
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
