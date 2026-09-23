<?php

namespace App\Jobs;

use App\Models\LotAppointment;
use App\Services\GlobalPlus\GlobalPlusAppointmentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class AssignGlobalPlusTechnicianJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 180;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly int $lotAppointmentId,
        public readonly string $operationId,
        public readonly int $controllerId,
    ) {}

    public function backoff(): array
    {
        return [15, 30, 60, 120];
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('global-plus-workflow:'.$this->lotAppointmentId))->shared()->releaseAfter(15)->expireAfter(330)];
    }

    public function handle(GlobalPlusAppointmentService $service): void
    {
        $appointment = LotAppointment::find($this->lotAppointmentId);
        if (! $appointment || data_get($appointment->global_plus_payload, 'workflow.id') !== $this->operationId
            || ! filled($appointment->global_plus_demand_id)
            || $appointment->global_plus_status !== GlobalPlusAppointmentService::STATUS_APPOINTMENT_PENDING) {
            return;
        }
        try {
            $service->syncAppointment($appointment, $this->controllerId);
        } catch (Throwable $exception) {
            if (! GlobalPlusAppointmentService::assignmentCanBeRetried($exception)) {
                $this->fail($exception);

                return;
            }
            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        app(GlobalPlusAppointmentService::class)->failWorkflow($this->lotAppointmentId, $this->operationId, $exception);
    }
}
